<?php

namespace App\Services\Design;

use App\Events\DesignCommentAdded;
use App\Events\DesignShared;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\DesignShare;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Share links. The token is 40 random characters, shown once and stored only as its sha256, so a database leak does
 * not leak working links. A link can view or view-and-comment, can expire, and can be revoked at any moment.
 *
 * Lookup answers one thing for every failure (unknown, malformed, revoked, expired): null. Callers must render the
 * same 404 for all of them so a visitor cannot tell a revoked link from one that never existed.
 */
class ShareService
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{40}$/D';

    public function __construct(
        private readonly ActivityLog $activity,
        private readonly DocumentSanitiser $sanitiser,
        private readonly ProjectAssets $assets,
    ) {}

    /**
     * @return array{share: DesignShare, token: string}
     */
    public function create(DesignProject $project, User $by, string $permission, ?CarbonInterface $expiresAt, bool $downloadAllowed): array
    {
        $token = Str::random(40);

        $share = DB::transaction(function () use ($project, $by, $permission, $expiresAt, $downloadAllowed, $token): DesignShare {
            $share = DesignShare::create([
                'design_project_id' => $project->id,
                'created_by' => $by->id,
                'token_hash' => hash('sha256', $token),
                'permission' => $permission,
                'expires_at' => $expiresAt,
                'download_allowed' => $downloadAllowed,
            ]);

            $this->activity->record($by, $project, 'share.created', 'share', $share->id, [
                'permission' => $permission,
                'expires_at' => $expiresAt?->toIso8601String(),
                'download_allowed' => $downloadAllowed,
            ]);

            return $share;
        });

        DesignShared::dispatch($share, $project, $by);

        return ['share' => $share, 'token' => $token];
    }

    public function revoke(DesignShare $share, User $by): DesignShare
    {
        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
            $this->activity->record($by, $share->project, 'share.revoked', 'share', $share->id);
        }

        return $share;
    }

    public function activeCount(DesignProject $project): int
    {
        return DesignShare::where('design_project_id', $project->id)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    /** The live share behind a token, or null for any reason it cannot be used. */
    public function findActive(string $token): ?DesignShare
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $share = DesignShare::where('token_hash', $hash)->first();

        if ($share === null || ! hash_equals((string) $share->token_hash, $hash) || ! $share->isActive()) {
            return null;
        }

        $project = DesignProject::withoutGlobalScopes()->find($share->design_project_id);

        if ($project === null) {
            return null;
        }

        $share->setRelation('project', $project);

        return $share;
    }

    /** Count a view without keeping who looked: a counter and a timestamp, nothing else. */
    public function recordView(DesignShare $share): void
    {
        DesignShare::whereKey($share->id)->update(['view_count' => DB::raw('view_count + 1'), 'last_viewed_at' => now()]);
    }

    /**
     * The read-only document a share page embeds: pages in order, clean elements, and short-lived signed URLs for the
     * pictures actually used. Nothing about the owner, the workspace or other pages' history.
     *
     * @return array<string, mixed>
     */
    public function publicDocument(DesignShare $share, string $token): array
    {
        $project = $share->project;
        $pages = DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->get();
        $assetIds = [];

        $out = $pages->map(function (DesignCanvas $page) use ($project, &$assetIds): array {
            $read = $this->sanitiser->readElements($page->elements);
            $this->collectAssetIds($read['elements'], $assetIds);
            $background = $page->background ?: ['type' => 'solid', 'color' => $page->background_color ?: '#ffffff', 'gradient' => null, 'asset_id' => null, 'fit' => 'cover'];

            if (($background['asset_id'] ?? null) !== null) {
                $assetIds[(int) $background['asset_id']] = true;
            }

            return [
                'id' => $page->id,
                'number' => (int) $page->page_number,
                'name' => $page->name,
                'width' => $page->width ?? $project->width,
                'height' => $page->height ?? $project->height,
                'background' => $background,
                'duration_ms' => $page->duration_ms ?? 5000,
                'transition' => $page->transition ?? 'none',
                'elements' => $read['elements'],
            ];
        })->all();

        return [
            'schema_version' => DocumentSchema::shared()->version(),
            'project' => ['name' => $project->name, 'width' => $project->width, 'height' => $project->height, 'unit' => $project->unit],
            'pages' => $out,
            'assets' => $this->publicAssets($project, array_keys($assetIds), $token),
            'permission' => $share->permission,
            'download_allowed' => (bool) $share->download_allowed,
        ];
    }

    /**
     * The ids come from the project's own pages, so every picture here is already part of the project.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function publicAssets(DesignProject $project, array $ids, string $token): array
    {
        if ($ids === []) {
            return [];
        }

        $minutes = (int) config('design.studio.shares.asset_url_minutes', 10);
        $out = [];

        foreach (DesignAsset::withoutGlobalScopes()->whereIn('id', $ids)->get() as $asset) {
            $out[$asset->id] = [
                'id' => $asset->id,
                'width' => $asset->width,
                'height' => $asset->height,
                'placeholder' => $asset->isPlaceholder(),
                'url' => $asset->isStoredUpload()
                    ? URL::temporarySignedRoute('studio.share.asset', now()->addMinutes($minutes), ['token' => $token, 'asset' => $asset->id])
                    : null,
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $elements @param array<int, true> $ids */
    private function collectAssetIds(array $elements, array &$ids): void
    {
        foreach ($elements as $element) {
            if (($element['type'] ?? null) === 'image' && ($element['asset_id'] ?? null) !== null) {
                $ids[(int) $element['asset_id']] = true;
            }

            if (isset($element['children'])) {
                $this->collectAssetIds($element['children'], $ids);
            }
        }
    }

    /**
     * A comment from someone who has only the link: a display name, never an address.
     *
     * @param  array<string, mixed>  $data  body, guest_name, page_id?, element_id?, x?, y?, parent_id?
     */
    public function addGuestComment(DesignShare $share, array $data): DesignComment
    {
        $comment = DesignComment::create([
            'design_project_id' => $share->design_project_id,
            'share_id' => $share->id,
            'guest_name' => $data['guest_name'],
            'body' => $data['body'],
            'page_id' => $data['page_id'] ?? null,
            'element_id' => $data['element_id'] ?? null,
            'x' => $data['x'] ?? null,
            'y' => $data['y'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        $this->activity->record(null, $share->project, 'comment.guest_added', 'comment', $comment->id, ['share_id' => $share->id, 'guest_name' => $comment->guest_name], $share->project->team_id);

        DesignCommentAdded::dispatch($comment, $share->project);

        return $comment;
    }

    /** What the owner sees in the share list: never the token or its hash. @return array<string, mixed> */
    public function present(DesignShare $share): array
    {
        return [
            'id' => $share->id,
            'permission' => $share->permission,
            'status' => $share->status(),
            'download_allowed' => (bool) $share->download_allowed,
            'expires_at' => $share->expires_at?->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
            'view_count' => (int) $share->view_count,
            'created_at' => $share->created_at?->toIso8601String(),
            'created_by' => $share->created_by,
        ];
    }
}
