<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EcosystemErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('errorCodes')]
    public function test_error_page_renders_branded_content(int $code, string $expectedHeading): void
    {
        $response = $this->get("/_test-only-error-render/{$code}");

        $response->assertStatus($code);
        $response->assertSee($expectedHeading);
        $response->assertSee('Dot.Design', false);
        $response->assertDontSee('the rest of the Dot Ecosystem', false);
        $this->assertLessThanOrEqual(1, substr_count($response->getContent(), 'class="lt-btn'), 'one primary action');
    }

    public static function errorCodes(): array
    {
        return [
            '404 not found' => [404, "We couldn't find that page"],
            '403 forbidden' => [403, "You don't have access to this"],
            '419 page expired' => [419, 'Your session timed out'],
            '429 too many requests' => [429, 'Slow down a little'],
            '500 server error' => [500, 'Something went wrong on our end'],
            '503 unavailable' => [503, "We'll be right back"],
        ];
    }

    public function test_a_url_that_matches_no_route_knows_who_is_signed_in(): void
    {
        $guest = $this->get('/no-such-page');
        $guest->assertNotFound()->assertSee('Go to the start page')->assertDontSee('Back to Home');

        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get('/no-such-page')->assertNotFound()->assertSee('Back to Home')->assertDontSee('Go to the start page');
        $this->actingAs($user)->getJson('/design/nope')->assertNotFound();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/_test-only-error-render/{code}', function (int $code) {
            return response()->view("errors.{$code}", [], $code);
        });
    }
}
