<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Models\DesignVersion;
use App\Services\Design\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VersionController extends StudioController
{
    public function __construct(private readonly VersionService $versions) {}

    public function index(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('viewAny', [DesignVersion::class, $project]);

        $kind = $request->query('kind');

        $page = DesignVersion::query()
            ->where('design_project_id', $project->id)
            ->when(in_array($kind, DesignVersion::KINDS, true), fn ($q) => $q->where('kind', $kind))
            ->with('user:id,name')
            ->orderByDesc('id')
            ->select(['id', 'design_project_id', 'user_id', 'kind', 'label', 'bytes', 'created_at'])
            ->cursorPaginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json([
            'versions' => collect($page->items())->map(fn (DesignVersion $v) => $this->present($v))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('create', [DesignVersion::class, $project]);

        $data = $request->validate(['label' => ['required', 'string', 'min:1', 'max:'.(int) config('design.studio.versions.max_label_length', 80)]]);

        $version = $this->versions->snapshot($project, 'manual', $request->user(), $data['label']);

        return response()->json(['status' => 'created', 'version' => $this->present($version->load('user:id,name'))], 201);
    }

    public function show(DesignProject $project, DesignVersion $version): JsonResponse
    {
        $this->childOf($project, $version);
        $this->authorize('view', $version);

        return response()->json(['version' => $this->present($version->load('user:id,name')) + ['pages' => $version->pages]]);
    }

    public function diff(Request $request, DesignProject $project, DesignVersion $version): JsonResponse
    {
        $this->childOf($project, $version);
        $this->authorize('view', $version);

        $against = $request->query('against', 'current');
        $other = null;

        if ($against !== 'current') {
            $other = DesignVersion::whereKey((int) $against)->where('design_project_id', $project->id)->first();
            abort_if($other === null, 404);
        }

        return response()->json([
            'from' => $version->id,
            'to' => $other?->id ?? 'current',
            'diff' => $this->versions->diff($project, $version, $other),
        ]);
    }

    public function restore(Request $request, DesignProject $project, DesignVersion $version): JsonResponse
    {
        $this->childOf($project, $version);
        $this->authorize('restore', $version);

        $before = $this->versions->restore($version, $request->user());

        return response()->json([
            'status' => 'restored',
            'restored_version' => $version->id,
            'pre_restore_version' => $before->id,
            'message' => 'Restored. The way it was a moment ago is saved in the history, so you can go back.',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(DesignVersion $v): array
    {
        return [
            'id' => $v->id,
            'kind' => $v->kind,
            'label' => $v->label,
            'bytes' => (int) $v->bytes,
            'created_at' => $v->created_at?->toIso8601String(),
            'author' => $v->user ? ['id' => $v->user->id, 'name' => $v->user->name] : null,
        ];
    }
}
