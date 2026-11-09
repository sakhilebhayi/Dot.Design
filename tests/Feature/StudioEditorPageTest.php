<?php

namespace Tests\Feature;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\Assistant\AssistantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ReadsEditorBoot;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * The editor workspace page: one JSON boot block, no inline script, no external hosts, the right permissions for
 * each role, and a 404 for a design the viewer cannot see.
 */
class StudioEditorPageTest extends TestCase
{
    use ReadsEditorBoot;
    use RefreshDatabase;
    use StudioWorld;

    private function ownerWithProject(array $elements = []): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = DesignProject::create(['user_id' => $user->id, 'name' => 'Spring flyer', 'type' => 'graphic', 'width' => 1080, 'height' => 1080, 'unit' => 'px']);
        $project->canvases()->create(['page_number' => 1, 'elements' => $elements]);

        return [$user, $project];
    }

    private function open(User $user, DesignProject $project)
    {
        return $this->actingAs($user)->get(route('design.projects.show', $project));
    }

    public function test_the_page_boots_the_studio_from_one_json_block(): void
    {
        [$user, $project] = $this->ownerWithProject([['id' => 'a1', 'type' => 'text', 'x' => 10, 'y' => 10, 'width' => 200, 'height' => 40, 'text' => 'Hello', 'fontSize' => 24]]);

        $html = $this->open($user, $project)->assertOk()->getContent();
        $boot = $this->editorBoot($html);

        $this->assertStringContainsString('id="studio-app"', $html);
        $this->assertSame($project->id, $boot['project']['id']);
        $this->assertSame('Spring flyer', $boot['project']['name']);
        $this->assertSame(1080, $boot['project']['width']);
        $this->assertCount(1, $boot['pages']);
        $this->assertSame('text', $boot['pages'][0]['elements'][0]['type'], 'the document is the same shape the document endpoint returns');
        $this->assertSame(1, $boot['pages'][0]['revision']);
        $this->assertSame('/design/projects/'.$project->id.'/pages/{page}', $boot['urls']['design.projects.pages.update']);
        $this->assertSame('/design/projects/'.$project->id.'/thumbnail', $boot['urls']['design.projects.thumbnail.update']);
        $this->assertSame('/design/settings', $boot['urls']['design.settings.update']);
        $this->assertSame('/design/templates/{template}/fill', $boot['urls']['design.templates.fill']);
        $this->assertSame('/login', $boot['urls']['login']);
    }

    public function test_the_boot_block_carries_everything_the_panels_draw_from(): void
    {
        [$user, $project] = $this->ownerWithProject();

        $boot = $this->editorBoot($this->open($user, $project)->assertOk()->getContent());

        $fontKeys = array_column($boot['fonts'], 'key');
        foreach (['inter', 'playfair-display', 'bebas-neue', 'jetbrains-mono'] as $key) {
            $this->assertContains($key, $fontKeys);
        }
        $inter = collect($boot['fonts'])->firstWhere('key', 'inter');
        $this->assertStringStartsWith('http', $inter['files']['400'], 'font files are turned into asset URLs');
        $this->assertStringContainsString('/fonts/design/inter/', $inter['files']['400']);
        $this->assertGreaterThan(100, count($boot['icons']));
        $this->assertArrayNotHasKey('$comment', $boot['icons']);
        $this->assertArrayHasKey('circle-check', $boot['uiIcons']);
        $this->assertGreaterThan(30, count($boot['formats']));
        $this->assertArrayHasKey('px', $boot['formats'][0]);
        $this->assertSame('simple', $boot['settings']['mode']);
        $this->assertArrayHasKey('ai', $boot['flags']);
        $this->assertArrayHasKey('filesVault', $boot['flags']);
        $this->assertArrayHasKey('notify', $boot['flags']);
        $this->assertSame($user->id, $boot['user']['id']);
        $this->assertSame(300, $boot['limits']['max_elements_per_page']);
        $this->assertNull($boot['brand'], 'no kit exists yet');
    }

    public function test_the_page_has_exactly_one_heading_naming_the_design_outside_the_editor_mount(): void
    {
        [$user, $project] = $this->ownerWithProject();

        $html = $this->open($user, $project)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertMatchesRegularExpression('/<h1 class="lt-sr">Spring flyer<\/h1>\s*<div id="studio-app"/', $html);
    }

    public function test_the_assistant_flag_in_the_boot_json_is_exactly_what_the_assistant_status_says(): void
    {
        [$user, $project] = $this->ownerWithProject();

        foreach ([['', true], ['sk-test-not-real', true], ['sk-test-not-real', false], ['', false]] as [$key, $enabled]) {
            config(['design_ai.api_key' => $key, 'design_ai.enabled' => $enabled, 'services.anthropic.api_key' => $key]);

            $boot = $this->editorBoot($this->open($user, $project)->assertOk()->getContent());

            $this->assertSame(AssistantStatus::enabled(), $boot['flags']['ai'], "key '{$key}', enabled ".var_export($enabled, true));
            $this->assertSame($key !== '' && $enabled, $boot['flags']['ai']);
        }
    }

    public function test_the_workspace_default_brand_kit_is_included_and_a_linked_kit_wins(): void
    {
        [$user, $project] = $this->ownerWithProject();
        $team = $user->currentTeam;
        $default = BrandKit::create(['user_id' => $user->id, 'team_id' => $team->id, 'name' => 'Default kit', 'is_default' => true, 'version' => 3, 'colors' => [['key' => 'primary', 'name' => 'Main', 'hex' => '#0f3d5e', 'role' => 'primary']], 'fonts' => ['heading' => 'oswald', 'body' => 'inter', 'accent' => 'inter']]);

        $boot = $this->editorBoot($this->open($user, $project)->assertOk()->getContent());
        $this->assertSame($default->id, $boot['brand']['id']);
        $this->assertSame('#0f3d5e', $boot['brand']['colors'][0]['hex']);
        $this->assertFalse($boot['brand']['linked']);

        $other = BrandKit::create(['user_id' => $user->id, 'team_id' => $team->id, 'name' => 'Linked kit', 'is_default' => false, 'version' => 1, 'colors' => [], 'fonts' => ['heading' => 'inter', 'body' => 'inter', 'accent' => 'inter']]);
        \DB::table('design_projects')->where('id', $project->id)->update(['brand_kit_id' => $other->id, 'brand_version' => 1]);

        $boot = $this->editorBoot($this->open($user, $project)->assertOk()->getContent());
        $this->assertSame($other->id, $boot['brand']['id']);
        $this->assertTrue($boot['brand']['linked']);
        $this->assertSame($other->id, $boot['project']['brand_kit_id']);
    }

    public function test_an_owner_can_edit_and_a_reviewer_gets_a_read_only_flag(): void
    {
        $w = $this->workspace();
        $project = $this->project($w['owner'], $w['team'], 'team');

        $owner = $this->editorBoot($this->open($w['owner'], $project)->assertOk()->getContent());
        $this->assertTrue($owner['permissions']['can']['edit']);
        $this->assertTrue($owner['permissions']['can']['export']);

        $editor = $this->editorBoot($this->open($w['editor'], $project)->assertOk()->getContent());
        $this->assertTrue($editor['permissions']['can']['edit']);

        $viewer = $this->editorBoot($this->open($w['viewer'], $project)->assertOk()->getContent());
        $this->assertFalse($viewer['permissions']['can']['edit'], 'a viewer role has no edit capability, so the workspace is read-only');
        $this->assertTrue($viewer['permissions']['can']['comment']);
        $this->assertTrue($viewer['permissions']['can']['approve']);
        $this->assertFalse($viewer['permissions']['can']['export']);
    }

    public function test_a_design_the_person_cannot_see_is_a_404_like_a_missing_one(): void
    {
        [$owner, $project] = $this->ownerWithProject();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->open($stranger, $project)->assertNotFound();
        $this->actingAs($stranger)->get('/design/projects/999999')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get(route('design.projects.show', $project))->assertRedirect(route('login'));
    }

    public function test_a_teammate_in_the_same_workspace_cannot_open_a_private_design(): void
    {
        $w = $this->workspace();
        $private = $this->project($w['owner'], $w['team'], 'private');

        $this->open($w['admin'], $private)->assertNotFound();
        $this->open($w['owner'], $private)->assertOk();
    }

    public function test_the_page_has_no_inline_script_no_inline_handlers_and_no_external_hosts(): void
    {
        [$user, $project] = $this->ownerWithProject();

        $html = $this->open($user, $project)->assertOk()->getContent();

        preg_match_all('#<script\b([^>]*)>#i', $html, $scripts);
        foreach ($scripts[1] as $attrs) {
            $this->assertTrue(str_contains($attrs, 'src=') || str_contains($attrs, 'type="application/json"'), 'every script is external or a JSON data block: '.$attrs);
        }
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*"/i', $html, 'no inline event handlers');
        $this->assertStringNotContainsString('eval(', $html);

        preg_match_all('#(?:src|href)="(https?://[^"]+)"#i', $html, $urls);
        $app = parse_url(config('app.url'), PHP_URL_HOST);
        foreach ($urls[1] as $url) {
            $this->assertContains(parse_url($url, PHP_URL_HOST), [$app, '127.0.0.1', 'localhost', 'localhost:5173', '[::1]', 'dot-design.test'], 'only own hosts: '.$url);
        }
        foreach (['cdn.', 'googleapis', 'gstatic', 'unpkg', 'jsdelivr', 'cloudflare'] as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
        $this->assertStringContainsString('id="studio-app"', $html);
        $this->assertStringContainsString('class="lt-skip"', $html, 'a skip link');
    }

    public function test_hostile_text_in_a_design_cannot_close_the_data_block(): void
    {
        [$user, $project] = $this->ownerWithProject([['id' => 'x', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 40, 'text' => '</script><script>alert(1)</script><!--', 'fontSize' => 20]]);
        $project->update(['name' => '</script><b>x</b>']);

        $html = $this->open($user, $project)->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('#<script type="application/json" id="studio-boot">#', $html));
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $boot = $this->editorBoot($html);
        $this->assertSame('</script><b>x</b>', $boot['project']['name']);
        $this->assertSame('</script><script>alert(1)</script><!--', $boot['pages'][0]['elements'][0]['text']);
    }

    public function test_the_editor_gets_pictures_it_may_draw_and_a_library_of_elements(): void
    {
        [$user, $project] = $this->ownerWithProject();
        $asset = DesignAsset::create(['user_id' => $user->id, 'name' => 'logo.png', 'type' => 'image', 'file_path' => 'x/logo.png', 'disk' => 'design_assets', 'mime_type' => 'image/png', 'file_size' => 10, 'width' => 40, 'height' => 20]);

        $boot = $this->editorBoot($this->open($user, $project)->assertOk()->getContent());
        $this->assertArrayHasKey($asset->id, $boot['assets']);
        $this->assertSame(40, $boot['assets'][$asset->id]['width']);

        $library = $this->actingAs($user)->getJson(route('design.studio.elements'))->assertOk();
        $data = json_decode(file_get_contents($library->baseResponse->getFile()->getPathname()), true);
        foreach (['badges', 'frames', 'dividers', 'patterns', 'gradients', 'palettes'] as $group) {
            $this->assertNotEmpty($data[$group], $group);
        }
    }

    public function test_the_elements_library_needs_a_signed_in_person(): void
    {
        $this->getJson(route('design.studio.elements'))->assertUnauthorized();
    }

    public function test_the_old_canvas_route_still_works_for_api_compatibility(): void
    {
        [$user, $project] = $this->ownerWithProject();
        $canvas = $project->canvases()->first();

        $this->actingAs($user)->putJson(route('design.projects.canvases.update', [$project, $canvas]), ['revision' => $canvas->revision, 'elements' => [], 'background_color' => '#ffffff'])->assertOk();
    }
}
