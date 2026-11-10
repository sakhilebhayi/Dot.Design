<?php

namespace App\Http\Controllers\Studio;

use App\Events\AssetDeleted;
use App\Models\DesignAsset;
use App\Models\DesignAssetFolder;
use App\Services\Assets\AssetUsageIndex;
use App\Support\Design\DesignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The asset library behind the Assets screen: a richer list (type, used or unused, sort), folders you can keep empty, tags you
 * can rename, bulk actions and where a picture is used. Upload, serve and the single delete stay in DesignAssetController; the
 * rename and re-file of one asset stays in AssetLibraryController. Everything is read through the DesignAsset workspace scope, and
 * every change is limited to what the policies allow (your own pictures for filing and tagging).
 */
class AssetStudioController extends StudioController
{
    private const TYPES = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    private const MAX_FOLDERS = 100;

    public function library(Request $request, AssetUsageIndex $usage): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'folder' => ['nullable', 'string', 'max:80'],
            'tag' => ['nullable', 'string', 'max:30'],
            'used' => ['nullable', Rule::in(['used', 'unused'])],
            'license' => ['nullable', Rule::in(DesignAsset::LICENSES)],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name', 'size'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DesignAsset::query()
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters): void {
                // "!" is the escape character on SQLite and PostgreSQL, so % and _ in the text stay literal.
                $needle = '%'.preg_replace('/([%_!])/', '!$1', (string) $filters['q']).'%';
                $q->whereRaw("lower(name) like lower(?) escape '!'", [$needle]);
            })
            ->when(filled($filters['type'] ?? null), fn ($q) => $q->where('mime_type', self::TYPES[$filters['type']]))
            ->when(filled($filters['folder'] ?? null), fn ($q) => $filters['folder'] === '_none' ? $q->whereNull('folder') : $q->where('folder', $filters['folder']))
            ->when(filled($filters['tag'] ?? null), fn ($q) => $q->whereJsonContains('tags', $filters['tag']))
            ->when(filled($filters['license'] ?? null), fn ($q) => $q->where('license', $filters['license']))
            ->when(($filters['used'] ?? null) === 'used', fn ($q) => $q->whereIn('id', $usage->usedIds()))
            ->when(($filters['used'] ?? null) === 'unused', fn ($q) => $q->whereNotIn('id', $usage->usedIds()));

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderByRaw('lower(name)')->orderBy('id'),
            'size' => $query->orderByDesc('file_size')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $total = (clone $query)->toBase()->getCountForPagination();
        $page = $query->cursorPaginate((int) ($filters['per_page'] ?? 36));

        return response()->json([
            'assets' => collect($page->items())->map(fn (DesignAsset $a) => $this->present($a, $request->user()->id, $usage))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'total' => $total,
        ]);
    }

    /** Folders (with the empty ones you made), tags, the storage meter and the upload limits: everything the sidebar shows. */
    public function meta(Request $request): JsonResponse
    {
        return response()->json($this->metaPayload($request));
    }

    public function usage(Request $request, DesignAsset $asset, AssetUsageIndex $usage): JsonResponse
    {
        $this->authorize('view', $asset);

        $designs = collect($usage->designsFor($asset->id))->map(fn (array $row) => $row + ['url' => route('design.projects.show', $row['project_id'])])->all();
        $visiblePages = array_sum(array_map(fn (array $d) => count($d['pages']), $designs));
        $everywhere = $asset->placementsIncludingHidden($visiblePages);

        return response()->json([
            'asset_id' => $asset->id,
            'designs' => $designs,
            'hidden' => $everywhere['hidden'],
            'in_use' => $visiblePages > 0 || $everywhere['hidden'] > 0,
        ]);
    }

    // ---- folders -----------------------------------------------------------------------------------------------

    public function createFolder(Request $request): JsonResponse
    {
        $name = $this->folderName($request, 'name');
        $userId = $request->user()->id;

        if (DesignAssetFolder::query()->where('user_id', $userId)->count() >= self::MAX_FOLDERS && ! DesignAssetFolder::query()->where('user_id', $userId)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'You have reached the limit of '.self::MAX_FOLDERS.' folders. Remove one you no longer use.']);
        }

        DesignAssetFolder::query()->firstOrCreate(['user_id' => $userId, 'name' => $name]);

        return response()->json(['status' => 'created', 'name' => $name] + $this->metaPayload($request), 201);
    }

    public function renameFolder(Request $request): JsonResponse
    {
        $from = $this->folderName($request, 'from');
        $to = $this->folderName($request, 'to');
        $userId = $request->user()->id;

        DB::transaction(function () use ($userId, $from, $to): void {
            DesignAsset::query()->where('user_id', $userId)->where('folder', $from)->update(['folder' => $to]);

            $row = DesignAssetFolder::query()->where('user_id', $userId)->where('name', $from)->first();
            $existing = DesignAssetFolder::query()->where('user_id', $userId)->where('name', $to)->exists();

            if ($row !== null && $existing) {
                $row->delete();
            } elseif ($row !== null) {
                $row->update(['name' => $to]);
            } elseif (! $existing) {
                DesignAssetFolder::query()->create(['user_id' => $userId, 'name' => $to]);
            }
        });

        return response()->json(['status' => 'renamed', 'name' => $to] + $this->metaPayload($request));
    }

    /** Deleting a folder never deletes pictures: they move to "No folder". */
    public function deleteFolder(Request $request): JsonResponse
    {
        $name = $this->folderName($request, 'name');
        $userId = $request->user()->id;

        $moved = DB::transaction(function () use ($userId, $name): int {
            DesignAssetFolder::query()->where('user_id', $userId)->where('name', $name)->delete();

            return DesignAsset::query()->where('user_id', $userId)->where('folder', $name)->update(['folder' => null]);
        });

        return response()->json(['status' => 'deleted', 'moved' => $moved] + $this->metaPayload($request));
    }

    // ---- tags --------------------------------------------------------------------------------------------------

    public function renameTag(Request $request): JsonResponse
    {
        $from = $this->tagName($request, 'from');
        $to = $this->tagName($request, 'to');

        $count = $this->retag($request->user()->id, $from, fn (array $tags) => array_values(array_unique(array_map(fn ($t) => $t === $from ? $to : $t, $tags))));

        return response()->json(['status' => 'renamed', 'changed' => $count] + $this->metaPayload($request));
    }

    public function deleteTag(Request $request): JsonResponse
    {
        $name = $this->tagName($request, 'name');
        $count = $this->retag($request->user()->id, $name, fn (array $tags) => array_values(array_filter($tags, fn ($t) => $t !== $name)));

        return response()->json(['status' => 'deleted', 'changed' => $count] + $this->metaPayload($request));
    }

    // ---- bulk --------------------------------------------------------------------------------------------------

    /** One result per picture, so a bulk action that partly fails says exactly which ones and why. */
    public function bulk(Request $request, AssetUsageIndex $usage): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
            'action' => ['required', Rule::in(['move', 'tag_add', 'tag_remove', 'delete'])],
            'folder' => ['nullable', 'string', 'max:80', 'regex:'.DesignAssetFolder::NAME_PATTERN],
            'tag' => ['nullable', 'string', 'min:1', 'max:30', 'regex:/^[\p{L}\p{N} _\-]+$/u'],
        ]);

        if (in_array($data['action'], ['tag_add', 'tag_remove'], true) && blank($data['tag'] ?? null)) {
            throw ValidationException::withMessages(['tag' => 'Type a tag first.']);
        }

        $user = $request->user();
        $folder = isset($data['folder']) ? (trim($data['folder']) ?: null) : null;
        $tag = isset($data['tag']) ? trim($data['tag']) : null;
        $assets = DesignAsset::query()->whereIn('id', $data['ids'])->get()->keyBy('id');
        $results = [];

        foreach (array_unique($data['ids']) as $id) {
            $asset = $assets->get($id);

            if ($asset === null) {
                $results[] = ['id' => $id, 'ok' => false, 'message' => 'This picture could not be found. It may already be deleted.'];

                continue;
            }

            $results[] = ['id' => $id, 'name' => $asset->name] + $this->bulkOne($user, $asset, $data['action'], $folder, $tag, $usage);
        }

        if ($data['action'] === 'move' && $folder !== null && collect($results)->contains('ok', true)) {
            DesignAssetFolder::query()->firstOrCreate(['user_id' => $user->id, 'name' => $folder]);
        }

        return response()->json([
            'results' => $results,
            'done' => collect($results)->where('ok', true)->count(),
            'failed' => collect($results)->where('ok', false)->count(),
        ] + $this->metaPayload($request));
    }

    /** @return array{ok: bool, message?: string} */
    private function bulkOne($user, DesignAsset $asset, string $action, ?string $folder, ?string $tag, AssetUsageIndex $usage): array
    {
        if ($action === 'delete') {
            if (! $user->can('delete', $asset)) {
                return ['ok' => false, 'message' => 'You cannot delete this picture. Only the person who uploaded it, or a workspace admin, can.'];
            }

            $placed = $usage->designsFor($asset->id);
            $hidden = $asset->placementsIncludingHidden()['hidden'];

            if ($placed !== [] || $hidden > 0) {
                $where = $placed !== [] ? $placed[0]['project'].', page '.$placed[0]['pages'][0] : 'a design in your workspace';

                return ['ok' => false, 'message' => "It is placed on {$where}. Remove it from the design first."];
            }

            $assetId = $asset->id;
            $assetName = $asset->name;
            $ownerId = $asset->user_id;
            $asset->delete();

            try {
                $asset->deleteStoredFile();
            } catch (\Throwable $e) {
                report($e);
            }

            event(new AssetDeleted($assetId, $assetName, $ownerId));

            return ['ok' => true];
        }

        if (! $user->can('update', $asset)) {
            return ['ok' => false, 'message' => 'Only the person who uploaded a picture can file or tag it.'];
        }

        if ($action === 'move') {
            $asset->update(['folder' => $folder]);

            return ['ok' => true];
        }

        $tags = collect((array) $asset->tags);
        $tags = $action === 'tag_add' ? $tags->push($tag)->unique() : $tags->reject(fn ($t) => $t === $tag);

        if ($tags->count() > 20) {
            return ['ok' => false, 'message' => 'A picture can have up to 20 tags.'];
        }

        $asset->update(['tags' => $tags->values()->all() ?: null]);

        return ['ok' => true];
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    private function folderName(Request $request, string $field): string
    {
        $data = $request->validate([$field => ['required', 'string', 'min:1', 'max:80', 'regex:'.DesignAssetFolder::NAME_PATTERN]], [
            "$field.required" => 'Give the folder a name.',
            "$field.regex" => 'Use letters, numbers, spaces and - _ . & / in a folder name.',
            "$field.max" => 'A folder name can be up to 80 characters.',
        ]);

        $name = trim($data[$field]);

        if ($name === '') {
            throw ValidationException::withMessages([$field => 'Give the folder a name.']);
        }

        return $name;
    }

    private function tagName(Request $request, string $field): string
    {
        $data = $request->validate([$field => ['required', 'string', 'min:1', 'max:30', 'regex:/^[\p{L}\p{N} _\-]+$/u']], [
            "$field.regex" => 'Use letters, numbers, spaces and - _ in a tag.',
            "$field.max" => 'A tag can be up to 30 characters.',
        ]);

        return trim($data[$field]);
    }

    /** @param callable(list<string>): list<string> $change */
    private function retag(int $userId, string $needle, callable $change): int
    {
        $count = 0;

        DesignAsset::query()->where('user_id', $userId)->whereJsonContains('tags', $needle)->get()->each(function (DesignAsset $asset) use ($change, &$count): void {
            $tags = $change((array) $asset->tags);
            $asset->update(['tags' => $tags === [] ? null : $tags]);
            $count++;
        });

        return $count;
    }

    /** @return array<string, mixed> */
    private function metaPayload(Request $request): array
    {
        $userId = $request->user()->id;

        $counts = DesignAsset::query()->whereNotNull('folder')->select('folder', DB::raw('count(*) as total'))->groupBy('folder')->pluck('total', 'folder');
        $own = DesignAssetFolder::query()->where('user_id', $userId)->pluck('name');
        $folders = $counts->keys()->merge($own)->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()
            ->map(fn (string $name) => ['name' => $name, 'count' => (int) ($counts[$name] ?? 0), 'own' => $own->contains($name)])->all();

        $tags = [];
        DesignAsset::query()->whereNotNull('tags')->limit(3000)->pluck('tags')->each(function ($list) use (&$tags): void {
            foreach ((array) $list as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        });
        ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'folders' => $folders,
            'tags' => collect($tags)->map(fn ($count, $name) => ['name' => (string) $name, 'count' => $count])->values()->all(),
            'usage' => DesignAsset::usageFor($userId),
            'unfiled' => DesignAsset::query()->whereNull('folder')->count(),
            'total' => DesignAsset::query()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(DesignAsset $a, int $viewerId, AssetUsageIndex $usage): array
    {
        return $a->toEditorArray() + [
            'folder' => $a->folder,
            'tags' => $a->tags ?? [],
            'license' => $a->license,
            'source' => $a->source,
            'type' => match ($a->mime_type) {
                'image/png' => 'PNG',
                'image/jpeg' => 'JPEG',
                'image/webp' => 'WebP',
                default => 'Picture',
            },
            'owned' => (int) $a->user_id === $viewerId,
            'used_in' => $usage->countFor($a->id),
            'created_at' => $a->created_at?->toIso8601String(),
            'can' => [
                'update' => (int) $a->user_id === $viewerId,
                'delete' => DesignAccess::canDeleteAsset(request()->user(), $a),
            ],
        ];
    }
}
