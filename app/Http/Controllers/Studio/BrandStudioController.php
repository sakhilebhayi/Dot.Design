<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignBrandUpdate;
use App\Models\DesignProject;
use App\Services\Design\BrandKitService;
use App\Services\Design\BrandService;
use App\Support\Ui\FontManifest;
use App\Support\Ui\ProjectCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The Brand Studio pages: the list of kits, the kit editor, and the "Brand updated" review. The pages only render a shell and
 * the boot data; everything they change goes through the JSON endpoints of the business layer (docs/R45_BUSINESS.md). Viewing a
 * page or a preview never changes a kit or a design.
 */
class BrandStudioController extends Controller
{
    public function index(Request $request, BrandKitService $kits): View
    {
        $kitModels = $kits->listFor($request->user());
        $pending = DesignBrandUpdate::query()
            ->where('status', DesignBrandUpdate::PENDING)
            ->whereIn('brand_kit_id', $kitModels->pluck('id'))
            ->whereHas('project')
            ->selectRaw('brand_kit_id, count(*) as total')
            ->groupBy('brand_kit_id')
            ->pluck('total', 'brand_kit_id');

        $cards = $kitModels->map(fn (BrandKit $kit) => $kits->present($kit, $request->user()) + [
            'outdated_designs' => (int) ($pending[$kit->id] ?? 0),
        ])->all();

        return view('design.brand.index', [
            'kits' => $cards,
            'fonts' => FontManifest::forBrowser(),
            'canCreate' => $this->mayCreate($request),
            'pendingTotal' => (int) $pending->sum(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->mayCreate($request), 403, 'Your role in this workspace cannot create brand kits.');

        return $this->editor($request, null);
    }

    public function edit(Request $request, BrandKit $kit): View
    {
        $this->authorize('view', $kit);

        return $this->editor($request, $kit);
    }

    /** All the designs waiting on a brand update, newest first. */
    public function updates(Request $request, BrandService $brand): View
    {
        $notices = collect($brand->pendingNotices());
        $projects = DesignProject::query()->whereIn('id', $notices->pluck('project_id'))->get()->keyBy('id');

        $rows = $notices->map(function (array $n) use ($projects) {
            $project = $projects->get($n['project_id']);

            return $n + [
                'width' => $project?->width,
                'height' => $project?->height,
                'thumbnail_url' => $project ? ProjectCard::thumbnailUrl($project) : null,
                'review_url' => route('design.brand.updates.show', $n['project_id']),
            ];
        })->all();

        return view('design.brand.updates', ['notices' => $rows]);
    }

    /** Review one design's brand update: what would change, and the choice of what to apply. */
    public function updatesShow(Request $request, DesignProject $project): View
    {
        $this->authorize('view', $project);

        return view('design.brand.update-show', [
            'project' => $project,
            'canEdit' => Gate::forUser($request->user())->allows('update', $project),
            'thumbnailUrl' => ProjectCard::thumbnailUrl($project),
            'fonts' => FontManifest::forBrowser(),
        ]);
    }

    /** Whether the person's role in their current workspace lets them create a kit (a kit is made in the current workspace). */
    private function mayCreate(Request $request): bool
    {
        $user = $request->user();
        $team = $user->currentTeam;

        return $team === null || $user->hasTeamPermission($team, 'create');
    }

    private function editor(Request $request, ?BrandKit $kit): View
    {
        $kits = app(BrandKitService::class);

        return view('design.brand.edit', [
            'kit' => $kit ? $kits->present($kit, $request->user()) : null,
            'options' => [
                'color_roles' => config('design_copy.color_roles'),
                'brand_roles' => config('design_copy.brand_roles'),
                'font_slots' => BrandKitService::FONT_SLOTS,
                'contact_keys' => BrandKitService::CONTACT_KEYS,
                'single_roles' => BrandAsset::SINGLE_ROLES,
                'contact_fields' => collect(BrandKitService::CONTACT_KEYS)->mapWithKeys(fn (string $k) => [$k => [
                    'label' => config("design_brief_flow.fields.$k.label") ?? ucfirst(str_replace('_', ' ', $k)),
                    'example' => config("design_brief_flow.fields.$k.example"),
                ]])->all(),
            ],
            'fonts' => FontManifest::forBrowser(),
            'limits' => [
                'max_kb' => (int) config('design.assets.max_kilobytes'),
                'kits_max' => BrandKitService::MAX_KITS_PER_WORKSPACE,
                'asset_count' => DesignAsset::query()->count(),
            ],
            'teamName' => $request->user()->currentTeam?->name,
        ]);
    }
}
