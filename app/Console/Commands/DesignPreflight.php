<?php

namespace App\Console\Commands;

use App\Models\DesignTemplate;
use App\Models\TokenSet;
use App\Models\User;
use App\Services\Design\TemplateImporter;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks that this host and this .env can run Dot.Design, and says plainly what is wrong.
 *
 * Statuses: PASS (fine), INFO (a fact worth knowing, not a problem), OFF (an optional feature that is switched off,
 * never an error), WARN (works, but something will hurt), FAIL (the product is broken or unsafe).
 * Exit code 1 when anything FAILS (with --strict, when anything WARNS too), otherwise 0.
 *
 * deploy.yml runs it on the host after the migrations and the caches, before the site comes out of maintenance
 * mode. The limits it reads are those of the PHP binary that runs this command (the CLI); the web server's PHP can
 * differ, so the deploy smoke check also proves the request-size limit through the real web stack.
 */
class DesignPreflight extends Command
{
    public const PASS = 'PASS';

    public const INFO = 'INFO';

    public const OFF = 'OFF';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** Smallest PHP the product runs on (composer.json). */
    private const MIN_PHP_ID = 80300;

    /** Largest document the editor autosaves (resources/design/document-schema.json), with room for the request. */
    private const MIN_POST_BYTES = 2 * 1024 * 1024;

    private const RECOMMENDED_MEMORY_BYTES = 256 * 1024 * 1024;

    private const MIN_MEMORY_BYTES = 64 * 1024 * 1024;

    protected $signature = 'design:preflight
        {--json : Print the results as JSON}
        {--strict : Exit 1 on warnings as well as failures}';

    protected $description = 'Check PHP, limits, folders, configuration, data and optional integrations; exit 1 if anything required is wrong';

