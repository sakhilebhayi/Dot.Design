<?php

namespace App\Services\Design\Assistant;

use App\Models\DesignAiProposal;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\ActivityLog;
use App\Services\Design\VersionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of a proposal: proposed, then applied or dismissed. Applying records the decision and takes a version
 * snapshot FIRST; the browser then applies the operations as one undoable step.
 */
class ProposalService
{
    public function __construct(
        private readonly OpValidator $validator,
        private readonly ImproveService $improve,
        private readonly VersionService $versions,
        private readonly ActivityLog $activity,
    ) {}

    /**
     * An automatic fix computed in the browser. It goes through the same validator as the model's proposals and
     * makes no call to the model, so it is not counted against the daily caps.
     *
     * @param  list<mixed>  $ops
     * @param  list<string>  $selection
     */
    public function storeAutomatic(User $user, DesignProject $project, DesignCanvas $canvas, array $ops, string $label, array $selection = []): ?DesignAiProposal
    {
        $page = $this->improve->pageArray($project, $canvas);
        $result = $this->validator->validate($page, $ops, $this->improve->kitFor($project), $selection, $this->improve->pageText($page['elements']), snapToBrand: false);

        if ($result->isEmpty()) {
            return null;
        }

        return DesignAiProposal::create([
            'design_project_id' => $project->id,
            'team_id' => $project->team_id,
            'user_id' => $user->id,
            'mode' => 'improve',
            'status' => 'proposed',
            'prompt' => mb_substr($label, 0, 200),
            'payload' => $this->improve->patchPayload($canvas, $result, 'automatic', $label),
        ]);
    }

    /** @return array{version_id: int, proposal: DesignAiProposal} */
    public function apply(DesignAiProposal $proposal, User $user): array
    {
        $payload = (array) $proposal->payload;

        if (($payload['type'] ?? null) !== 'patch') {
            throw ValidationException::withMessages(['proposal' => 'This suggestion is not a set of changes. Use the resize tool for sizes.']);
        }

        return DB::transaction(function () use ($proposal, $user, $payload): array {
            $locked = DesignAiProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->status !== 'proposed') {
                abort(409, 'This suggestion was already used or dismissed.');
            }

            $project = DesignProject::query()->findOrFail($locked->design_project_id);
            $version = $this->versions->checkpoint($project, $user, 'Before '.(($payload['source'] ?? 'ai') === 'ai' ? 'assistant change' : 'automatic fix'));

            $locked->update(['status' => 'applied']);
            $this->activity->record($user, $project, 'ai.apply', 'ai_proposal', $locked->id, [
                'source' => $payload['source'] ?? 'ai',
                'ops' => count((array) ($payload['ops'] ?? [])),
            ]);

            return ['version_id' => $version->id, 'proposal' => $locked->refresh()];
        });
    }

    public function dismiss(DesignAiProposal $proposal): DesignAiProposal
    {
        if ($proposal->status === 'proposed') {
            $proposal->update(['status' => 'dismissed']);
        }

        return $proposal;
    }

    /** @return array<string, mixed> the shape the browser renders */
    public static function present(DesignAiProposal $proposal): array
    {
        $payload = (array) $proposal->payload;

        return [
            'id' => $proposal->id,
            'mode' => $proposal->mode,
            'status' => $proposal->status,
            'type' => $payload['type'] ?? null,
            'source' => $payload['source'] ?? 'ai',
            'page_id' => $payload['page_id'] ?? null,
            'base_revision' => $payload['base_revision'] ?? null,
            'format_key' => $payload['format_key'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'changes' => $payload['changes'] ?? [],
            'ops' => $payload['ops'] ?? [],
            'notes' => $payload['notes'] ?? [],
        ];
    }
}
