<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantLimitReached;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\Assistant\UsageGuard;
use App\Support\Design\DesignAccess;
use Closure;
use Illuminate\Http\JsonResponse;

/** Maps the assistant's plain failures onto honest JSON answers. No model name, key or provider body ever leaves here. */
abstract class AiController extends Controller
{
    protected function guarded(Closure $run): JsonResponse
    {
        try {
            return $run();
        } catch (AssistantUnavailable $e) {
            return response()->json(['status' => 'unavailable', 'message' => $e->getMessage()]);
        } catch (AssistantLimitReached $e) {
            $user = request()->user();

            return response()->json([
                'status' => 'limit',
                'message' => $e->getMessage(),
                'scope' => $e->scope,
                'usage' => app(UsageGuard::class)->usage($user, DesignAccess::defaultTeamId($user->id)),
            ], 429);
        } catch (AssistantFailed $e) {
            return response()->json([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'retryable' => $e->retryable,
            ], $e->reason === 'busy' ? 503 : 502);
        }
    }
}
