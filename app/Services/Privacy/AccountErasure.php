<?php

namespace App\Services\Privacy;

use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\Design\ProjectAssets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * What happens to a person's work when they delete their account (POPIA: a person can ask for their personal information
 * to be deleted; a business keeps the records it made in the course of its work).
 *
 * Database foreign keys already remove or null most rows; this class does what keys cannot and decides what belongs to
 * whom:
 *
 *  - REFUSED while the person owns a workspace that still has other people in it: removing the owner would delete or
 *    orphan the whole business's designs. They remove the others, or delete the workspace, first.
 *  - TRANSFERRED to the workspace owner when the workspace goes on without them: designs shared with the workspace
 *    (visibility "team"), the pictures those designs use, workspace brand assets, brand kits and workspace templates.
 *  - DELETED, with their files on disk: their private designs, personal library pictures, personal templates, project
 *    thumbnails, settings, folders, AI proposals and generation logs, grants, sessions and invitations to their address.
 *  - ANONYMISED: comments they wrote stay (they are part of the design's conversation) under the name "Former member";
 *    audit rows about their actions lose the person's id; reviews keep their outcome without the name.
 *  - REVOKED: share links they created stop working.
 *
 * Files are removed only after the database transaction commits, so a failed deletion never leaves rows pointing at
 * nothing.
 */
class AccountErasure
{
    public const FORMER_MEMBER = 'Former member';

    public function __construct(private readonly ProjectAssets $assets) {}

    /**
     * Workspaces this person owns that still have other members: their names, empty when nothing blocks deletion.
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        return Team::query()
            ->where('user_id', $user->id)
            ->where('personal_team', false)
            ->get()
            ->filter(fn (Team $team) => DB::table('team_user')->where('team_id', $team->id)->where('user_id', '!=', $user->id)->exists())
            ->map(fn (Team $team) => $team->name)
            ->values()
            ->all();
    }

    /**
     * Run inside the caller's transaction, before the user row and their owned workspaces are deleted.
     *
     * @throws ValidationException
     */
    public function prepare(User $user): void
    {
        $blockers = $this->blockers($user);

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'password' => 'You own '.(count($blockers) === 1 ? 'a workspace' : 'workspaces').' with other people in '.(count($blockers) === 1 ? 'it' : 'them').' ('.implode(', ', $blockers).'). Remove the other members or delete the workspace first, so nobody loses their designs by accident.',
            ]);
        }

        $filesToDelete = [];

        // Workspaces that carry on without this person: they are members, not owners.
        $continuing = Team::query()
            ->whereIn('id', DB::table('team_user')->where('user_id', $user->id)->select('team_id'))
            ->where('user_id', '!=', $user->id)
            ->get()
            ->keyBy('id');

        $this->handleProjects($user, $continuing, $filesToDelete);
        $this->handleAssets($user, $continuing, $filesToDelete);
        $this->handleBrandAndTemplates($user, $continuing);
        $this->anonymise($user);

        DB::afterCommit(function () use ($filesToDelete): void {
            foreach ($filesToDelete as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * @param  Collection<int, Team>  $continuing
     * @param  list<array{string, string}>  $files
     */
    private function handleProjects(User $user, $continuing, array &$files): void
    {
        $transferred = [];

        foreach (DesignProject::withoutGlobalScopes()->where('user_id', $user->id)->get() as $project) {
            $team = $project->team_id === null ? null : $continuing->get($project->team_id);

            if ($team !== null && $project->visibility === 'team') {
                $project->forceFill(['user_id' => $team->user_id])->save();
                $transferred[] = $project->id;

                continue;
            }

            if ($project->thumbnail_path !== null) {
                $files[] = [(string) config('design.assets.disk'), (string) $project->thumbnail_path];
            }

            $project->delete();
        }

        // Pictures filed under a transferred project follow it.
        if ($transferred !== []) {
            foreach ($continuing as $team) {
                DesignAsset::withoutGlobalScopes()->where('user_id', $user->id)->whereIn('design_project_id', $transferred)
                    ->where('team_id', $team->id)->update(['user_id' => $team->user_id]);
            }
        }
    }

    /**
     * @param  Collection<int, Team>  $continuing
     * @param  list<array{string, string}>  $files
     */
    private function handleAssets(User $user, $continuing, array &$files): void
    {
        // Every picture any surviving design of a continuing workspace uses stays, whoever uploaded it.
        $inUse = [];

        foreach ($continuing as $team) {
            DesignProject::withoutGlobalScopes()->where('team_id', $team->id)->where('user_id', '!=', $user->id)->each(function (DesignProject $project) use (&$inUse): void {
                foreach ($this->assets->referencedIds($project) as $id) {
                    $inUse[$id] = true;
                }
            });
        }

        $kitAssetIds = BrandAsset::query()
            ->whereIn('brand_kit_id', BrandKit::withoutGlobalScopes()->whereIn('team_id', $continuing->keys())->select('id'))
            ->pluck('design_asset_id')->flip()->all();

        foreach (DesignAsset::withoutGlobalScopes()->where('user_id', $user->id)->get() as $asset) {
            $team = $asset->team_id === null ? null : $continuing->get($asset->team_id);
            $keep = $team !== null && ($asset->license === 'brand' || isset($inUse[$asset->id]) || isset($kitAssetIds[$asset->id]));

            if ($keep) {
                $asset->forceFill(['user_id' => $team->user_id])->save();

                continue;
            }

            if ($asset->isStoredUpload() && $asset->file_path !== null && ! $this->sharedFile($asset)) {
                $files[] = [(string) $asset->disk, ltrim((string) $asset->file_path, '/')];
            }

            $asset->delete();
        }
    }

    /** Another row (another person's identical upload cannot exist, but a transferred row can) still points at the file. */
    private function sharedFile(DesignAsset $asset): bool
    {
        return DesignAsset::withoutGlobalScopes()->where('disk', $asset->disk)->where('file_path', $asset->file_path)->where('id', '!=', $asset->id)->exists();
    }

    /** @param Collection<int, Team> $continuing */
    private function handleBrandAndTemplates(User $user, $continuing): void
    {
        foreach ($continuing as $team) {
            BrandKit::withoutGlobalScopes()->where('user_id', $user->id)->where('team_id', $team->id)->update(['user_id' => $team->user_id]);
            DesignTemplate::withoutGlobalScopes()->where('user_id', $user->id)->where('source', 'team')->where('team_id', $team->id)->update(['user_id' => $team->user_id]);
        }

        // What is left is personal: a kit with no workspace cascades with the user; templates saved "for me" are removed here
        // because their foreign key would only blank the owner and leave a row nobody can see.
        DesignTemplate::withoutGlobalScopes()->where('user_id', $user->id)->delete();
    }

    private function anonymise(User $user): void
    {
        // Comments stay in the conversation under a neutral name; the user_id is blanked by the foreign key.
        DB::table('design_comments')->where('user_id', $user->id)->update(['guest_name' => self::FORMER_MEMBER]);
        DB::table('design_comments')->where('resolved_by', $user->id)->update(['resolved_by' => null]);

        // Audit rows keep what happened and when, not who.
        DB::table('design_activity')->where('user_id', $user->id)->update(['user_id' => null]);

        // A review that was waiting for this person can no longer be answered.
        DB::table('design_reviews')->where('reviewer_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);

        // Links they made stop working with them.
        DB::table('design_shares')->where('created_by', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        // Access rows, sessions, and anything addressed to their email.
        DB::table('design_project_grants')->where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('team_invitations')->whereRaw('lower(email) = ?', [strtolower((string) $user->email)])->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('ai_generation_logs')->where('user_id', $user->id)->delete();
        DB::table('design_ai_proposals')->where('user_id', $user->id)->delete();
    }
}
