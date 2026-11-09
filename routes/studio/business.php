<?php

// R-45 business routes. Loaded from routes/web.php (inside the auth group). Define routes only; no limiters, aliases, gates or bindings here (route:cache).

use App\Http\Controllers\Studio\BrandKitController;
use App\Http\Controllers\Studio\BrandPaletteController;
use App\Http\Controllers\Studio\BrandProjectController;
use App\Http\Controllers\Studio\BriefController;
use App\Http\Controllers\Studio\FormatsController;
use App\Http\Controllers\Studio\TemplateController;
use App\Http\Middleware\Studio\AcceptsJson;
use Illuminate\Support\Facades\Route;

Route::prefix('design')->name('design.')->middleware(AcceptsJson::class)->group(function () {
    // Formats
    Route::get('formats', [FormatsController::class, 'index'])->name('formats.index');

    // Guided brief
    Route::get('brief/flow', [BriefController::class, 'flow'])->name('brief.flow');
    Route::post('brief/recommend', [BriefController::class, 'recommend'])->middleware('throttle:design-briefs')->name('brief.recommend');
    Route::post('projects/from-brief', [BriefController::class, 'store'])->middleware('throttle:design-briefs')->name('projects.from-brief');

    // Templates
    Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('templates/facets', [TemplateController::class, 'facets'])->name('templates.facets');
    Route::post('templates', [TemplateController::class, 'store'])->middleware('throttle:design-briefs')->name('templates.store');
    Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
    Route::post('templates/{template}/fill', [TemplateController::class, 'fill'])->middleware('throttle:design-briefs')->name('templates.fill');
    Route::put('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::middleware('operator')->group(function () {
        Route::post('templates/{template}/publish', [TemplateController::class, 'publish'])->name('templates.publish');
        Route::post('templates/{template}/retire', [TemplateController::class, 'retire'])->name('templates.retire');
    });

    // Brand Studio
    Route::get('brand/kits', [BrandKitController::class, 'index'])->name('brand.kits.index');
    Route::post('brand/kits', [BrandKitController::class, 'store'])->name('brand.kits.store');
    Route::get('brand/kits/{kit}', [BrandKitController::class, 'show'])->name('brand.kits.show');
    Route::put('brand/kits/{kit}', [BrandKitController::class, 'update'])->name('brand.kits.update');
    Route::delete('brand/kits/{kit}', [BrandKitController::class, 'destroy'])->name('brand.kits.destroy');
    Route::post('brand/kits/{kit}/default', [BrandKitController::class, 'makeDefault'])->name('brand.kits.default');
    Route::post('brand/kits/{kit}/assets', [BrandKitController::class, 'attachAsset'])->name('brand.kits.assets.store');
    Route::delete('brand/kits/{kit}/assets/{brandAsset}', [BrandKitController::class, 'detachAsset'])->whereNumber('brandAsset')->name('brand.kits.assets.destroy');
    Route::post('brand/palette', BrandPaletteController::class)->middleware('throttle:design-briefs')->name('brand.palette');
    Route::get('brand/notices', [BrandProjectController::class, 'notices'])->name('brand.notices');

    // A project and its brand kit
    Route::get('projects/{project}/brand', [BrandProjectController::class, 'show'])->name('projects.brand.show');
    Route::post('projects/{project}/brand/apply', [BrandProjectController::class, 'apply'])->name('projects.brand.apply');
    Route::post('projects/{project}/brand/dismiss', [BrandProjectController::class, 'dismiss'])->name('projects.brand.dismiss');
    Route::put('projects/{project}/brand-kit', [BrandProjectController::class, 'link'])->name('projects.brand.link');
});