    /** @var list<array{status: string, area: string, check: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->results = [];

        $this->php();
        $this->limits();
        $this->application();
        $this->database();
        $this->storage();
        $this->frontend();
        $this->data();
        $this->queueAndSchedule();
        $this->integrations();

        return $this->report();
    }

    // ------------------------------------------------------------------ PHP

    private function php(): void
    {
        if (PHP_VERSION_ID >= self::MIN_PHP_ID) {
            $this->add(self::PASS, 'PHP', 'Version', PHP_VERSION.' (8.3 or newer is required)');
        } else {
            $this->add(self::FAIL, 'PHP', 'Version', PHP_VERSION.' is too old; composer.json needs PHP 8.3 or newer.');
        }

        $driver = (string) config('database.default');
        $dbExtension = match ($driver) {
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'mysql', 'mariadb' => 'pdo_mysql',
            default => null,
        };

        $required = ['mbstring', 'intl', 'bcmath', 'curl', 'fileinfo', 'gd', 'openssl', 'ctype', 'json', 'pdo'];

        if ($dbExtension !== null) {
            $required[] = $dbExtension;
        }

        $missing = array_values(array_filter($required, fn (string $e) => ! extension_loaded($e)));

        if ($missing === []) {
            $this->add(self::PASS, 'PHP', 'Extensions', implode(', ', $required));
        } else {
            $this->add(self::FAIL, 'PHP', 'Extensions', 'Missing: '.implode(', ', $missing).'. Enable them for this PHP version in cPanel (Select PHP Version, Extensions).');
        }

        if (extension_loaded('gd')) {
            $info = gd_info();
            $lacking = [];

            foreach (['JPEG Support' => 'JPEG', 'PNG Support' => 'PNG', 'WebP Support' => 'WebP'] as $key => $label) {
                if (empty($info[$key])) {
                    $lacking[] = $label;
                }
            }

            if ($lacking === []) {
                $this->add(self::PASS, 'PHP', 'GD formats', 'JPEG, PNG and WebP are supported, so uploads are re-encoded and stripped of metadata.');
            } else {
                $this->add(self::FAIL, 'PHP', 'GD formats', 'GD lacks '.implode(', ', $lacking).' support; uploaded images would be stored as sent, with their metadata.');
            }
        }

        $opcache = extension_loaded('Zend OPcache');
        $this->add($opcache ? self::PASS : self::WARN, 'PHP', 'OPcache', $opcache ? 'loaded' : 'not loaded; every request recompiles the code. Enable it in cPanel.');
    }

    private function limits(): void
    {
        $this->addAll(self::evaluateLimits([
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_input_vars' => (string) ini_get('max_input_vars'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
        ], (int) config('design.assets.max_kilobytes', 8192) * 1024, (int) config('design.assets.max_megapixels', 40)));

        $this->add(self::INFO, 'PHP limits', 'Which PHP', 'These numbers belong to the PHP binary running this command ('.PHP_SAPI.'). The website can run with different ones: compare them in cPanel, MultiPHP INI Editor.');
    }

    /**
     * Pure: judges the limits it is given. Public so a test can feed it any host.
     *
     * @param  array<string, string>  $ini
     * @return list<array{status: string, area: string, check: string, detail: string}>
     */
    public static function evaluateLimits(array $ini, int $uploadBytes = 8 * 1024 * 1024, int $maxMegapixels = 40): array
    {
        $rows = [];
        $row = function (string $status, string $check, string $detail) use (&$rows): void {
            $rows[] = ['status' => $status, 'area' => 'PHP limits', 'check' => $check, 'detail' => $detail];
        };

        $post = self::bytes($ini['post_max_size'] ?? '8M');
        $upload = self::bytes($ini['upload_max_filesize'] ?? '2M');
        $memory = self::bytes($ini['memory_limit'] ?? '128M');
        $vars = (int) ($ini['max_input_vars'] ?? 1000);
        $time = (int) ($ini['max_execution_time'] ?? 30);

        if ($post !== null && $post > 0 && $post < self::MIN_POST_BYTES) {
            $row(self::FAIL, 'post_max_size', "{$ini['post_max_size']} is below 2M, so the editor cannot save a large document. Set it to 10M or more.");
        } elseif ($post !== null && $post > 0 && $post < $uploadBytes + 1024 * 1024) {
            $row(self::WARN, 'post_max_size', "{$ini['post_max_size']} is smaller than a full-size upload plus its form data (".self::mb($uploadBytes + 1024 * 1024).' needed). Set it to 10M or more.');
        } else {
            $row(self::PASS, 'post_max_size', ($post === 0 ? 'unlimited' : $ini['post_max_size'] ?? '').' (editor saves up to about 1 MB, uploads up to '.self::mb($uploadBytes).')');
        }

        if ($upload !== null && $upload > 0 && $upload < $uploadBytes) {
            $row(self::WARN, 'upload_max_filesize', "{$ini['upload_max_filesize']} is below the ".self::mb($uploadBytes).' the app allows; larger images fail at the server with a generic error. Set it to 10M.');
        } else {
            $row(self::PASS, 'upload_max_filesize', $upload === 0 ? 'unlimited' : (string) ($ini['upload_max_filesize'] ?? ''));
        }

        if ($memory === null || $memory < 0) {
            $row(self::INFO, 'memory_limit', 'unlimited here; the website may still have a limit, and the app refuses images it cannot decode safely.');
        } elseif ($memory < self::MIN_MEMORY_BYTES) {
            $row(self::FAIL, 'memory_limit', "{$ini['memory_limit']} is too low to decode images at all. Set it to 256M.");
        } elseif ($memory < self::RECOMMENDED_MEMORY_BYTES) {
            // The app itself holds roughly 32 MB, and MemoryGuard keeps 8 MB of slack; GD needs 4 bytes per pixel.
            $safeMp = max(0, (int) floor(($memory - 40 * 1024 * 1024) / 4 / 1_000_000));
            $row(self::WARN, 'memory_limit', "{$ini['memory_limit']}: images above about {$safeMp} megapixels are refused with a clear message (the app allows {$maxMegapixels}; a rotated photo needs a second copy). Set 256M to accept normal camera photos.");
        } else {
            $row(self::PASS, 'memory_limit', (string) ($ini['memory_limit'] ?? ''));
        }

        $row($vars >= 1000 ? self::PASS : self::WARN, 'max_input_vars', $vars >= 1000 ? (string) $vars : "{$vars} is below 1000; long forms can lose fields silently.");
        $row($time === 0 || $time >= 30 ? self::PASS : self::WARN, 'max_execution_time', $time === 0 ? 'unlimited' : "{$time}s".($time < 30 ? ' is short for exports and AI calls (30 or more advised).' : ''));

        return $rows;
    }

