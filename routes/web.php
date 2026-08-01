<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\ComponentController;
use App\Http\Controllers\DesignTokenController;
use App\Http\Controllers\TokenConsumptionRecordController;
use App\Http\Controllers\TokenSetController;
use Illuminate\Support\Facades\Route;


Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $userId = auth()->id();

        $totalProjects  = \App\Models\DesignProject::where('user_id', $userId)->count();
        $recentProjects = \App\Models\DesignProject::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(30))->count();
        $totalCanvases  = \App\Models\DesignCanvas::whereHas(
            'project', fn ($q) => $q->where('user_id', $userId)
        )->count();
        $totalAssets    = \App\Models\DesignAsset::where('user_id', $userId)->count();
        $aiGenerations  = \App\Models\AiGenerationLog::where('user_id', $userId)->count();

        $projects = \App\Models\DesignProject::where('user_id', $userId)
            ->withCount(['canvases'])
            ->latest()
            ->get();

        $recentAssets = \App\Models\DesignAsset::where('user_id', $userId)
            ->latest()
            ->limit(8)
            ->get();

        $generationsByProvider = \App\Models\AiGenerationLog::where('user_id', $userId)
            ->selectRaw('provider, count(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider');

        return view('dashboard', compact(
            'totalProjects', 'recentProjects', 'totalCanvases',
            'totalAssets', 'aiGenerations', 'projects', 'recentAssets',
            'generationsByProvider'
        ));
    })->name('dashboard');

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
