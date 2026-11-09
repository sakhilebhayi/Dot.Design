<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Services\Design\BrandKitService;
use App\Services\Design\Formats;
use App\Services\Design\TemplateRepository;
use App\Support\Ui\StartScreen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The template browser page. `design.templates.index` and `design.templates.show` are one URL each for two audiences: a script
 * asking for JSON gets the list or the template (TemplateController, unchanged); a browser navigating there gets the page.
 * The decision is the request's Accept header, so every existing JSON caller (they all send `Accept: application/json`) is unaffected.
 */
class TemplateBrowserController extends Controller
{
    public function index(Request $request, TemplateController $json, StartScreen $start, BrandKitService $kits, TemplateRepository $templates): JsonResponse|View
    {
        if ($request->expectsJson()) {
            return app()->call([$json, 'index']);
        }

        return $this->page($request, $start, $kits, $templates, $request->query('open'));
    }

    /** A template's own address opens the browser with its detail dialog showing. */
    public function show(Request $request, string $template, TemplateController $json, StartScreen $start, BrandKitService $kits, TemplateRepository $templates): JsonResponse|View
    {
        if ($request->expectsJson()) {
            return app()->call([$json, 'show'], ['template' => $template]);
        }

        return $this->page($request, $start, $kits, $templates, $template);
    }

    /** Platform labels for the filter (the stored values are lower-case words). */
    private const PLATFORMS = [
        'instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'x' => 'X', 'tiktok' => 'TikTok', 'youtube' => 'YouTube',
        'whatsapp' => 'WhatsApp', 'pinterest' => 'Pinterest', 'print' => 'Print', 'pdf' => 'PDF', 'presentation' => 'Presentation',
        'email' => 'Email', 'web' => 'Website', 'digital' => 'Digital', 'office' => 'Office',
    ];

    private function page(Request $request, StartScreen $start, BrandKitService $kits, TemplateRepository $templates, mixed $open): View
    {
        $groups = app(Formats::class)->grouped();
        $kit = $kits->defaultFor($request->user());

        return view('design.templates.index', [
            'boot' => [
                'open' => is_string($open) && $open !== '' ? $open : null,
                'categories' => (array) config('design_copy.template_categories'),
                'platforms' => self::PLATFORMS,
                'formats' => collect($groups)->map(fn ($g) => ['label' => $g['label'], 'items' => collect($g['formats'])->map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'size_label' => $f['size_label'], 'width' => $f['px']['width'], 'height' => $f['px']['height']])->all()])->all(),
                'fonts' => $start->fontManifest(),
                'previous' => $start->previousBrief($request->user()),
                'kit' => $kit === null ? null : [
                    'id' => $kit->id,
                    'name' => $kit->name,
                    'colors' => collect((array) $kit->colors)->map(fn ($c) => ['hex' => $c['hex'], 'name' => $c['name'] ?? null])->take(6)->values()->all(),
                    'tagline' => $kit->tagline,
                    'website' => $kit->website,
                    'contacts' => (array) ($kit->contacts ?? []),
                ],
                'goals' => collect((array) config('design_brief_flow.goals'))->map(fn ($g) => $g['purpose'])->all(),
                'urls' => [
                    'templates' => route('design.templates.index'),
                    'facets' => route('design.templates.facets'),
                    'template_base' => url('design/templates'),
                    'from_brief' => route('design.projects.from-brief'),
                    'start' => route('design.start'),
                ],
            ],
        ]);
    }
}
