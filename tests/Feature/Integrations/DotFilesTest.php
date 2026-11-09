<?php

namespace Tests\Feature\Integrations;

use App\Models\DesignFileLink;
use App\Models\User;
use App\Services\Integrations\Contracts\FileVault;
use App\Services\Integrations\IntegrationRefused;
use App\Services\Integrations\IntegrationState;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * The calls mirror Dot.Files' real platform file API (Dot.Files PlatformFileController + PlatformRequest, read there): signed POSTs
 * under /api/platform/files, scoped by the signed team_uuid. Http::fake stands in for the network only.
 */
class DotFilesTest extends IntegrationTestCase
{
    private const BASE = 'https://files.test';

    private const SECRET = 'files-secret-for-tests-0001';

    private const FILE = '9c1d0e2a-7b3f-4a55-8e61-2d4f6a8b0c13';

    /** A 1x1 PNG. */
    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['design_integrations.files' => [
            'enabled' => true, 'url' => self::BASE, 'secret' => self::SECRET, 'platform' => 'design', 'folder' => 'Dot.Design',
            'timeout' => 5, 'formats' => ['png', 'jpg', 'pdf', 'svg', 'gif'], 'max_bytes' => 25 * 1024 * 1024,
        ]]);
        config(['design_integrations.breaker' => ['threshold' => 3, 'cooldown' => 300]]);
    }

    private function respond(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function upload(string $bytes, string $name = 'export.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function save(User $user, $project, array $extra = [], ?string $bytes = null, string $format = 'png')
    {
        return $this->actingAs($user)->post(route('design.integrations.files.store', $project), $extra + [
            'file' => $this->upload($bytes ?? $this->png(), 'export.'.$format),
            'format' => $format,
            'scope' => 'page-1',
            'acknowledge' => true,
        ], ['Accept' => 'application/json']);
    }

    public function test_the_first_save_files_a_signed_document_for_the_company_and_remembers_it(): void
    {
        $this->respond([self::BASE.'/api/platform/files/store' => Http::response(['uuid' => self::FILE, 'name' => 'spring-sale-poster-page-1.png', 'open_url' => 'https://files.test/o/'.self::FILE], 201)]);
        [$owner] = $this->workspace();
        $project = $this->project($owner);

        $this->save($owner, $project)->assertCreated()->assertJsonPath('status', 'filed')->assertJsonPath('open_url', 'https://files.test/o/'.self::FILE);

        Http::assertSent(function (Request $r) {
            $ts = (int) $r->header('X-Dot-Timestamp')[0];
            $this->assertSame('design', $r->header('X-Dot-Platform')[0]);
            $this->assertEqualsWithDelta(time(), $ts, 5);
            $this->assertSame('sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), self::SECRET), $r->header('X-Dot-Signature')[0]);

            $body = json_decode($r->body(), true);
            // Files' store rules (PlatformFileController::store + the team_uuid rule added by open()).
            $v = Validator::make($body, ['team_uuid' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'content_b64' => ['required', 'string'], 'folder' => ['nullable', 'string', 'max:255']]);
            $this->assertFalse($v->fails(), (string) $v->errors());
            $this->assertSame(self::COMPANY, $body['team_uuid']);
            $this->assertSame('spring-sale-poster-page-1.png', $body['name']);
            $this->assertContains(pathinfo($body['name'], PATHINFO_EXTENSION), ['pdf', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'zip']);
            $this->assertSame($this->png(), base64_decode($body['content_b64'], true));
            $this->assertSame('Dot.Design', $body['folder']);

            return true;
        });

        $link = DesignFileLink::first();
        $this->assertSame(self::FILE, $link->files_uuid);
        $this->assertSame('page-1', $link->scope);
    }

    public function test_saving_the_same_export_again_adds_a_new_version_instead_of_a_second_file(): void
    {
        $this->respond([
            self::BASE.'/api/platform/files/store' => Http::response(['uuid' => self::FILE, 'open_url' => 'https://files.test/o/'.self::FILE], 201),
            self::BASE.'/api/platform/files/update' => Http::response(['uuid' => self::FILE, 'version' => 2, 'checksum' => 'abc', 'open_url' => 'https://files.test/o/'.self::FILE], 200),
        ]);
        [$owner] = $this->workspace();
        $project = $this->project($owner);

        $this->save($owner, $project)->assertCreated();
        $this->save($owner, $project)->assertCreated()->assertJsonPath('version', 2);

        $this->assertSame(1, DesignFileLink::count());
        $this->assertSame(2, DesignFileLink::first()->version);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/update') && json_decode($r->body(), true)['uuid'] === self::FILE);

        // The third save carries the version the second one returned, so Files can refuse a lost update.
        $this->respond([self::BASE.'/api/platform/files/update' => Http::response(['uuid' => self::FILE, 'version' => 3], 200)]);
        $this->save($owner, $project)->assertCreated();
        Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['base_version'] === 2);
    }

    public function test_a_version_conflict_is_reported_in_plain_words_and_nothing_is_overwritten(): void
    {
        [$owner, $team] = $this->workspace();
        $project = $this->project($owner);
        DesignFileLink::create(['design_project_id' => $project->id, 'team_id' => $team->id, 'scope' => 'page-1', 'format' => 'png', 'files_uuid' => self::FILE, 'version' => 2, 'filed_at' => now()]);
        $this->respond([self::BASE.'/api/platform/files/update' => Http::response(['error' => 'version_conflict', 'current_version' => 3], 409)]);

        $this->save($owner, $project)->assertStatus(409)->assertJsonPath('error', 'Someone saved a newer version of this file in Dot.Files. Open it there before saving over it.');

        $this->assertSame(2, DesignFileLink::first()->version);
    }

    public function test_a_document_removed_in_dot_files_is_filed_again_as_a_new_one(): void
    {
        [$owner, $team] = $this->workspace();
        $project = $this->project($owner);
        DesignFileLink::create(['design_project_id' => $project->id, 'team_id' => $team->id, 'scope' => 'page-1', 'format' => 'png', 'files_uuid' => 'old', 'version' => 1, 'filed_at' => now()]);
        $this->respond([
            self::BASE.'/api/platform/files/update' => Http::response(['error' => 'not_found'], 404),
            self::BASE.'/api/platform/files/store' => Http::response(['uuid' => self::FILE], 201),
        ]);

        $this->save($owner, $project)->assertCreated();

        $this->assertSame(self::FILE, DesignFileLink::first()->files_uuid);
    }

    public function test_it_is_a_404_with_the_integration_off_so_no_control_can_exist_for_it(): void
    {
        Http::fake();
        config(['design_integrations.files.enabled' => false]);
        [$owner] = $this->workspace();

        $this->save($owner, $this->project($owner))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_somebody_elses_design_is_a_404_and_nothing_is_sent(): void
    {
        Http::fake();
        [$owner] = $this->workspace();
        $project = $this->project($owner);
        $stranger = User::factory()->create();

        $this->save($stranger, $project)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_person_must_acknowledge_that_the_whole_business_will_see_the_file(): void
    {
        Http::fake();
        [$owner] = $this->workspace();

        $this->save($owner, $this->project($owner), ['acknowledge' => false])->assertUnprocessable()->assertJsonValidationErrors('acknowledge');

        Http::assertNothingSent();
    }

    public function test_bytes_must_really_be_the_format_claimed_and_an_svg_may_not_carry_script(): void
    {
        Http::fake();
        [$owner] = $this->workspace();
        $project = $this->project($owner);

        $this->save($owner, $project, [], 'not a png at all')->assertStatus(422)->assertJsonPath('error', 'That file is not a valid PNG.');
        $this->save($owner, $project, [], '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'svg')->assertStatus(422);
        $this->save($owner, $project, ['format' => 'exe'])->assertUnprocessable()->assertJsonValidationErrors('format');

        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_without_an_infodot_company_cannot_use_dot_files(): void
    {
        Http::fake();
        $user = User::factory()->withPersonalTeam()->create();
        $user->forceFill(['current_team_id' => $user->ownedTeams()->first()->id])->save();

        $this->save($user, $this->project($user))->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_wrong_secret_is_explained_and_a_working_call_afterwards_earns_connected(): void
    {
        [$owner] = $this->workspace();
        $project = $this->project($owner);
        $this->respond([self::BASE.'/api/platform/files/store' => Http::response(['error' => 'invalid_request'], 401)]);

        $this->save($owner, $project)->assertStatus(422);
        $state = app(FileVault::class)->status();
        $this->assertSame(IntegrationState::UNAVAILABLE, $state->status);
        $this->assertStringContainsString('signature', $state->reason);

        $this->respond([self::BASE.'/api/platform/files/store' => Http::response(['uuid' => self::FILE], 201)]);
        $this->save($owner, $project)->assertCreated();
        $this->assertSame(IntegrationState::CONNECTED, app(FileVault::class)->status()->status);
    }

    public function test_repeated_failures_pause_calls_to_dot_files(): void
    {
        [$owner, $team] = $this->workspace();
        $this->respond([self::BASE.'/*' => Http::response([], 503)]);
        $vault = app(FileVault::class);

        for ($i = 0; $i < 3; $i++) {
            try {
                $vault->store($team, 'a.png', $this->png());
            } catch (IntegrationRefused) {
            }
        }

        $this->expectException(IntegrationRefused::class);
        try {
            $vault->store($team, 'a.png', $this->png());
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_the_secret_is_never_part_of_what_is_sent_or_stored(): void
    {
        $this->respond([self::BASE.'/api/platform/files/store' => Http::response(['uuid' => self::FILE], 201)]);
        [$owner] = $this->workspace();
        $this->save($owner, $this->project($owner))->assertCreated();

        Http::assertSent(fn (Request $r) => ! str_contains($r->body(), self::SECRET));
        $this->assertStringNotContainsString(self::SECRET, json_encode(DesignFileLink::first()->toArray()));
    }
}
