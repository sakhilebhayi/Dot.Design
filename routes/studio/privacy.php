<?php

// R-45 privacy routes. Loaded from routes/web.php (inside the auth group). Define routes only (route:cache).

use App\Http\Controllers\Studio\MyDataController;
use Illuminate\Support\Facades\Route;

Route::get('design/settings/my-data', [MyDataController::class, 'download'])->middleware('throttle:design-exports')->name('design.settings.my-data');
