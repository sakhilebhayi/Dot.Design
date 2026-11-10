<?php

// R-45 ai routes. Loaded from routes/web.php (inside the auth group). Define routes only; no limiters, aliases, gates or bindings here (route:cache).

use App\Http\Controllers\Studio\AiAssistantController;
use App\Http\Controllers\Studio\AiProposalController;
use App\Http\Middleware\Studio\AcceptsJson;
use Illuminate\Support\Facades\Route;

Route::prefix('design')->name('design.assistant.')->middleware(AcceptsJson::class)->group(function () {
    Route::get('assistant/status', [AiAssistantController::class, 'status'])->name('status');
    Route::post('assistant/compose', [AiAssistantController::class, 'compose'])->middleware('throttle:design-ai')->name('compose');
    Route::post('assistant/copy', [AiAssistantController::class, 'copy'])->middleware('throttle:design-ai')->name('copy');

    Route::post('projects/{project}/assistant/improve', [AiProposalController::class, 'improve'])->middleware('throttle:design-ai')->name('improve');
    Route::post('projects/{project}/assistant/proposals', [AiProposalController::class, 'store'])->middleware('throttle:design-briefs')->name('proposals.store');
    Route::post('ai-proposals/{proposal}/apply', [AiProposalController::class, 'apply'])->middleware('throttle:design-briefs')->name('proposals.apply');
    Route::post('ai-proposals/{proposal}/dismiss', [AiProposalController::class, 'dismiss'])->middleware('throttle:design-briefs')->name('proposals.dismiss');
});
