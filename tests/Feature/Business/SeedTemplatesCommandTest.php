<?php

namespace Tests\Feature\Business;

use App\Models\DesignTemplate;
use Database\Seeders\DesignTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Tests\Support\BusinessFixtures;
use Tests\TestCase;

class SeedTemplatesCommandTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/templates-'.uniqid());
        File::copyDirectory($this->fixtureDir(), $this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function templates(): Collection
    {
        return DesignTemplate::withoutGlobalScopes()->get();
    }

    public function test_it_creates_templates_and_is_idempotent(): void
    {
        $this->artisan('design:seed-templates', ['--path' => $this->dir])
            ->expectsOutputToContain('3 created, 0 updated, 0 unchanged, 0 refused')->assertExitCode(0);

        $this->assertCount(3, $this->templates());
        $sale = $this->fixtureTemplate();
        $this->assertSame('curated', $sale->source);
        $this->assertSame('published', $sale->status);
        $this->assertSame(1, $sale->version);
        $this->assertSame(1080, $sale->width);
        $this->assertNotNull($sale->published_at);
        $this->assertSame(2, $this->fixtureTemplate('fixture-menu-clean-a4')->page_count);

        $sale->update(['uses' => 17]);
        $this->artisan('design:seed-templates', ['--path' => $this->dir])->expectsOutputToContain('0 created, 0 updated, 3 unchanged')->assertExitCode(0);
        $this->assertCount(3, $this->templates());
        $this->assertSame(1, $sale->fresh()->version, 'An unchanged definition does not bump its version');
        $this->assertSame(17, $sale->fresh()->uses);
    }

    public function test_a_changed_definition_updates_in_place_keeps_usage_and_bumps_the_version(): void
    {
        $this->artisan('design:seed-templates', ['--path' => $this->dir])->assertExitCode(0);
        $this->fixtureTemplate()->update(['uses' => 9]);

        $file = $this->dir.'/sale-bold-square.php';
        File::put($file, str_replace("'name' => 'Fixture: bold sale post'", "'name' => 'Fixture: BOLDER sale post'", File::get($file)));

        $this->artisan('design:seed-templates', ['--path' => $this->dir])->expectsOutputToContain('0 created, 1 updated, 2 unchanged')->assertExitCode(0);

        $sale = $this->fixtureTemplate();
        $this->assertSame('Fixture: BOLDER sale post', $sale->name);
        $this->assertSame(2, $sale->version);
        $this->assertSame(9, $sale->uses);
        $this->assertCount(3, $this->templates());
    }

    public function test_an_invalid_template_is_refused_with_a_clear_error_and_the_valid_ones_still_load(): void
    {
        File::put($this->dir.'/broken.php', "<?php\nreturn ['slug' => 'broken-one', 'name' => 'Broken', 'category' => 'social', 'kind' => 'poster', 'format_key' => 'a4-portrait',\n 'pages' => [['elements' => [['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'text' => 'hi', 'fontSize' => 9999, 'bind' => 'nope']]]]];\n");

        $this->artisan('design:seed-templates', ['--path' => $this->dir])
            ->expectsOutputToContain('REFUSED broken.php (broken-one)')
            ->expectsOutputToContain('fontSize must be a number from 4 to 2000')
            ->expectsOutputToContain('[nope] is not a known slot')
            ->expectsOutputToContain('3 created, 0 updated, 0 unchanged, 1 refused')
            ->assertExitCode(1);

        $this->assertNull(DesignTemplate::withoutGlobalScopes()->where('slug', 'broken-one')->first());
        $this->assertCount(3, $this->templates());
    }

    public function test_check_mode_validates_everything_and_writes_nothing(): void
    {
        $this->artisan('design:seed-templates', ['--path' => $this->dir, '--check' => true])
            ->expectsOutputToContain('3 valid, 0 refused. Nothing was written.')->assertExitCode(0);
        $this->assertCount(0, $this->templates());

        File::put($this->dir.'/bad.php', "<?php\nreturn ['slug' => 'x'];\n");
        $this->artisan('design:seed-templates', ['--path' => $this->dir, '--check' => true])
            ->expectsOutputToContain('REFUSED bad.php')->assertExitCode(1);
        $this->assertCount(0, $this->templates());
    }

    public function test_duplicate_slugs_across_files_are_refused(): void
    {
        File::copy($this->dir.'/sale-bold-square.php', $this->dir.'/sale-bold-square-copy.php');

        $this->artisan('design:seed-templates', ['--path' => $this->dir, '--check' => true])
            ->expectsOutputToContain('is also used in sale-bold-square-copy.php')->assertExitCode(1);
    }

    public function test_helper_files_and_unloadable_files_are_handled(): void
    {
        File::put($this->dir.'/_shared.php', "<?php\nthrow new Exception('helpers are never loaded');\n");
        File::put($this->dir.'/Builder.php', "<?php\nthrow new Exception('class files are never loaded');\n");
        File::put($this->dir.'/syntax.php', "<?php\nreturn 42;\n");

        $this->artisan('design:seed-templates', ['--path' => $this->dir, '--check' => true])
            ->expectsOutputToContain('syntax.php')
            ->expectsOutputToContain('must return a template definition')
            ->assertExitCode(1);
    }

    public function test_a_file_may_return_a_list_of_definitions_or_a_closure(): void
    {
        $other = '$d = require __DIR__.\'/menu-clean-a4.php\'; return [array_merge($d, [\'slug\' => \'fixture-menu-two\']), array_merge($d, [\'slug\' => \'fixture-menu-three\'])];';
        File::put($this->dir.'/multi.php', "<?php\nreturn function () { $other };\n");

        $this->artisan('design:seed-templates', ['--path' => $this->dir])->expectsOutputToContain('5 created')->assertExitCode(0);
        $this->assertNotNull(DesignTemplate::withoutGlobalScopes()->where('slug', 'fixture-menu-three')->first());
    }

    public function test_a_slug_that_belongs_to_a_saved_template_is_not_overwritten(): void
    {
        $owner = $this->workspaceUser();
        DesignTemplate::create(['slug' => 'fixture-sale-bold-square', 'name' => 'Mine', 'source' => 'user', 'user_id' => $owner->id, 'category' => 'social', 'kind' => 'poster', 'width' => 1, 'height' => 1, 'pages' => [['elements' => []]], 'status' => 'published']);

        $this->artisan('design:seed-templates', ['--path' => $this->dir])
            ->expectsOutputToContain('Skipped [fixture-sale-bold-square]')->assertExitCode(1);

        $this->assertSame('Mine', DesignTemplate::withoutGlobalScopes()->where('slug', 'fixture-sale-bold-square')->value('name'));
    }

    public function test_retire_missing_retires_curated_templates_whose_file_is_gone(): void
    {
        $this->artisan('design:seed-templates', ['--path' => $this->dir])->assertExitCode(0);
        File::delete($this->dir.'/event-dark-story.php');

        $this->artisan('design:seed-templates', ['--path' => $this->dir, '--retire-missing' => true])
            ->expectsOutputToContain('1 curated template(s) retired')->assertExitCode(0);

        $this->assertSame('retired', $this->fixtureTemplate('fixture-event-dark-story')->status);
        $this->assertSame('published', $this->fixtureTemplate()->status);
    }

    public function test_the_default_directory_with_no_files_is_a_clean_no_op_and_the_seeder_hook_runs(): void
    {
        $this->artisan('design:seed-templates')->assertExitCode(0);
        $this->seed(DesignTemplateSeeder::class);

        $this->assertSame(count(glob(database_path('templates/[a-z0-9]*.php')) ?: []) > 0, $this->templates()->isNotEmpty());
    }
}
