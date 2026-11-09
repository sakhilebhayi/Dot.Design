<?php

namespace Tests\Feature\Ai;

use App\Services\Design\Assistant\Contracts\ModelClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BusinessFixtures;
use Tests\Support\FakeModelClient;
use Tests\Support\StudioWorld;
use Tests\TestCase;

abstract class AiTestCase extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;
    use StudioWorld;

    protected FakeModelClient $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new FakeModelClient;
        $this->app->instance(ModelClient::class, $this->model);
    }

    /** A valid compose plan for the sale fixture, with overrides. */
    protected function plan(array $overrides = []): array
    {
        return array_replace_recursive([
            'template_slug' => 'fixture-sale-bold-square',
            'format_key' => 'instagram-post-square',
            'style' => 'bold',
            'palette' => 'brand',
            'business_name' => null,
            'copy' => ['headline' => 'Fresh pies daily', 'subheadline' => null, 'tagline' => null, 'offer' => null, 'cta' => null, 'description' => null],
            'questions' => [],
        ], $overrides);
    }
}
