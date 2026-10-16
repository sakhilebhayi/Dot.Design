<?php

use App\Http\Controllers\AiGenerationController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\ComponentController;
use App\Http\Controllers\DesignAssetController;
use App\Http\Controllers\DesignCanvasController;
use App\Http\Controllers\DesignCanvasExportController;
use App\Http\Controllers\DesignProjectController;
use App\Http\Controllers\DesignTokenController;
use App\Http\Controllers\TokenConsumptionRecordController;
use App\Http\Controllers\TokenSetController;
use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        // DesignProject/DesignAsset/AiGenerationLog all apply HasUserScope
        // (see app/Models/Concerns/HasUserScope.php), so every query below
        // is already scoped to the authenticated user at the model level --
        // the explicit where('user_id', ...) calls this closure used to
        // carry are redundant and were removed. DesignCanvas has no user_id
        // column of its own, so whereHas('project') still needs to touch
        // the project relation, but that relation query is itself scoped
        // by DesignProject's own global scope.
        $totalProjects = DesignProject::count();
        $recentProjects = DesignProject::where('created_at', '>=', now()->subDays(30))->count();
        $totalCanvases = DesignCanvas::whereHas('project')->count();
        $totalAssets = DesignAsset::count();
        $aiGenerations = AiGenerationLog::count();

        $projects = DesignProject::withCount(['canvases'])
            ->latest()
            ->get();

        $recentAssets = DesignAsset::latest()
            ->limit(8)
            ->get();

        $generationsByProvider = AiGenerationLog::selectRaw('provider, count(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider');

        return view('dashboard', compact(
            'totalProjects', 'recentProjects', 'totalCanvases',
            'totalAssets', 'aiGenerations', 'projects', 'recentAssets',
            'generationsByProvider'
        ));
    })->name('dashboard');

    // Canvas / AI-creation tool domain -- previously had no per-ID routes at
    // all (see wiki.md §"0.3.2"'s note on this). Route-namespaced separately
    // from design-system/* below, matching the two-domains split documented
    // throughout wiki.md §1.
    //
    // "create" must be registered before the "{project}" resource-style
    // routes, otherwise it's swallowed by {project}'s implicit route-model
    // binding.
    Route::prefix('design')->name('design.')->group(function () {
        Route::get('projects', [DesignProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/create', [DesignProjectController::class, 'create'])->name('projects.create');
        Route::post('projects', [DesignProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}', [DesignProjectController::class, 'show'])->name('projects.show');
        Route::delete('projects/{project}', [DesignProjectController::class, 'destroy'])->name('projects.destroy');

        Route::post('projects/{project}/canvases', [DesignCanvasController::class, 'store'])->name('projects.canvases.store');
        Route::put('projects/{project}/canvases/{canvas}', [DesignCanvasController::class, 'update'])->name('projects.canvases.update');
        Route::get('projects/{project}/canvases/{canvas}/export.svg', [DesignCanvasExportController::class, 'svg'])->name('projects.canvases.export.svg');

        Route::post('projects/{project}/ai-generations', [AiGenerationController::class, 'store'])->name('projects.ai-generations.store');

        Route::delete('assets/{asset}', [DesignAssetController::class, 'destroy'])->name('assets.destroy');
    });

    // Design-token / component-library domain (MVP scaffold).
    // Deliberately global/shared — not scoped to the current user or team.
    // See database/migrations/2026_08_01_000001_create_design_system_tables.php
    // for the tenancy rationale.
    Route::prefix('design-system')->name('design-system.')->group(function () {
        Route::resource('token-sets', TokenSetController::class)
            ->parameters(['token-sets' => 'tokenSet'])
            ->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::post('token-sets/{tokenSet}/tokens', [DesignTokenController::class, 'store'])
            ->name('token-sets.tokens.store');
        Route::put('token-sets/{tokenSet}/tokens/{token}', [DesignTokenController::class, 'update'])
            ->name('token-sets.tokens.update');
        Route::delete('token-sets/{tokenSet}/tokens/{token}', [DesignTokenController::class, 'destroy'])
            ->name('token-sets.tokens.destroy');

        Route::post('token-sets/{tokenSet}/consumers', [TokenConsumptionRecordController::class, 'store'])
            ->name('token-sets.consumers.store');
        Route::put('token-sets/{tokenSet}/consumers/{record}', [TokenConsumptionRecordController::class, 'update'])
            ->name('token-sets.consumers.update');
        Route::delete('token-sets/{tokenSet}/consumers/{record}', [TokenConsumptionRecordController::class, 'destroy'])
            ->name('token-sets.consumers.destroy');

        Route::resource('components', ComponentController::class)
            ->only(['index', 'show', 'store', 'update', 'destroy']);
    });
});
