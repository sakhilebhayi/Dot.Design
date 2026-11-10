<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignAsset;
use App\Support\Design\DesignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The asset library: list with search, folder, tag, licence and source filters; folder list; re-file and re-tag.
 * Upload, serve and delete stay in DesignAssetController with all their hardening. Everything here goes through the
 * DesignAsset workspace scope, so a library only ever lists what the viewer may see.
 */
class AssetLibraryController extends StudioController
{
    public function index(Request $request): JsonResponse|View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:80'],
            'tag' => ['nullable', 'string', 'max:30'],
            'license' => ['nullable', Rule::in(DesignAsset::LICENSES)],
            'source' => ['nullable', 'string', 'max:24'],
            'project_id' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = DesignAsset::query()
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters): void {
                // "!" is the escape character on both SQLite and PostgreSQL, so % and _ in the text stay literal.
                $needle = '%'.preg_replace('/([%_!])/', '!$1', (string) $filters['q']).'%';
                $q->whereRaw("lower(name) like lower(?) escape '!'", [$needle]);
            })
            ->when(filled($filters['folder'] ?? null), fn ($q) => $filters['folder'] === '_none' ? $q->whereNull('folder') : $q->where('folder', $filters['folder']))
            ->when(filled($filters['tag'] ?? null), fn ($q) => $q->whereJsonContains('tags', $filters['tag']))
            ->when(filled($filters['license'] ?? null), fn ($q) => $q->where('license', $filters['license']))
            ->when(filled($filters['source'] ?? null), fn ($q) => $q->where('source', $filters['source']))
            ->when(filled($filters['project_id'] ?? null), fn ($q) => $q->where('design_project_id', (int) $filters['project_id']))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 40));

        $items = collect($page->items())->map(fn (DesignAsset $a) => $this->present($a, $request->user()->id))->all();

        if (! $request->expectsJson() && ! $request->is('*.json')) {
            return view('design.assets.index', [
                'assets' => $items,
                'filters' => $filters,
                'folders' => $this->folders(),
                'usage' => DesignAsset::usageFor($request->user()->id),
                'nextCursor' => $page->nextCursor()?->encode(),
            ]);
        }

        return response()->json([
            'assets' => $items,
            'next_cursor' => $page->nextCursor()?->encode(),
            'usage' => DesignAsset::usageFor($request->user()->id),
        ]);
    }

    public function folders(): array
    {
        return DesignAsset::query()
            ->whereNotNull('folder')
            ->select('folder', DB::raw('count(*) as total'))
            ->groupBy('folder')->orderBy('folder')->limit(200)
            ->get()->map(fn ($row) => ['name' => $row->folder, 'count' => (int) $row->total])->all();
    }

    public function foldersJson(): JsonResponse
    {
        return response()->json(['folders' => $this->folders()]);
    }

    public function update(Request $request, DesignAsset $asset): JsonResponse
    {
        $this->authorize('update', $asset);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[\p{L}\p{N} _\-\.&\/]+$/u'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'min:1', 'max:30', 'regex:/^[\p{L}\p{N} _\-]+$/u'],
            'license' => ['sometimes', Rule::in(['own', 'brand'])],
        ]);

        if (($data['license'] ?? $asset->license) === 'brand' && ($data['license'] ?? null) === 'brand'
            && ! in_array(DesignAccess::teamRole($request->user()->id, $asset->team_id), ['owner', 'admin'], true)) {
            return $this->invalid('Only a workspace owner or admin can make an asset a workspace brand asset.', ['license' => ['Not allowed.']], 403);
        }

        if (array_key_exists('tags', $data)) {
            $data['tags'] = collect($data['tags'] ?? [])->map(fn ($t) => trim($t))->filter()->unique()->values()->all() ?: null;
        }

        if (array_key_exists('folder', $data) && $data['folder'] !== null) {
            $data['folder'] = trim($data['folder']) ?: null;
        }

        $asset->update($data);

        return response()->json(['status' => 'updated', 'asset' => $this->present($asset->fresh(), $request->user()->id)]);
    }

    /** @return array<string, mixed> */
    private function present(DesignAsset $a, int $viewerId): array
    {
        return $a->toEditorArray() + [
            'folder' => $a->folder,
            'tags' => $a->tags ?? [],
            'license' => $a->license,
            'source' => $a->source,
            'owned' => (int) $a->user_id === $viewerId,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
