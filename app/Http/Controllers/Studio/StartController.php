<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignProject;
use App\Services\Design\BrandKitService;
use App\Services\Design\BriefFlow;
use App\Services\Design\Formats;
use App\Support\Ui\StartScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * The front door: "What would you like to make?" (goal cards, common sizes, a blank canvas at any size, recent designs) and the
 * guided brief wizard that follows a goal. Both are pages; the data behind them is the JSON the business layer already serves
 * (docs/R45_BUSINESS.md), read here through the same services.
 */
class StartController extends Controller
{
    /** The sizes shown as one-click chips. A fixed, plain list of the most used ones; the picker holds all of them. */
    private const COMMON = ['instagram-post-square', 'instagram-story', 'whatsapp-status', 'facebook-post', 'a4-portrait', 'a5-portrait', 'business-card-za', 'presentation'];

    public function index(Request $request, BriefFlow $flow, Formats $formats, StartScreen $start): View
    {
        $preset = $start->resolveFormat($request->query('format'));

        $common = collect(self::COMMON)->map(fn (string $key) => $formats->find($key))->filter()->values()->all();
        $recent = DesignProject::query()->latest('updated_at')->limit(6)->get();

        return view('design.start.index', [
            'goals' => $flow->goals(),
            'common' => $common,
            'groups' => $formats->grouped(),
            'preset' => $preset,
            'recent' => $recent,
            'brandUpdated' => \App\Support\Ui\ProjectCard::brandUpdatedIds($recent),
            'assistant' => StartScreen::assistantEnabled(),
            'limits' => $start->sizeLimits(),
            'fonts' => $start->fontManifest(),
            'urls' => $this->urls(),
        ]);
    }

    public function brief(Request $request, BriefFlow $flow, Formats $formats, StartScreen $start, BrandKitService $kits): View|RedirectResponse
    {
        $goal = $request->query('goal');
        $narrowed = is_string($goal) ? $flow->forGoal($goal) : null;

        if ($narrowed === null) {
            return redirect()->route('design.start');
        }

        $preset = $start->resolveFormat($request->query('format'));
        $kit = $kits->defaultFor($request->user());

        return view('design.start.brief', [
            'goal' => $narrowed['goal'],
            'boot' => [
                'goal' => $narrowed['goal'],
                'steps' => $narrowed['steps'],
                'styles' => $narrowed['styles'],
                'limits' => (array) config('design_brief_flow.limits'),
                'groups' => $formats->grouped(),
                'preset_format' => ($preset['kind'] ?? null) === 'format' ? $preset['format']['key'] : null,
                'previous' => $start->previousBrief($request->user()),
                'kit' => $kit === null ? null : $this->kitSummary($kits->present($kit, $request->user())),
                'fonts' => $start->fontManifest(),
                'urls' => $this->urls() + ['start' => route('design.start')],
            ],
        ]);
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        return [
            'store' => route('design.projects.store'),
            'recommend' => route('design.brief.recommend'),
            'from_brief' => route('design.projects.from-brief'),
            'templates' => route('design.templates.index'),
            'template_base' => url('design/templates'),
            'brief_page' => route('design.start.brief'),
            'assets' => route('design.assets.index'),
            'asset_upload' => route('design.assets.store'),
            'palette' => route('design.brand.palette'),
            'kits' => route('design.brand.kits.store'),
            'compose' => Route::has('design.assistant.compose') ? route('design.assistant.compose') : url('design/assistant/compose'),
        ];
    }

    /**
     * @param  array<string, mixed>  $kit
     * @return array<string, mixed>
     */
    private function kitSummary(array $kit): array
    {
        return [
            'id' => $kit['id'],
            'name' => $kit['name'],
            'colors' => collect((array) $kit['colors'])->map(fn ($c) => ['hex' => $c['hex'], 'name' => $c['name'] ?? null])->take(6)->values()->all(),
            'has_logo' => collect((array) ($kit['assets'] ?? []))->contains(fn ($a) => in_array($a['role'] ?? null, ['logo', 'logo-light', 'mark'], true)),
        ];
    }
}
