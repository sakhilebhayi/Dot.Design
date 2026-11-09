<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Services\Design\ActivityLog;
use App\Services\Design\DocumentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project properties the studio changes: name, status (draft <-> archived), visibility. The size of a project is NOT
 * changeable here (resizing is a deliberate operation of its own); review states are set by the review flow only.
 */
class ProjectController extends StudioController
{
    public function update(Request $request, DesignProject $project, ActivityLog $activity, DocumentPresenter $presenter): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'archived'])],
            'visibility' => ['sometimes', Rule::in(DesignProject::VISIBILITIES)],
            'width' => ['prohibited'],
            'height' => ['prohibited'],
            'unit' => ['prohibited'],
        ], [
            'width.prohibited' => 'The size of a project cannot be changed here.',
            'height.prohibited' => 'The size of a project cannot be changed here.',
            'unit.prohibited' => 'The size of a project cannot be changed here.',
        ]);

        if (array_key_exists('visibility', $data) && $data['visibility'] !== $project->visibility) {
            $this->authorize('changeVisibility', $project);

            if ($data['visibility'] === 'team' && $project->team_id === null) {
                return $this->invalid('This project does not belong to a workspace, so it cannot be shared with one.', ['visibility' => ['No workspace.']]);
            }
        }

        if (array_key_exists('status', $data) && $project->status === 'in_review' && $data['status'] === 'archived') {
            return $this->invalid('Cancel the review before archiving this project.', ['status' => ['A review is waiting.']], 409);
        }

        if (array_key_exists('status', $data) && in_array($project->status, ['approved', 'in_review'], true) && $data['status'] === 'draft') {
            return $this->invalid('An approved project returns to draft by being edited; a review in progress is cancelled from the review.', ['status' => ['Not allowed from here.']], 409);
        }

        $before = ['visibility' => $project->visibility, 'status' => $project->status];
        $project->fill(collect($data)->only('name')->all());
        $project->forceFill(collect($data)->only(['status', 'visibility'])->all())->save();

        if ($before['visibility'] !== $project->visibility) {
            $activity->record($request->user(), $project, 'project.visibility_changed', 'project', $project->id, ['from' => $before['visibility'], 'to' => $project->visibility]);
        }

        if ($before['status'] !== $project->status) {
            $activity->record($request->user(), $project, 'project.status_changed', 'project', $project->id, ['from' => $before['status'], 'to' => $project->status]);
        }

        return response()->json(['status' => 'updated', 'project' => $presenter->project($project->fresh(), $request->user())]);
    }
}
