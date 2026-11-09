<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Services\Design\ReviewRefused;
use App\Services\Design\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends StudioController
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(DesignProject $project): JsonResponse
    {
        $this->authorize('viewAny', [DesignReview::class, $project]);

        $rows = DesignReview::where('design_project_id', $project->id)
            ->with(['requester:id,name', 'reviewer:id,name', 'version:id,kind,label,created_at'])
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['reviews' => $rows->map(fn (DesignReview $r) => $this->present($r))->all()]);
    }

    /** Reviews assigned to me (default) or requested by me. */
    public function inbox(Request $request): JsonResponse
    {
        $data = $request->validate([
            'box' => ['sometimes', Rule::in(['assigned', 'requested'])],
            'status' => ['sometimes', 'nullable', 'string', 'max:24'],
        ]);

        $rows = $this->reviews->inbox($request->user(), $data['box'] ?? 'assigned', $data['status'] ?? null);

        return response()->json(['reviews' => $rows->map(fn (DesignReview $r) => $this->present($r, true))->all()]);
    }

    public function store(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('request', [DesignReview::class, $project]);

        $data = $request->validate([
            'reviewer_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->run(fn () => response()->json([
            'status' => 'requested',
            'review' => $this->present($this->load($this->reviews->request($project, $request->user(), (int) $data['reviewer_id'], $data['note'] ?? null))),
        ], 201));
    }

    public function approve(Request $request, DesignProject $project, DesignReview $review): JsonResponse
    {
        $this->childOf($project, $review);
        $this->authorize('decide', $review);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->run(fn () => response()->json([
            'status' => 'approved',
            'review' => $this->present($this->load($this->reviews->approve($review, $request->user(), $data['note'] ?? null))),
        ]));
    }

    public function requestChanges(Request $request, DesignProject $project, DesignReview $review): JsonResponse
    {
        $this->childOf($project, $review);
        $this->authorize('decide', $review);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->run(fn () => response()->json([
            'status' => 'changes_requested',
            'review' => $this->present($this->load($this->reviews->requestChanges($review, $request->user(), $data['note'] ?? null))),
        ]));
    }

    public function cancel(Request $request, DesignProject $project, DesignReview $review): JsonResponse
    {
        $this->childOf($project, $review);
        $this->authorize('cancel', $review);

        return $this->run(fn () => response()->json([
            'status' => 'cancelled',
            'review' => $this->present($this->load($this->reviews->cancel($review, $request->user()))),
        ]));
    }

    /** Turns a refused review action into the right HTTP answer. */
    private function run(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ReviewRefused $e) {
            return $this->invalid($e->getMessage(), $e->field ? [$e->field => [$e->getMessage()]] : [], $e->httpStatus);
        }
    }

    private function load(DesignReview $review): DesignReview
    {
        return $review->load(['requester:id,name', 'reviewer:id,name', 'version:id,kind,label,created_at']);
    }

    /** @return array<string, mixed> */
    private function present(DesignReview $r, bool $withProject = false): array
    {
        $out = [
            'id' => $r->id,
            'project_id' => $r->design_project_id,
            'status' => $r->status->value,
            'note' => $r->note,
            'decision_note' => $r->decision_note,
            'requester' => $r->requester ? ['id' => $r->requester->id, 'name' => $r->requester->name] : null,
            'reviewer' => $r->reviewer ? ['id' => $r->reviewer->id, 'name' => $r->reviewer->name] : null,
            'version' => $r->version ? ['id' => $r->version->id, 'kind' => $r->version->kind, 'label' => $r->version->label] : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
        ];

        if ($withProject) {
            $out['project'] = $r->project ? ['id' => $r->project->id, 'name' => $r->project->name, 'status' => $r->project->status] : null;
        }

        return $out;
    }
}
