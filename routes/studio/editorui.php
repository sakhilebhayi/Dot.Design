<?php

// R-45 editor-ui routes. Loaded from routes/web.php (inside the auth group). Define routes only (route:cache).

use App\Http\Controllers\Studio\EditorController;
use App\Http\Controllers\Studio\EditorLibraryController;
use Illuminate\Support\Facades\Route;

Route::prefix('design')->name('design.')->group(function () {
    Route::get('studio/elements', [EditorLibraryController::class, 'elements'])->name('studio.elements');
    Route::get('projects/{project}', [EditorController::class, 'show'])->name('projects.show');
});
