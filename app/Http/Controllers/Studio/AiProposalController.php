<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignAiProposal;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Services\Design\Assistant\ImproveService;
use App\Services\Design\Assistant\OpValidator;
use App\Services\Design\Assistant\ProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Improve an existing page (proposal), store an automatic fix as a proposal, apply and dismiss. */
class AiProposalController extends AiController
{
    /** POST design/projects/{project}/assistant/improve {instruction, page_id, selection?} */
    public function improve(Request $request, DesignProject $project, ImproveService $improve): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'instruction' => ['required', 'string', 'min:3', 'max:'.(int) config('design_ai.input.instruction_max')],
            'page_id' => ['required', 'integer'],
            'selection' => ['nullable', 'array', 'max:100'],
            'selection.*' => ['string', 'max:64'],
        ], [
            'instruction.required' => 'Tell the assistant what to improve.',
            'instruction.max' => 'Keep the request under '.(int) config('design_ai.input.instruction_max').' characters.',
        ]);

        $canvas = $this->pageOf($project, (int) $data['page_id']);

        return $this->guarded(function () use ($request, $improve, $project, $canvas, $data): JsonResponse {
            $result = $improve->propose($request->user(), $project, $canvas, $data['instruction'], array_values((array) ($data['selection'] ?? [])));

            if ($result['status'] !== 'ok') {
                return response()->json(['status' => $result['status'], 'message' => $result['message'] ?? null]);
            }

            return response()->json(['status' => 'ok', 'proposal' => ProposalService::present($result['proposal'])], 201);
        });
    }

    /**
     * POST design/projects/{project}/assistant/proposals {page_id, ops, label, selection?}
     * An automatic fix worked out in the browser. Validated like any proposal; no model call, not counted against the caps.
     */
    public function store(Request $request, DesignProject $project, ProposalService $proposals): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'page_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:120'],
            'ops' => ['required', 'array', 'min:1', 'max:'.(OpValidator::MAX_OPS * 2)],
            'selection' => ['nullable', 'array', 'max:100'],
            'selection.*' => ['string', 'max:64'],
        ]);

        $canvas = $this->pageOf($project, (int) $data['page_id']);
        $proposal = $proposals->storeAutomatic($request->user(), $project, $canvas, array_values($data['ops']), $data['label'], array_values((array) ($data['selection'] ?? [])));

        if ($proposal === null) {
            return response()->json(['status' => 'nothing', 'message' => 'There is nothing to change here, or the change is not allowed.']);
        }

        return response()->json(['status' => 'ok', 'proposal' => ProposalService::present($proposal)], 201);
    }

    /** POST design/ai-proposals/{proposal}/apply: records the decision and snapshots the design first. The browser then applies the operations as one undo step. */
    public function apply(Request $request, DesignAiProposal $proposal, ProposalService $proposals): JsonResponse
    {
        $this->own($request, $proposal);
        $this->authorize('apply', $proposal);

        $result = $proposals->apply($proposal, $request->user());

        return response()->json(['status' => 'applied', 'version_id' => $result['version_id'], 'proposal' => ProposalService::present($result['proposal'])]);
    }

    public function dismiss(Request $request, DesignAiProposal $proposal, ProposalService $proposals): JsonResponse
    {
        $this->own($request, $proposal);
        $this->authorize('dismiss', $proposal);

        return response()->json(['status' => 'dismissed', 'proposal' => ProposalService::present($proposals->dismiss($proposal))]);
    }

    private function own(Request $request, DesignAiProposal $proposal): void
    {
        abort_unless((int) $proposal->user_id === (int) $request->user()->id, 404);
    }

    private function pageOf(DesignProject $project, int $pageId): DesignCanvas
    {
        $canvas = DesignCanvas::query()->whereKey($pageId)->where('design_project_id', $project->id)->first();
        abort_if($canvas === null, 404);

        return $canvas;
    }
}
