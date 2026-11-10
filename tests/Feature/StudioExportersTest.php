<?php

namespace Tests\Feature;

use App\Models\DesignCanvas;
use App\Services\CanvasPngExporter;
use App\Services\CanvasSvgExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * The editor exports in the browser; these server exporters remain for the API and legacy links and must not crash on
 * schema v2 pages.
 */
class StudioExportersTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    private function v2Page(): DesignCanvas
    {
        $project = $this->project($this->person());
        $page = $project->canvases()->first();
        $page->update([
            'width' => 400, 'height' => 300,
            'background' => ['type' => 'solid', 'color' => '#112233', 'gradient' => null, 'asset_id' => null, 'fit' => 'cover'],
            'elements' => [
                ['id' => 'a', 'type' => 'shape', 'kind' => 'rect', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 50, 'fill' => '#ff000080', 'radius' => 8, 'stroke' => ['color' => '#00ff00', 'width' => 2]],
                ['id' => 'b', 'type' => 'shape', 'kind' => 'ellipse', 'x' => 20, 'y' => 20, 'width' => 60, 'height' => 60, 'fill' => ['kind' => 'linear', 'angle' => 0, 'stops' => [['at' => 0, 'color' => '#0000ff'], ['at' => 1, 'color' => '#ffffff']]]],
                ['id' => 'c', 'type' => 'shape', 'kind' => 'star', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'fill' => '#fff'],
                ['id' => 'd', 'type' => 'icon', 'icon' => 'arrow-right', 'x' => 0, 'y' => 0, 'width' => 24, 'height' => 24],
                ['id' => 'e', 'type' => 'image', 'asset_id' => null, 'fit' => 'cover', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50],
                ['id' => 'f', 'type' => 'text', 'x' => 0, 'y' => 100, 'width' => 200, 'height' => 40, 'text' => 'Centred <b>& safe</b>', 'fontSize' => 20, 'color' => '#ffffff', 'align' => 'center'],
                ['id' => 'g', 'type' => 'group', 'x' => 200, 'y' => 200, 'width' => 50, 'height' => 50, 'children' => [
                    ['id' => 'h', 'type' => 'shape', 'kind' => 'rect', 'x' => 5, 'y' => 5, 'width' => 20, 'height' => 20, 'fill' => '#abcdef'],
                ]],
                ['id' => 'i', 'type' => 'shape', 'kind' => 'rect', 'hidden' => true, 'x' => 0, 'y' => 0, 'width' => 9, 'height' => 9, 'fill' => '#123456'],
                ['id' => 'j', 'type' => 'spaceship'],
            ],
        ]);

        return $page->fresh();
    }

    public function test_the_svg_exporter_draws_v2_pages_and_skips_what_it_cannot(): void
    {
        $svg = app(CanvasSvgExporter::class)->export($this->v2Page());

        $this->assertStringContainsString('width="400" height="300"', $svg);
        $this->assertStringContainsString('fill="#112233"', $svg);
        $this->assertStringContainsString('<rect x="10" y="10" width="100" height="50" rx="8" fill="#ff0000" fill-opacity="0.502"', $svg);
        $this->assertStringContainsString('stroke="#00ff00"', $svg);
        $this->assertStringContainsString('<ellipse', $svg);
        $this->assertStringContainsString('fill="#0000ff"', $svg, 'a gradient is drawn as its first stop');
        $this->assertStringContainsString('translate(200 200)', $svg);
        $this->assertStringContainsString('fill="#abcdef"', $svg);
        $this->assertStringContainsString('text-anchor="middle"', $svg);
        $this->assertStringContainsString('Centred &lt;b&gt;&amp;', $svg);
        $this->assertStringNotContainsString('#123456', $svg, 'hidden elements are not drawn');
        $this->assertStringNotContainsString('Add a picture', $svg);
        $this->assertStringNotContainsString('unavailable', strtolower($svg), 'an empty picture slot is not exported as content');
        $this->assertNotFalse(simplexml_load_string($svg), 'the output is well-formed XML');
    }

    public function test_the_svg_exporter_still_renders_v1_pages_as_before(): void
    {
        $project = $this->project($this->person());
        $page = $project->canvases()->first();
        $page->update(['background_color' => '#f5f5f5', 'elements' => [
            ['id' => 'r', 'type' => 'rectangle', 'x' => 1, 'y' => 2, 'width' => 30, 'height' => 40, 'fill' => '#ec4899'],
            ['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 20, 'text' => 'Hi', 'fontSize' => 24, 'color' => '#111111'],
        ]]);

        $svg = app(CanvasSvgExporter::class)->export($page->fresh());

        $this->assertStringContainsString('width="1200" height="628"', $svg);
        $this->assertStringContainsString('fill="#f5f5f5"', $svg);
        $this->assertStringContainsString('fill="#ec4899"', $svg);
        $this->assertStringContainsString('>Hi</tspan>', $svg);
    }

    public function test_hostile_colours_never_become_markup(): void
    {
        $project = $this->project($this->person());
        $page = $project->canvases()->first();
        $page->update(['elements' => [['id' => 's', 'type' => 'shape', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'width' => 5, 'height' => 5, 'fill' => '"/><script>alert(1)</script>', 'stroke' => ['color' => '"onload="x', 'width' => 3]]]]);

        $svg = app(CanvasSvgExporter::class)->export($page->fresh());

        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('onload', $svg);
        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function test_a_share_style_image_url_closure_is_used_instead_of_embedding(): void
    {
        $project = $this->project($this->person());
        $page = $project->canvases()->first();
        $page->update(['elements' => [['id' => 'i', 'type' => 'image', 'asset_id' => 5, 'fit' => 'cover', 'x' => 0, 'y' => 0, 'width' => 50, 'height' => 50]]]);

        $svg = app(CanvasSvgExporter::class)->export($page->fresh(), fn ($id) => "https://example.test/a/{$id}?x=1&y=\"2\"");

        $this->assertStringContainsString('href="https://example.test/a/5?x=1&amp;y=&quot;2&quot;"', $svg);
        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function test_the_png_exporter_does_not_crash_on_v2_pages(): void
    {
        $page = $this->v2Page();
        $page->update(['elements' => array_values(array_filter($page->elements, fn ($e) => $e['type'] !== 'text'))]);

        $png = app(CanvasPngExporter::class)->export($page->fresh());

        $this->assertStringStartsWith("\x89PNG", $png);
        $info = getimagesizefromstring($png);
        $this->assertSame([400, 300], [$info[0], $info[1]]);
        $image = imagecreatefromstring($png);
        $this->assertSame(0x112233, imagecolorat($image, 399, 299) & 0xFFFFFF, 'v2 background colour');
    }
}
