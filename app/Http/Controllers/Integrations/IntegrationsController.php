<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The settings page and JSON status for the connections to other Dot platforms. Read-only: there is no control here, because
 * switching an integration on is a server configuration change, not something a person does in the browser. Setup details
 * (variable names) are shown to platform operators only.
 */
class IntegrationsController extends Controller
{
    public function index(Request $request, IntegrationStatus $status): View
    {
        return view('design.integrations.index', [
            'integrations' => $status->all(),
            'operator' => (bool) $request->user()?->is_platform_operator,
        ]);
    }

    public function status(Request $request, IntegrationStatus $status): JsonResponse
    {
        return response()->json(['integrations' => $status->toArray((bool) $request->user()?->is_platform_operator)]);
    }
}
