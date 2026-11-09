<?php

// R-45 public routes. Loaded from routes/web.php (outside the auth group). Define routes only; no limiters, aliases, gates or bindings here (route:cache).

use App\Http\Controllers\Studio\PublicShareController;
use App\Http\Middleware\PublicShareHeaders;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Share links. Anonymous by design: no session is started (so no cookie is ever set), and without a session there is
// no CSRF token to check, so the comment POST is exempt. The link itself is the credential; every route re-validates it.
Route::prefix('s/{token}')
    ->name('studio.share.')
    // Any segment reaches the controller, which answers every unusable token with the same 404 page.
    ->where(['token' => '[^/]+', 'asset' => '[0-9]{1,18}'])
    ->withoutMiddleware([
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->middleware(PublicShareHeaders::class)
    ->group(function () {
        Route::get('/', [PublicShareController::class, 'show'])->middleware('throttle:design-public-view')->name('show');
        Route::get('assets/{asset}', [PublicShareController::class, 'asset'])->middleware(['signed', 'throttle:design-public-view'])->name('asset');
        Route::post('comments', [PublicShareController::class, 'comment'])->middleware('throttle:design-public-comments')->name('comment');
    });