    /** PHP shorthand ("8M", "2G", "512K", "-1") to bytes; null for unlimited (-1). */
    public static function bytes(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.').' MB';
    }

    // ------------------------------------------------------------------ application

    private function application(): void
    {
        $production = app()->environment('production');
        $env = (string) config('app.env');

        $this->add(
            $production ? self::PASS : self::INFO,
            'Application',
            'Environment',
            $production ? 'production' : "{$env}; the deploy forces production on the live host.",
        );

        $this->add(
            filled(config('app.key')) ? self::PASS : self::FAIL,
            'Application',
            'APP_KEY',
            filled(config('app.key')) ? 'set' : 'missing; sessions and encrypted values cannot work. Generate it once and never change it.',
        );

        if (config('app.debug')) {
            $this->add($production ? self::FAIL : self::WARN, 'Application', 'APP_DEBUG', 'on; every error would show a stack trace and configuration to visitors.');
        } else {
            $this->add(self::PASS, 'Application', 'APP_DEBUG', 'off');
        }

        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $local = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');

        if ($local) {
            $this->add($production ? self::FAIL : self::INFO, 'Application', 'APP_URL', $url === '' ? 'empty' : "{$url} is a local address; links in emails and share links would be wrong.");
        } elseif ($scheme !== 'https') {
            $this->add($production ? self::WARN : self::INFO, 'Application', 'APP_URL', "{$url} is not https; set the https address.");
        } else {
            $this->add(self::PASS, 'Application', 'APP_URL', $url);
        }

        if ($scheme === 'https') {
            $secure = config('session.secure');
            $this->add($secure === true ? self::PASS : self::WARN, 'Application', 'Session cookie', $secure === true ? 'Secure flag on' : 'SESSION_SECURE_COOKIE is not true, so the session cookie is also sent over http. Set it to true.');
            $hsts = (int) config('security.hsts_seconds', 0);
            $this->add($hsts > 0 ? self::PASS : self::INFO, 'Application', 'HSTS', $hsts > 0 ? "{$hsts} seconds on https responses" : 'off (SECURITY_HSTS_SECONDS=0)');
        }

        $trusted = false;

        try {
            $trusted = in_array(TrustHosts::class, app(HttpKernel::class)->getGlobalMiddleware(), true);
        } catch (Throwable) {
        }

        $this->add(
            self::INFO,
            'Application',
            'Trusted hosts',
            $trusted ? 'enforced by the app' : 'not enforced by the app: keep this domain on its own cPanel virtual host so only design.infodot.co.za reaches it.',
        );

        if ($production) {
            $cached = [
                'config' => app()->configurationIsCached(),
                'routes' => app()->routesAreCached(),
                'events' => app()->eventsAreCached(),
            ];
            $viewFiles = glob(storage_path('framework/views/*.php')) ?: [];
            $cached['views'] = $viewFiles !== [];
            $notCached = array_keys(array_filter($cached, fn (bool $v) => ! $v));

            $this->add(
                $notCached === [] ? self::PASS : self::WARN,
                'Application',
                'Caches',
                $notCached === [] ? 'config, routes, events and views are cached' : 'not cached: '.implode(', ', $notCached).' (run config:cache, route:cache, view:cache, event:cache)',
            );
        }

        $logLevel = strtolower((string) config('logging.channels.single.level', 'debug'));
        $this->add(
            $production && $logLevel === 'debug' ? self::WARN : self::PASS,
            'Application',
            'Log level',
            $logLevel.($production && $logLevel === 'debug' ? ': debug floods the log and may record request details. Set LOG_LEVEL=warning.' : ''),
        );

        $mailer = (string) config('mail.default');
        $mailBad = in_array($mailer, ['log', 'array'], true);
        $this->add(
            $production && $mailBad ? self::WARN : self::PASS,
            'Application',
            'Mail',
            $mailer.($production && $mailBad ? ': mail is written to the log, nobody receives it. Review and approval emails go through Dot.Notify, but password resets use this mailer.' : ''),
        );

        $broadcast = (string) config('broadcasting.default');
        $this->add(
            $production && $broadcast === 'reverb' ? self::WARN : self::PASS,
            'Application',
            'Broadcasting',
            $broadcast.($production && $broadcast === 'reverb' ? ': no Reverb server can run on shared hosting. Set BROADCAST_CONNECTION=log.' : ''),
        );

        $open = in_array('registration', (array) config('fortify.features'), true);
        $this->add($open ? self::INFO : self::PASS, 'Application', 'Sign-up', $open ? 'public sign-up is OPEN (ALLOW_REGISTRATION=true)' : 'closed; InfoDot creates accounts at first sign-in');
    }

