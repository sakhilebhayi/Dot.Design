<?php

// R-45 output routes. Loaded from routes/web.php (inside the auth group). Define routes only; no limiters, aliases, gates or bindings here (route:cache).

use App\Http\Controllers\Studio\DesignCopyController;
use App\Http\Middleware\JsonApi;
use App\Http\Middleware\LimitRequestBody;
use Illuminate\Support\Facades\Route;

Route::prefix('design')->name('design.')->group(function () {
    // "Make this for another place": one new project per target size, re-laid out by the browser and re-sanitised here.
    // The throttle is an inline string (provider-free) so route:cache works without a limiter defined in a route file.
    Route::post('projects/{project}/copies', [DesignCopyController::class, 'store'])
        ->middleware([JsonApi::class, 'throttle:30,1', LimitRequestBody::class.':'.(6 * 1024 * 1024)])
        ->name('projects.copies.store');
});
