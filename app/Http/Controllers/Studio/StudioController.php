<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Shared helpers for the studio JSON API. Authorisation is by policy (capability checks through DesignAccess); a row
 * that does not belong to the project named in the URL is a 404, the same answer as a row that does not exist.
 */
abstract class StudioController extends Controller
{
    protected function pageOf(DesignProject $project, DesignCanvas $page): DesignCanvas
    {
        abort_unless((int) $page->design_project_id === (int) $project->id, 404);

        return $page;
    }

    /** A child row (comment, review, share, version) must belong to the project in the URL. */
    protected function childOf(DesignProject $project, Model $child, string $column = 'design_project_id'): Model
    {
        abort_unless((int) $child->{$column} === (int) $project->id, 404);

        return $child;
    }

    /** @param array<string, mixed> $body */
    protected function invalid(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json(['status' => $status === 413 ? 'too_large' : 'invalid', 'message' => $message, 'errors' => (object) $errors], $status);
    }
}
