<?php

use App\Http\Controllers\Integrations\MembershipWebhookController;
use Illuminate\Support\Facades\Route;

// Server-to-server routes for the integrations agent. Loaded by StudioIntegrationsServiceProvider under the `api` middleware group and
// the /api prefix (not from routes/web.php: there is no signed-in user here). Authentication is the HMAC signature, checked in the controller.

// InfoDot -> Design: a company's membership changed. Answers 401 for everything unless DESIGN_INTEGRATION_MEMBERSHIP is on and the signature is valid.
Route::post('ecosystem/membership', MembershipWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('design.integrations.membership');
