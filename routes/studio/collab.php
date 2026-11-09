<?php

// R-45 collab routes. Loaded from routes/web.php (inside the auth group). Define routes only; no limiters, aliases, gates or bindings here (route:cache).

use App\Http\Controllers\Studio\ActivityPageController;
use App\Http\Controllers\Studio\PeopleController;
use App\Http\Controllers\Studio\ReviewInboxController;
use App\Http\Middleware\JsonApi;
use Illuminate\Support\Facades\Route;

Route::prefix('design')->name('design.')->group(function () {
    // Pages. The backend already owns the JSON routes at design/reviews (design.reviews.inbox) and design/activity
    // (design.activity.index), so the pages live beside them under their own paths; the rail finds the inbox by route name.
    Route::get('review', [ReviewInboxController::class, 'index'])->name('reviews.index');
    Route::get('activity/log', [ActivityPageController::class, 'index'])->name('activity.page');

    // Who is in the workspace, for the mention picker, the reviewer picker and the "people with access" list.
    Route::get('projects/{project}/people', [PeopleController::class, 'index'])->middleware(JsonApi::class)->name('projects.people');
});
