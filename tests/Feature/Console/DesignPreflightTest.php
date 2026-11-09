<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DesignPreflight;
use App\Models\DesignTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * design:preflight runs in deploy.yml after the migrations. These tests pin what it must fail on, what it must only
 * warn about, and that an optional integration that is switched off is never an error.
 */
class DesignPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDir = sys_get_temp_dir().'/preflight-public-'.bin2hex(random_bytes(4));
        $this->buildPublicDir($this->publicDir);
        $this->app->usePublicPath($this->publicDir);

        // A host that is fully set up: production, https, queue and caches as the deploy leaves them.
        config([
            'app.url' => 'https://design.infodot.co.za',
            'app.debug' => false,
            'queue.default' => 'database',
        ]);

        Artisan::call('design:seed-templates');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);

        parent::tearDown();
    }

    /** A public/ folder with a manifest for every @vite entry, every configured font, and every interface font the CSS names. */
    private function buildPublicDir(string $dir): void
    {
        File::ensureDirectoryExists($dir.'/build');

        $manifest = [];

        foreach (DesignPreflight::viteEntriesInViews() as $entry) {
            $manifest[$entry] = ['file' => 'assets/'.basename($entry), 'isEntry' => true];
        }

        File::put($dir.'/build/manifest.json', (string) json_encode($manifest));

        foreach (config('design_fonts.fonts') as $font) {
            foreach ($font['files'] as $path) {
                File::ensureDirectoryExists(dirname($dir.'/'.$path));
                File::put($dir.'/'.$path, 'x');
            }
        }

        foreach (File::allFiles(resource_path('css')) as $css) {
            if (preg_match_all('#/fonts/ui/[A-Za-z0-9._-]+\.woff2#', File::get($css->getPathname()), $m)) {
                foreach ($m[0] as $ref) {
                    File::ensureDirectoryExists(dirname($dir.$ref));
                    File::put($dir.$ref, 'x');
                }
            }
        }
    }

    private function statusOf(string $output, string $check): ?string
    {
        foreach (preg_split('/\R/', $output) as $line) {
            if (preg_match('/^\s*(PASS|INFO|OFF|WARN|FAIL)\s+'.preg_quote($check, '/').'\s/', $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function run_(array $options = []): array
    {
        $code = Artisan::call('design:preflight', $options);

        return [$code, Artisan::output()];
    }

    public function test_a_ready_host_passes_and_optional_integrations_are_off_not_errors(): void
    {
        [$code, $out] = $this->run_();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Preflight OK', $out);

        foreach (['Dot.Notify', 'Dot.Files', 'InfoDot membership webhook', 'Dot.Analytics snapshot', 'Assistant (AI)', 'AI image generation'] as $name) {
            $this->assertSame('OFF', $this->statusOf($out, $name), "$name should read OFF\n$out");
        }

        $this->assertSame('PASS', $this->statusOf($out, 'Templates seeded'), $out);
        $this->assertSame('PASS', $this->statusOf($out, 'Built assets'), $out);
        $this->assertSame('PASS', $this->statusOf($out, 'Editor fonts'), $out);
    }

    public function test_the_assistant_reads_on_once_a_key_is_set(): void
    {
        config(['design_ai.api_key' => 'sk-test-not-real']);

        [, $out] = $this->run_();

        $this->assertSame('PASS', $this->statusOf($out, 'Assistant (AI)'), $out);
        $this->assertStringNotContainsString('sk-test-not-real', $out);
    }

    public function test_an_unseeded_template_table_fails(): void
    {
        DesignTemplate::withoutGlobalScopes()->delete();

        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $this->statusOf($out, 'Templates seeded'), $out);
        $this->assertStringContainsString('design:seed-templates', $out);
    }

    public function test_a_missing_build_manifest_fails_and_a_missing_entry_is_named(): void
    {
        $manifest = json_decode(File::get($this->publicDir.'/build/manifest.json'), true);
        unset($manifest['resources/js/studio/main.js']);
        File::put($this->publicDir.'/build/manifest.json', json_encode($manifest));

        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('resources/js/studio/main.js', $out);

        File::delete($this->publicDir.'/build/manifest.json');
        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $this->statusOf($out, 'Built assets'), $out);
    }

    public function test_a_missing_font_file_fails(): void
    {
        File::delete($this->publicDir.'/fonts/design/inter/inter-400.woff2');

        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $this->statusOf($out, 'Editor fonts'), $out);
    }

    public function test_an_enabled_integration_without_its_secret_fails_and_names_the_variable(): void
    {
        config([
            'design_integrations.notify.enabled' => true,
            'design_integrations.notify.url' => 'https://notify.infodot.co.za/api/ingest/events',
            'design_integrations.notify.secret' => null,
        ]);

        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $this->statusOf($out, 'Dot.Notify'), $out);
        $this->assertStringContainsString('NOTIFY_INGEST_SECRET', $out);
    }

    public function test_a_complete_integration_reads_on_and_never_prints_the_secret(): void
    {
        config([
            'design_integrations.files.enabled' => true,
            'design_integrations.files.url' => 'https://files.infodot.co.za',
            'design_integrations.files.secret' => 'super-secret-value',
        ]);

        [$code, $out] = $this->run_();

        $this->assertSame(0, $code, $out);
        $this->assertSame('PASS', $this->statusOf($out, 'Dot.Files'), $out);
        $this->assertStringNotContainsString('super-secret-value', $out);
    }

    public function test_production_with_debug_on_fails(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production', 'app.debug' => true]);

        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $this->statusOf($out, 'APP_DEBUG'), $out);
    }

    public function test_a_web_reachable_asset_disk_fails(): void
    {
        config(['filesystems.disks.design_assets.root' => $this->publicDir.'/uploads']);

        [$code, $out] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $this->statusOf($out, 'Asset disk'), $out);
    }

    public function test_strict_turns_a_warning_into_a_failure_but_the_default_does_not(): void
    {
        config(['queue.default' => 'sync']);
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        [$plain, $out] = $this->run_();
        $this->assertSame('WARN', $this->statusOf($out, 'Queue driver'), $out);
        $this->assertSame(0, $plain, $out);

        [$strict] = $this->run_(['--strict' => true]);
        $this->assertSame(1, $strict);
    }

    public function test_json_output_has_counts_and_results(): void
    {
        [$code, $out] = $this->run_(['--json' => true]);
        $data = json_decode($out, true);

        $this->assertSame(0, $code, $out);
        $this->assertTrue($data['ok']);
        $this->assertSame(0, $data['counts']['FAIL']);
        $this->assertNotEmpty($data['results']);
        $this->assertSame(['status', 'area', 'check', 'detail'], array_keys($data['results'][0]));
    }

    public function test_limit_judgement(): void
    {
        $status = fn (array $rows, string $check) => collect($rows)->firstWhere('check', $check)['status'];

        $good = DesignPreflight::evaluateLimits(['upload_max_filesize' => '10M', 'post_max_size' => '12M', 'memory_limit' => '512M', 'max_input_vars' => '3000', 'max_execution_time' => '60']);
        $this->assertSame(['PASS'], array_values(array_unique(array_column($good, 'status'))));

        $tiny = DesignPreflight::evaluateLimits(['upload_max_filesize' => '1M', 'post_max_size' => '1M', 'memory_limit' => '32M', 'max_input_vars' => '200', 'max_execution_time' => '10']);
        $this->assertSame('FAIL', $status($tiny, 'post_max_size'));
        $this->assertSame('FAIL', $status($tiny, 'memory_limit'));
        $this->assertSame('WARN', $status($tiny, 'upload_max_filesize'));
        $this->assertSame('WARN', $status($tiny, 'max_input_vars'));
        $this->assertSame('WARN', $status($tiny, 'max_execution_time'));

        $default = DesignPreflight::evaluateLimits(['upload_max_filesize' => '2M', 'post_max_size' => '8M', 'memory_limit' => '128M', 'max_input_vars' => '1000', 'max_execution_time' => '30']);
        $this->assertSame('WARN', $status($default, 'post_max_size'));
        $this->assertSame('WARN', $status($default, 'memory_limit'));
        $this->assertStringContainsString('megapixels', collect($default)->firstWhere('check', 'memory_limit')['detail']);

        $unlimited = DesignPreflight::evaluateLimits(['upload_max_filesize' => '10M', 'post_max_size' => '0', 'memory_limit' => '-1', 'max_input_vars' => '1000', 'max_execution_time' => '0']);
        $this->assertSame('PASS', $status($unlimited, 'post_max_size'));
        $this->assertSame('INFO', $status($unlimited, 'memory_limit'));
    }

    public function test_shorthand_sizes_parse(): void
    {
        $this->assertSame(8 * 1024 * 1024, DesignPreflight::bytes('8M'));
        $this->assertSame(1024 ** 3, DesignPreflight::bytes('1G'));
        $this->assertSame(512 * 1024, DesignPreflight::bytes('512K'));
        $this->assertNull(DesignPreflight::bytes('-1'));
    }

    public function test_vite_entries_are_read_from_literal_directives_only(): void
    {
        $dir = sys_get_temp_dir().'/preflight-views-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/a.blade.php', "<head>@vite(['resources/css/a.css', \"resources/js/a.js\"])</head>");
        File::put($dir.'/b.blade.php', '<head>@vite($dynamic)</head>{{-- resources/js/in-a-comment.js --}}');
        File::put($dir.'/c.txt', "@vite(['resources/js/not-a-view.js'])");

        $this->assertSame(['resources/css/a.css', 'resources/js/a.js'], DesignPreflight::viteEntriesInViews($dir));

        File::deleteDirectory($dir);
    }

    public function test_the_command_never_calls_env_so_it_works_with_a_cached_config(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\benv\(/', File::get(app_path('Console/Commands/DesignPreflight.php')));
    }
}
