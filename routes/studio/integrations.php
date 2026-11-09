<?php

use App\Http\Controllers\Integrations\DotFilesController;
use App\Http\Controllers\Integrations\IntegrationsController;
use Illuminate\Support\Facades\Route;

// R-45 integrations routes. Loaded from routes/web.php (inside the auth group). Define routes only; no limiters, aliases, gates or bindings here (route:cache).
// The InfoDot membership webhook is server to server and signed, not signed-in, so it lives in routes/studio/integrations_api.php.

Route::prefix('design/integrations')->name('design.integrations.')->group(function () {
    Route::get('/', [IntegrationsController::class, 'index'])->name('index');
    Route::get('status', [IntegrationsController::class, 'status'])->name('status');
});

// Offered by the UI only while the Dot.Files integration is on; answers 404 otherwise.
Route::post('design/projects/{project}/dot-files', [DotFilesController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('design.integrations.files.store');
