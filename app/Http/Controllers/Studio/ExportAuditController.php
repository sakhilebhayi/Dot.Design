<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignCanvas;
use App\Models\DesignExport;
use App\Models\DesignProject;
use App\Services\Design\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Records that an export happened. The browser renders and downloads the file; the server never sees it, it only keeps
 * the audit row (format, pages, scale) that Analytics and the activity trail read.
 */
class ExportAuditController extends StudioController
{
    public function store(Request $request, DesignProject $project, ActivityLog $activity): JsonResponse
    {
        $this->authorize('export', $project);

        $data = $request->validate([
            'format' => ['required', Rule::in(DesignExport::FORMATS)],
            'pages' => ['nullable'],
            'scale' => ['nullable', 'numeric', 'between:0.1,8'],
            'bytes' => ['nullable', 'integer', 'min:0', 'max:4000000000'],
        ]);

        $pages = 'all';

        if (is_array($data['pages'] ?? null)) {
            $ids = array_values(array_unique(array_map('intval', $data['pages'])));
            $known = DesignCanvas::where('design_project_id', $project->id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if (count($ids) > 60 || array_diff($ids, $known) !== []) {
                return $this->invalid('Some of those pages are not part of this project.', ['pages' => ['Unknown page.']]);
            }

            $pages = implode(',', $ids);
        } elseif (($data['pages'] ?? 'all') !== 'all' && $data['pages'] !== null) {
            return $this->invalid('Pages must be "all" or a list of page ids.', ['pages' => ['Invalid.']]);
        }

        $export = DesignExport::create([
            'design_project_id' => $project->id,
            'user_id' => $request->user()->id,
            'format' => $data['format'],
            'pages' => $pages,
            'scale' => $data['scale'] ?? 1,
            'bytes' => $data['bytes'] ?? null,
        ]);

        $activity->record($request->user(), $project, 'export.recorded', 'export', $export->id, [
            'format' => $export->format, 'pages' => mb_substr($pages, 0, 120), 'scale' => $export->scale,
        ]);

        return response()->json(['status' => 'recorded', 'export' => ['id' => $export->id, 'format' => $export->format, 'pages' => $pages, 'scale' => $export->scale]], 201);
    }
}
