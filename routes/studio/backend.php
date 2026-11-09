<?php

// R-45 backend routes. Loaded from routes/web.php (inside the auth group). Define routes only; no limiters, aliases, gates or bindings here (route:cache).

use App\Http\Controllers\Studio\ActivityController;
use App\Http\Controllers\Studio\AssetLibraryController;
use App\Http\Controllers\Studio\CommentController;
use App\Http\Controllers\Studio\DocumentController;
use App\Http\Controllers\Studio\ExportAuditController;
use App\Http\Controllers\Studio\GrantController;
use App\Http\Controllers\Studio\PageController;
use App\Http\Controllers\Studio\ProjectAssetController;
use App\Http\Controllers\Studio\ProjectController;
use App\Http\Controllers\Studio\ReviewController;
use App\Http\Controllers\Studio\SettingsController;
use App\Http\Controllers\Studio\ShareController;
use App\Http\Controllers\Studio\ThumbnailController;
use App\Http\Controllers\Studio\VersionController;
use App\Http\Middleware\JsonApi;
use App\Http\Middleware\LimitRequestBody;
use Illuminate\Support\Facades\Route;

Route::prefix('design')->name('design.')->group(function () {
    // Asset library. The list answers HTML for a browser and JSON for fetch(); upload, serve and delete stay in DesignAssetController.
    Route::get('assets', [AssetLibraryController::class, 'index'])->name('assets.index');

    Route::middleware(JsonApi::class)->group(function () {
        Route::get('assets/folders', [AssetLibraryController::class, 'foldersJson'])->name('assets.folders');
        Route::put('assets/{asset}', [AssetLibraryController::class, 'update'])->name('assets.update');

        // Per-user studio preferences.
        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        // Review inbox and workspace audit trail.
        Route::get('reviews', [ReviewController::class, 'inbox'])->name('reviews.inbox');
        Route::get('activity', [ActivityController::class, 'workspace'])->name('activity.index');

        Route::prefix('projects/{project}')->name('projects.')->group(function () {
            // Document and project properties.
            Route::get('document', [DocumentController::class, 'show'])->name('document');
            Route::put('/', [ProjectController::class, 'update'])->name('update');

            // Pages. The fixed "order" path is registered before {page} so it is not read as a page id.
            Route::put('pages/order', [PageController::class, 'order'])->middleware('throttle:design-saves')->name('pages.order');
            Route::post('pages', [PageController::class, 'store'])->middleware('throttle:design-saves')->name('pages.store');
            Route::put('pages/{page}', [PageController::class, 'update'])
                ->middleware(['throttle:design-saves', LimitRequestBody::class])
                ->name('pages.update');
            Route::delete('pages/{page}', [PageController::class, 'destroy'])->middleware('throttle:design-saves')->name('pages.destroy');
            Route::post('pages/{page}/duplicate', [PageController::class, 'duplicate'])->middleware('throttle:design-saves')->name('pages.duplicate');

            // Thumbnail (a small PNG or WebP rendered by the browser), served only through the policy-checked GET.
            Route::put('thumbnail', [ThumbnailController::class, 'update'])
                ->middleware(['throttle:design-thumbnails', LimitRequestBody::class.':'.(400 * 1024)])
                ->name('thumbnail.update');
            Route::post('thumbnail', [ThumbnailController::class, 'update'])->middleware('throttle:design-thumbnails')->name('thumbnail.upload');

            // A picture that is part of this project, for people who can see the project but did not upload it.
            Route::get('assets/{asset}/file', [ProjectAssetController::class, 'file'])->whereNumber('asset')->name('assets.file');

            // History.
            Route::get('versions', [VersionController::class, 'index'])->name('versions.index');
            Route::post('versions', [VersionController::class, 'store'])->middleware('throttle:design-saves')->name('versions.store');
            Route::get('versions/{version}', [VersionController::class, 'show'])->name('versions.show');
            Route::get('versions/{version}/diff', [VersionController::class, 'diff'])->name('versions.diff');
            Route::post('versions/{version}/restore', [VersionController::class, 'restore'])->middleware('throttle:design-saves')->name('versions.restore');

            // Share links.
            Route::get('shares', [ShareController::class, 'index'])->name('shares.index');
            Route::post('shares', [ShareController::class, 'store'])->middleware('throttle:design-shares')->name('shares.store');
            Route::delete('shares/{share}', [ShareController::class, 'destroy'])->middleware('throttle:design-shares')->name('shares.destroy');

            // Comments.
            Route::get('comments', [CommentController::class, 'index'])->name('comments.index');
            Route::post('comments', [CommentController::class, 'store'])->middleware('throttle:design-comments')->name('comments.store');
            Route::put('comments/{comment}', [CommentController::class, 'update'])->middleware('throttle:design-comments')->name('comments.update');
            Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->middleware('throttle:design-comments')->name('comments.destroy');
            Route::post('comments/{comment}/resolve', [CommentController::class, 'resolve'])->middleware('throttle:design-comments')->name('comments.resolve');
            Route::delete('comments/{comment}/resolve', [CommentController::class, 'unresolve'])->middleware('throttle:design-comments')->name('comments.unresolve');

            // Review and approval.
            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews', [ReviewController::class, 'store'])->middleware('throttle:design-comments')->name('reviews.store');
            Route::post('reviews/{review}/approve', [ReviewController::class, 'approve'])->middleware('throttle:design-comments')->name('reviews.approve');
            Route::post('reviews/{review}/request-changes', [ReviewController::class, 'requestChanges'])->middleware('throttle:design-comments')->name('reviews.request-changes');
            Route::post('reviews/{review}/cancel', [ReviewController::class, 'cancel'])->middleware('throttle:design-comments')->name('reviews.cancel');

            // Audit trail, export audit, explicit access.
            Route::get('activity', [ActivityController::class, 'project'])->name('activity');
            Route::post('exports', [ExportAuditController::class, 'store'])->middleware('throttle:design-exports')->name('exports.store');
            Route::get('grants', [GrantController::class, 'index'])->name('grants.index');
            Route::post('grants', [GrantController::class, 'store'])->middleware('throttle:design-shares')->name('grants.store');
            Route::delete('grants/{grant}', [GrantController::class, 'destroy'])->middleware('throttle:design-shares')->name('grants.destroy');
        });
    });

    // The thumbnail image itself is an image response, not JSON.
    Route::get('projects/{project}/thumbnail', [ThumbnailController::class, 'show'])->name('projects.thumbnail.show');
});
