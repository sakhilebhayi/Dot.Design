<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 2b: the endpoints that take whole documents (save, copies, thumbnail, proposals) are sent bodies no
 * editor would send. Whatever the shape, the answer is a plain 4xx with no stack trace and the stored page is untouched.
 */
class HostileRequestBodyTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    private function raw(string $method, string $uri, string $body, array $server = [])
    {
        return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + $server, $body);
    }

    public function test_malformed_extreme_and_oversized_bodies_get_a_plain_4xx_and_leave_the_page_alone(): void
    {
        $t = $this->tenant('Alpha');
        $this->actingAs($t['owner']);
        $url = route('design.projects.pages.update', [$t['project'], $t['page']]);
        $before = $t['page']->fresh()->only(['elements', 'revision']);

        $deep = str_repeat('[', 5000).str_repeat(']', 5000);

        $cases = [
            'NaN literal' => '{"revision":1,"elements":[{"type":"text","x":NaN}]}',
            'Infinity literal' => '{"revision":1,"elements":[{"type":"text","x":Infinity}]}',
            'truncated' => '{"revision":1,"elements":[{"type":"te',
            'deep nesting' => '{"revision":1,"elements":'.$deep.'}',
            'elements a string' => '{"revision":1,"elements":"<script>alert(1)</script>"}',
            'element a string' => '{"revision":1,"elements":["x","y"]}',
            'revision a list' => '{"revision":[1],"elements":[]}',
            'revision a string' => '{"revision":"1; DROP TABLE design_canvases","elements":[]}',
            'revision huge' => '{"revision":99999999999999999999999,"elements":[]}',
            'bad utf8 escape' => '{"revision":1,"elements":[{"id":"a","type":"text","text":"\ud800"}]}',
            'empty' => '',
            'scalar' => '5',
            'null' => 'null',
        ];

        foreach ($cases as $name => $body) {
            $response = $this->raw('PUT', $url, $body);
            $status = $response->getStatusCode();

            $this->assertContains($status, [400, 409, 413, 422], "{$name} answered {$status}");
            $this->assertStringNotContainsString('Stack trace', (string) $response->getContent(), $name);
            $this->assertStringNotContainsString('vendor/laravel', (string) $response->getContent(), $name);
            $this->assertStringNotContainsString('/Users/', (string) $response->getContent(), $name);
        }

        $this->assertSame($before, $t['page']->fresh()->only(['elements', 'revision']), 'a refused save must leave the page exactly as it was');
    }

    public function test_an_oversized_save_is_refused_before_it_is_parsed(): void
    {
        $t = $this->tenant('Alpha');
        $url = route('design.projects.pages.update', [$t['project'], $t['page']]);
        $big = '{"revision":1,"elements":[],"pad":"'.str_repeat('A', 1_600_000).'"}';

        $this->actingAs($t['owner']);
        $response = $this->raw('PUT', $url, $big);
        $response->assertStatus(413);
        $this->assertSame('too_large', $response->json('status'));

        $thumb = route('design.projects.thumbnail.update', $t['project']);
        $this->raw('PUT', $thumb, str_repeat('A', 500_000))->assertStatus(413);
    }

    public function test_the_other_document_endpoints_survive_the_same_bodies(): void
    {
        $t = $this->tenant('Alpha');
        $this->actingAs($t['owner']);

        $endpoints = [
            ['POST', route('design.projects.copies.store', $t['project'])],
            ['POST', route('design.assistant.proposals.store', $t['project'])],
            ['POST', route('design.projects.pages.store', $t['project'])],
            ['PUT', route('design.projects.pages.order', $t['project'])],
            ['POST', route('design.projects.from-brief')],
            ['POST', route('design.brand.palette')],
            ['POST', route('design.brand.kits.store')],
            ['PUT', route('design.brand.kits.update', $t['kit'])],
            ['POST', route('design.projects.comments.store', $t['project'])],
            ['POST', route('design.projects.exports.store', $t['project'])],
        ];

        $bodies = ['{"pages":'.str_repeat('[', 3000).'}', '{"pages":"x","brief":"x","ops":"x","colors":"x","body":[]}', '[[[[]]]]', '{"a":NaN}', '', '{"colors":'.str_repeat('{"a":', 600).'1'.str_repeat('}', 600).'}'];

        foreach ($endpoints as [$method, $url]) {
            foreach ($bodies as $i => $body) {
                $status = $this->raw($method, $url, $body)->getStatusCode();
                $this->assertLessThan(500, $status, "{$method} {$url} with body #{$i} answered {$status}");
            }
        }
    }

    public function test_server_errors_never_carry_a_trace_or_a_path_in_json(): void
    {
        config(['app.debug' => false]);
        $t = $this->tenant('Alpha');
        $this->actingAs($t['owner']);

        // A route that throws: a model-less asset file whose disk row is broken.
        $t['asset']->forceFill(['disk' => 'no_such_disk'])->save();
        $response = $this->getJson(route('design.assets.file', $t['asset']));

        $this->assertContains($response->getStatusCode(), [404, 500]);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('vendor/', $body);
        $this->assertStringNotContainsString('/Users/', $body);
        $this->assertStringNotContainsString('no_such_disk', $body);
        $this->assertStringNotContainsString('"trace"', $body);
        $this->assertStringNotContainsString('"file"', $body);
    }
}
