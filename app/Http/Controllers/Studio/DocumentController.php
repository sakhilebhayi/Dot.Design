<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Services\Design\DocumentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends StudioController
{
    /**
     * The full editable document: project, pages (v1 rows upgraded on the fly, nothing written back), assets, the
     * viewer's permissions and the schema limits.
     */
    public function show(Request $request, DesignProject $project, DocumentPresenter $presenter): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($presenter->document($project, $request->user()))
            ->setPrivate()->header('Cache-Control', 'private, no-store');
    }
}
