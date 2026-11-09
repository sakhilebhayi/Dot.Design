<?php

// R-45 pages polish routes. Loaded from routes/web.php (inside the auth group). Define routes only (route:cache).

use App\Http\Controllers\Studio\MilestoneController;
use Illuminate\Support\Facades\Route;

Route::post('design/milestones/{key}', [MilestoneController::class, 'seen'])->where('key', '[a-z0-9_]{1,40}')->name('design.milestones.seen');
