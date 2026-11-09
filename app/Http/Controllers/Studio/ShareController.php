<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Models\DesignShare;
use App\Services\Design\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShareController extends StudioController
{
    public function __construct(private readonly ShareService $shares) {}

    public function index(DesignProject $project): JsonResponse
    {
        $this->authorize('viewAny', [DesignShare::class, $project]);

        $rows = DesignShare::where('design_project_id', $project->id)->orderByDesc('id')->limit(100)->get();

        return response()->json(['shares' => $rows->map(fn (DesignShare $s) => $this->shares->present($s))->all()]);
    }

    /** Creates a link. The URL (which carries the token) is in this response only and can never be retrieved again. */
    public function store(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('create', [DesignShare::class, $project]);

        $maxDays = (int) config('design.studio.shares.max_expiry_days', 365);
        $data = $request->validate([
            'permission' => ['required', Rule::in(DesignShare::PERMISSIONS)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.$maxDays],
            'download_allowed' => ['sometimes', 'boolean'],
        ]);

        if ($this->shares->activeCount($project) >= (int) config('design.studio.shares.max_active_per_project', 20)) {
            return $this->invalid('This project already has the maximum number of active links. Revoke one first.', ['permission' => ['Too many active links.']], 409);
        }

        $result = $this->shares->create(
            $project,
            $request->user(),
            $data['permission'],
            isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            (bool) ($data['download_allowed'] ?? false),
        );

        return response()->json([
            'status' => 'created',
            'share' => $this->shares->present($result['share']),
            'url' => route('studio.share.show', ['token' => $result['token']]),
            'note' => 'Copy this link now. For security it is shown only once.',
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, DesignProject $project, DesignShare $share): JsonResponse
    {
        $this->childOf($project, $share);
        $this->authorize('revoke', $share);

        $this->shares->revoke($share, $request->user());

        return response()->json(['status' => 'revoked', 'share' => $this->shares->present($share->fresh())]);
    }
}