    // ------------------------------------------------------------------ database

    private function database(): void
    {
        $production = app()->environment('production');
        $driver = (string) config('database.default');

        try {
            DB::connection()->getPdo();
            $this->add(self::PASS, 'Database', 'Connection', $driver.' at '.($driver === 'sqlite' ? 'a file' : (string) config("database.connections.{$driver}.host")));
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'Connection', 'Cannot connect ('.class_basename($e).'). Check DB_* in .env.');

            return;
        }

        if ($production && $driver !== 'pgsql') {
            $this->add(self::WARN, 'Database', 'Driver', "{$driver}: production runs on PostgreSQL.");
        }

        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                $this->add(self::FAIL, 'Database', 'Migrations', 'The migrations table does not exist. Run php artisan migrate --force.');

                return;
            }

            $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
            $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));

            $this->add(
                $pending === [] ? self::PASS : self::FAIL,
                'Database',
                'Migrations',
                $pending === [] ? count($files).' applied, none pending' : count($pending).' pending (first: '.$pending[0].'). Run php artisan migrate --force.',
            );
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'Migrations', 'Could not read the migration state ('.class_basename($e).').');
        }
    }

    // ------------------------------------------------------------------ storage

    private function storage(): void
    {
        $dirs = [
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $bad = [];

        foreach ($dirs as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }

        $this->add($bad === [] ? self::PASS : self::FAIL, 'Storage', 'Writable folders', $bad === [] ? implode(', ', array_keys($dirs)) : 'Not writable or missing: '.implode(', ', $bad));

        $diskName = (string) config('design.assets.disk');
        $disk = config("filesystems.disks.{$diskName}");

        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local') {
            $this->add(self::FAIL, 'Storage', 'Asset disk', "DESIGN_ASSET_DISK={$diskName} is not a configured local disk.");
        } else {
            $root = (string) ($disk['root'] ?? '');
            $publicRoots = array_unique(array_filter([rtrim(public_path(), '/'), rtrim((string) realpath(public_path()), '/')]));
            $rootReal = rtrim((string) (realpath($root) ?: $root), '/');
            $inPublic = false;

            foreach ($publicRoots as $publicRoot) {
                if ($root !== '' && (str_starts_with(rtrim($root, '/').'/', $publicRoot.'/') || str_starts_with($rootReal.'/', $publicRoot.'/'))) {
                    $inPublic = true;
                }
            }

            $private = ($disk['visibility'] ?? 'private') === 'private' && empty($disk['url']) && ! $inPublic;

            if (! $private) {
                $this->add(self::FAIL, 'Storage', 'Asset disk', "{$diskName} is reachable from the web (public visibility, a url, or a folder under public/). Uploaded designs must stay private.");
            } else {
                $existing = $root;

                while ($existing !== '' && ! is_dir($existing)) {
                    $existing = dirname($existing);
                }

                $writable = $existing !== '' && is_writable($existing);
                $this->add(
                    $writable ? self::PASS : self::FAIL,
                    'Storage',
                    'Asset disk',
                    $writable ? "{$diskName}: private, outside public/, ".(is_dir($root) ? 'writable' : 'will be created on the first upload').' ('.str_replace(base_path().'/', '', $root).')' : "{$diskName}: {$root} is not writable.",
                );
            }
        }

        $free = @disk_free_space(storage_path());

        if ($free !== false) {
            $this->add(
                $free < 100 * 1048576 ? self::FAIL : ($free < 1024 * 1048576 ? self::WARN : self::PASS),
                'Storage',
                'Free disk space',
                self::mb((int) $free).' free where storage/ lives (uploads, rollback copies and backups all land on this account)',
            );
        }
    }

    // ------------------------------------------------------------------ frontend

    private function frontend(): void
    {
        $manifestPath = public_path('build/manifest.json');

        if (! is_file($manifestPath)) {
            $this->add(self::FAIL, 'Frontend', 'Built assets', 'public/build/manifest.json is missing: every page that loads @vite would answer 500.');
        } else {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            if (! is_array($manifest)) {
                $this->add(self::FAIL, 'Frontend', 'Built assets', 'public/build/manifest.json is not valid JSON.');
            } else {
                $needed = self::viteEntriesInViews();
                $missing = array_values(array_filter($needed, fn (string $e) => ! isset($manifest[$e])));
                $this->add(
                    $missing === [] ? self::PASS : self::FAIL,
                    'Frontend',
                    'Built assets',
                    $missing === [] ? count($needed).' entries used by the views are all in the manifest' : 'Missing from the manifest: '.implode(', ', $missing),
                );
            }
        }

        $files = [];
        $absent = [];

        foreach ((array) config('design_fonts.fonts') as $font) {
            foreach ((array) ($font['files'] ?? []) as $path) {
                $files[$path] = true;

                if (! is_file(public_path($path))) {
                    $absent[] = $path;
                }
            }
        }

        $this->add(
            $absent === [] && $files !== [] ? self::PASS : self::FAIL,
            'Frontend',
            'Editor fonts',
            $absent === [] && $files !== [] ? count($files).' self-hosted font files present' : ($files === [] ? 'No fonts are configured.' : count($absent).' missing, first: '.$absent[0]),
        );

        $uiMissing = [];
        $uiRefs = [];

        foreach (File::allFiles(resource_path('css')) as $css) {
            if (preg_match_all('#/fonts/ui/[A-Za-z0-9._-]+\.woff2#', (string) file_get_contents($css->getPathname()), $m)) {
                foreach ($m[0] as $ref) {
                    $uiRefs[$ref] = true;
                }
            }
        }

        foreach (array_keys($uiRefs) as $ref) {
            if (! is_file(public_path(ltrim($ref, '/')))) {
                $uiMissing[] = $ref;
            }
        }

        $this->add(
            $uiRefs !== [] && $uiMissing === [] ? self::PASS : self::FAIL,
            'Frontend',
            'Interface fonts',
            $uiRefs === [] ? 'The stylesheets reference no interface fonts under /fonts/ui.' : ($uiMissing === [] ? count($uiRefs).' files referenced by the stylesheets are present' : 'Missing: '.implode(', ', $uiMissing)),
        );
    }

    /**
     * Every entry named in a literal @vite([...]) in the Blade views. Dynamic arguments are skipped.
     *
     * @return list<string>
     */
    public static function viteEntriesInViews(?string $viewsPath = null): array
    {
        $entries = [];

        foreach (File::allFiles($viewsPath ?? resource_path('views')) as $view) {
            if (! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match_all('/@vite\(\s*\[(.*?)\]\s*\)/s', (string) file_get_contents($view->getPathname()), $directives)) {
                foreach ($directives[1] as $list) {
                    if (preg_match_all('/[\'"](resources\/[^\'"]+)[\'"]/', $list, $m)) {
                        foreach ($m[1] as $entry) {
                            $entries[$entry] = true;
                        }
                    }
                }
            }
        }

        $entries = array_keys($entries);
        sort($entries);

        return $entries;
    }

    // ------------------------------------------------------------------ data

    private function data(): void
    {
        if (! Schema::hasTable('design_templates') || ! Schema::hasTable('token_sets')) {
            $this->add(self::FAIL, 'Data', 'Tables', 'design_templates or token_sets is missing; run the migrations.');

            return;
        }

        try {
            $read = app(TemplateImporter::class)->read();
            $valid = count($read['valid']);
            $refused = count($read['refused']);
            $published = DesignTemplate::withoutGlobalScopes()->where('source', 'curated')->where('status', 'published')->count();

            if ($refused > 0) {
                $this->add(self::FAIL, 'Data', 'Template files', "{$refused} template definition(s) are refused (run php artisan design:seed-templates --check for the reasons).");
            } elseif ($valid === 0) {
                $this->add(self::FAIL, 'Data', 'Template files', 'database/templates holds no valid template definitions.');
            } else {
                $this->add(self::PASS, 'Data', 'Template files', "{$valid} definitions, all valid");
            }

            if ($published === 0) {
                $this->add(self::FAIL, 'Data', 'Templates seeded', 'No published template in the database: the template browser is empty. Run php artisan design:seed-templates.');
            } elseif ($published < $valid) {
                $this->add(self::WARN, 'Data', 'Templates seeded', "{$published} published of {$valid} definitions. Run php artisan design:seed-templates.");
            } else {
                $this->add(self::PASS, 'Data', 'Templates seeded', "{$published} published");
            }
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Data', 'Templates', 'Could not read the templates ('.class_basename($e).': '.$e->getMessage().').');
        }

        $sets = TokenSet::query()->count();
        $this->add($sets > 0 ? self::PASS : self::WARN, 'Data', 'Design-system reference data', $sets > 0 ? "{$sets} token sets" : 'No token sets. Run php artisan db:seed --class=DesignSystemSeeder --force once.');

        if (Schema::hasColumn('users', 'is_platform_operator')) {
            $operators = User::query()->where('is_platform_operator', true)->count();
            $this->add($operators > 0 ? self::PASS : self::INFO, 'Data', 'Platform operator', $operators > 0 ? "{$operators} user(s)" : 'none yet: nobody can publish curated templates or edit the design-system catalogue. See docs/R45_OPERATIONS.md.');
        }
    }

    // ------------------------------------------------------------------ queue and schedule

    private function queueAndSchedule(): void
    {
        $production = app()->environment('production');
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->add($production ? self::WARN : self::INFO, 'Queue', 'Queue driver', 'sync: notifications and the analytics snapshot run inside the request. Use database on the live host.');
        } elseif ($connection === 'database') {
            if (! Schema::hasTable('jobs')) {
                $this->add(self::FAIL, 'Queue', 'Queue driver', 'database, but the jobs table is missing.');
            } else {
                $oldest = DB::table('jobs')->min('created_at');
                $waiting = DB::table('jobs')->count();
                $age = $oldest ? time() - (int) $oldest : 0;
                $this->add(
                    $age > 900 ? self::WARN : self::PASS,
                    'Queue',
                    'Backlog',
                    $waiting === 0 ? 'database, nothing waiting' : "{$waiting} job(s) waiting, the oldest for ".intdiv($age, 60).' min'.($age > 900 ? '. The cron worker is not draining them (or the site was in maintenance mode: it does not run then).' : ''),
                );
            }

            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $this->add($failed > 0 ? self::WARN : self::PASS, 'Queue', 'Failed jobs', $failed > 0 ? "{$failed} failed job(s): php artisan queue:failed to read them, queue:retry all to retry." : 'none');
        } else {
            $this->add(self::INFO, 'Queue', 'Queue driver', $connection);
        }

        $this->add(self::INFO, 'Queue', 'Worker model', 'cron-driven: queue:work --stop-when-empty --tries=3 --max-time=50 every minute; no daemon is needed (deploy.yml installs the line).');

        $scheduled = [];

        foreach (app(Schedule::class)->events() as $event) {
            $scheduled[] = (string) ($event->command ?? '');
        }

        $expected = ['design:prune-ai-proposals', 'design:publish-metrics', 'design-system:scan-token-drift'];
        $missing = array_values(array_filter($expected, fn (string $c) => ! collect($scheduled)->contains(fn (string $s) => str_contains($s, $c))));
        $this->add($missing === [] ? self::PASS : self::FAIL, 'Schedule', 'Registered tasks', $missing === [] ? implode(', ', $expected) : 'Not scheduled: '.implode(', ', $missing));

        $log = storage_path('logs/cron-scheduler.log');
        $seen = is_file($log) ? time() - (int) filemtime($log) : null;

        if ($seen !== null && $seen < 600) {
            $this->add(self::PASS, 'Schedule', 'Cron seen', 'schedule:run wrote its log '.intdiv($seen, 60).' min ago');
        } else {
            $this->add($production ? self::WARN : self::INFO, 'Schedule', 'Cron seen', $seen === null ? 'storage/logs/cron-scheduler.log does not exist yet: the cron lines are installed by the deploy workflow after this check (expected on a first deploy).' : 'the scheduler log is '.intdiv($seen, 60).' min old: the cron line is not running.');
        }
    }

    // ------------------------------------------------------------------ optional integrations

    private function integrations(): void
    {
        $production = app()->environment('production');
        $sso = (string) config('ecosystem.infodot.secret', '');

        $this->add(
            filled($sso) ? self::PASS : ($production ? self::WARN : self::INFO),
            'Integrations',
            'InfoDot sign-in',
            filled($sso) ? 'service secret set' : 'INFODOT_SERVICE_SECRET is empty: people cannot sign in through InfoDot (the only way in, as sign-up is closed).',
        );

        $aiKey = (string) config('design_ai.api_key');
        if (! config('design_ai.enabled')) {
            $this->add(self::OFF, 'Integrations', 'Assistant (AI)', 'switched off (DESIGN_AI_ENABLED=false); the deterministic features still work');
        } elseif ($aiKey === '') {
            $this->add(self::OFF, 'Integrations', 'Assistant (AI)', 'off: ANTHROPIC_API_KEY is empty; the brief recommender, improve heuristics and resize still work');
        } else {
            $this->add(self::PASS, 'Integrations', 'Assistant (AI)', 'on, model '.config('design_ai.model').'; billed per call, capped at '.config('design_ai.limits.user_daily').' per person and '.config('design_ai.limits.workspace_daily').' per workspace a day');
        }

        $driver = (string) config('ai_image.driver', 'mock');
        $this->add($driver === 'mock' ? self::OFF : self::WARN, 'Integrations', 'AI image generation', $driver === 'mock' ? 'mock driver (honest placeholder, no real provider is contacted)' : "driver {$driver}: real, billed image calls.");

        $this->integration('Dot.Notify', 'design_integrations.notify', ['url' => 'NOTIFY_INGEST_URL', 'secret' => 'NOTIFY_INGEST_SECRET'], 'DESIGN_INTEGRATION_NOTIFY');
        $this->integration('Dot.Files', 'design_integrations.files', ['url' => 'FILES_API_URL', 'secret' => 'FILES_PLATFORM_SECRET'], 'DESIGN_INTEGRATION_FILES');

        if (! config('design_integrations.membership.enabled')) {
            $this->add(self::OFF, 'Integrations', 'InfoDot membership webhook', 'off (DESIGN_INTEGRATION_MEMBERSHIP=false)');
        } else {
            $this->add(filled($sso) ? self::PASS : self::FAIL, 'Integrations', 'InfoDot membership webhook', filled($sso) ? 'on; InfoDot must list `design` in ECOSYSTEM_MEMBERSHIP_PLATFORMS' : 'on, but INFODOT_SERVICE_SECRET is empty, so every call would be refused.');
        }

        $set = array_filter([
            'ANALYTICS_INGEST_URL' => config('analytics.url'),
            'ANALYTICS_INGEST_SECRET' => config('analytics.secret'),
            'ANALYTICS_COMPANY_UUID' => config('analytics.company_uuid'),
        ], fn ($v) => filled($v));

        if ($set === []) {
            $this->add(self::OFF, 'Integrations', 'Dot.Analytics snapshot', 'off: ANALYTICS_* are empty');
        } elseif (count($set) < 3) {
            $this->add(self::WARN, 'Integrations', 'Dot.Analytics snapshot', 'partly set (nothing is sent until the URL, secret and company uuid are all set)');
        } else {
            $this->add(self::PASS, 'Integrations', 'Dot.Analytics snapshot', 'on, aggregate counts once a day');
        }

        $font = config('design.export.png_font');
        if (blank($font)) {
            $this->add(self::OFF, 'Integrations', 'Server PNG font', 'DESIGN_EXPORT_FONT unset; only the legacy server PNG endpoint is affected (the editor exports in the browser)');
        } else {
            $this->add(is_file((string) $font) ? self::PASS : self::FAIL, 'Integrations', 'Server PNG font', is_file((string) $font) ? (string) $font : "DESIGN_EXPORT_FONT points at {$font}, which does not exist.");
        }
    }

    /**
     * @param  array{url: string, secret: string}  $vars
     */
    private function integration(string $label, string $configKey, array $vars, string $flag): void
    {
        if (! config("{$configKey}.enabled")) {
            $this->add(self::OFF, 'Integrations', $label, "off ({$flag}=false)");

            return;
        }

        $missing = [];

        if (blank(config("{$configKey}.url"))) {
            $missing[] = $vars['url'];
        }

        if (blank(config("{$configKey}.secret"))) {
            $missing[] = $vars['secret'];
        }

        if ($missing !== []) {
            $this->add(self::FAIL, 'Integrations', $label, "{$flag}=true but ".implode(' and ', $missing).' is empty; every call would fail.');

            return;
        }

        $url = (string) config("{$configKey}.url");
        $this->add(str_starts_with($url, 'https://') ? self::PASS : self::WARN, 'Integrations', $label, 'on, '.parse_url($url, PHP_URL_HOST).(str_starts_with($url, 'https://') ? '' : ' (not https)'));
    }

    // ------------------------------------------------------------------ output

    /** @param  list<array{status: string, area: string, check: string, detail: string}>  $rows */
    private function addAll(array $rows): void
    {
        foreach ($rows as $row) {
            $this->results[] = $row;
        }
    }

    private function add(string $status, string $area, string $check, string $detail): void
    {
        $this->results[] = ['status' => $status, 'area' => $area, 'check' => $check, 'detail' => $detail];
    }

    private function report(): int
    {
        $counts = array_fill_keys([self::PASS, self::INFO, self::OFF, self::WARN, self::FAIL], 0);

        foreach ($this->results as $r) {
            $counts[$r['status']]++;
        }

        $exit = ($counts[self::FAIL] > 0 || ($this->option('strict') && $counts[self::WARN] > 0)) ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $exit === self::SUCCESS, 'counts' => $counts, 'results' => $this->results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $area = null;

        foreach ($this->results as $r) {
            if ($r['area'] !== $area) {
                $area = $r['area'];
                $this->newLine();
                $this->line("<fg=white;options=bold>{$area}</>");
            }

            $colour = match ($r['status']) {
                self::PASS => 'green',
                self::FAIL => 'red',
                self::WARN => 'yellow',
                default => 'gray',
            };
            $this->line(sprintf('  <fg=%s>%-4s</>  %-28s %s', $colour, $r['status'], $r['check'], $r['detail']));
        }

        $this->newLine();
        $summary = sprintf('%d pass, %d info, %d off, %d warning(s), %d failure(s).', $counts[self::PASS], $counts[self::INFO], $counts[self::OFF], $counts[self::WARN], $counts[self::FAIL]);

        if ($exit === self::SUCCESS) {
            $this->info('Preflight OK: '.$summary);
        } else {
            $this->error('Preflight FAILED: '.$summary);
        }

        return $exit;
    }
}
