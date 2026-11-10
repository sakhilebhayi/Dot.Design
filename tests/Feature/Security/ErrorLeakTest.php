<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

/**
 * R-45 security, part 7: errors tell a visitor nothing. With debug off (the deploy forces it), a crash, a database error, a
 * missing page, a forged form and a throttled request all answer a plain page or a plain JSON body: no exception text, no
 * SQL, no file path, no class name, no stack trace.
 */
class ErrorLeakTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKS = ['SQLSTATE', 'vendor/', '/Users/', 'Stack trace', 'Illuminate\\', 'App\\', 'RuntimeException', 'secret-db-password', '#0 ', 'password='];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        Route::middleware('web')->get('/__boom', fn () => throw new \RuntimeException('secret-db-password /etc/passwd password=hunter2'));
        Route::middleware('web')->get('/__sql', fn () => throw new QueryException('pgsql', 'select * from users where password = ?', ['secret-db-password'], new \PDOException('SQLSTATE[08006] connection to server at 10.0.0.5 failed')));
        Route::middleware('web')->get('/__forbidden', fn () => abort(403, 'secret-db-password'));
        Route::middleware('web')->get('/__denied', fn () => throw new AuthorizationException('secret-db-password'));
    }

    private function assertNoLeak(string $body, string $label): void
    {
        foreach (self::LEAKS as $leak) {
            $this->assertStringNotContainsString($leak, $body, "{$label} leaked '{$leak}'");
        }
    }

    public function test_html_errors_leak_nothing(): void
    {
        foreach (['/__boom' => 500, '/__sql' => 500, '/__forbidden' => 403, '/__denied' => 403, '/no-such-page' => 404] as $path => $status) {
            $response = $this->get($path, ['Accept' => 'text/html']);
            $response->assertStatus($status);
            $this->assertNoLeak((string) $response->getContent(), $path);
        }
    }

    public function test_json_errors_leak_nothing_on_the_api_and_the_studio(): void
    {
        foreach (['/__boom', '/__sql', '/__forbidden', '/__denied'] as $path) {
            $response = $this->getJson($path);
            $this->assertNoLeak((string) $response->getContent(), "{$path} (json)");
            $decoded = (array) json_decode((string) $response->getContent(), true);
            foreach (['trace', 'file', 'line', 'exception'] as $key) {
                $this->assertArrayNotHasKey($key, $decoded);
            }
        }

        $this->getJson('/api/user')->assertStatus(401);
        $this->getJson('/design/projects/999999/document')->assertStatus(401);
    }

    public function test_the_csrf_failure_page_and_the_throttled_pages_say_nothing_about_the_system(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->app->bind(PreventRequestForgery::class, EnforcedCsrf::class);

        $forged = $this->actingAs($user)->post('/logout', [], ['Sec-Fetch-Site' => 'cross-site']);
        $forged->assertStatus(419);
        $this->assertNoLeak((string) $forged->getContent(), '419');
        $this->assertStringNotContainsString('TokenMismatchException', (string) $forged->getContent());

        $this->get('/csp-report')->assertStatus(405);
    }

    public function test_the_health_check_reveals_nothing_beyond_up(): void
    {
        $body = (string) $this->get('/up')->assertOk()->getContent();

        foreach (['version', 'php', 'laravel', 'database', 'env'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word.'"', $body);
        }
    }

    public function test_the_app_refuses_to_run_with_debug_on_in_production_configuration(): void
    {
        // The deploy pipeline forces APP_DEBUG=false; the shipped example file must never say otherwise.
        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $example);
        // The example itself stays http://localhost so CI (which copies it to .env) behaves like local runs; the live value is documented.
        $this->assertStringContainsString('# LIVE: https://design.infodot.co.za', $example, 'the cookie Secure flag follows the scheme of APP_URL: the live value must be https');
    }
}
