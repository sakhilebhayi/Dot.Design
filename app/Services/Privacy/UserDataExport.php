<?php

namespace App\Services\Privacy;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Everything Dot.Design holds about one person, as one JSON-friendly array (POPIA: a person may ask what personal information
 * is held about them). Built without the signed-in workspace scopes, so the same answer comes out of the artisan command for
 * the owner handling a request and out of the self-service download.
 *
 * Left out on purpose: the password hash, two-factor secrets and recovery codes, session cookies, and the tokens of share links
 * (only a hash is stored, so there is nothing to hand over). Other people's words are included only where this person wrote
 * them or was the subject (their own comments, reviews they requested or were asked to give).
 */
class UserDataExport
{
    /** @return array<string, mixed> */
    public function build(User $user, bool $withDocuments = true): array
    {
        $projects = DesignProject::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'about' => 'A copy of the personal information Dot.Design holds about this person. Documents are the designs they created.',
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_secret !== null,
                'has_profile_photo' => $user->profile_photo_path !== null,
                'current_workspace_id' => $user->current_team_id,
            ],
            'workspaces' => $this->workspaces($user),
            'studio_settings' => DB::table('design_user_settings')->where('user_id', $user->id)->first(['mode', 'snap', 'grid', 'rulers', 'reduced_motion_pref', 'last_format_key', 'onboarded_at']),
            'designs' => $projects->map(fn (DesignProject $p) => $this->project($p, $withDocuments))->all(),
            'pictures' => DesignAsset::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (DesignAsset $a) => ['id' => $a->id, 'name' => $a->name, 'folder' => $a->folder, 'tags' => $a->tags, 'license' => $a->license, 'bytes' => $a->file_size, 'width' => $a->width, 'height' => $a->height, 'uploaded_at' => $a->created_at?->toIso8601String()])->all(),
            'comments' => DB::table('design_comments')->join('design_projects', 'design_projects.id', '=', 'design_comments.design_project_id')
                ->where('design_comments.user_id', $user->id)->orderBy('design_comments.id')
                ->get(['design_comments.id', 'design_comments.body', 'design_comments.created_at', 'design_comments.resolved_at', 'design_projects.name as design'])->all(),
            'reviews' => [
                'requested' => DB::table('design_reviews')->where('requested_by', $user->id)->get(['id', 'design_project_id', 'status', 'note', 'decision_note', 'created_at', 'decided_at'])->all(),
                'assigned_to_them' => DB::table('design_reviews')->where('reviewer_id', $user->id)->get(['id', 'design_project_id', 'status', 'note', 'decision_note', 'created_at', 'decided_at'])->all(),
            ],
            'share_links_created' => DB::table('design_shares')->where('created_by', $user->id)->get(['id', 'design_project_id', 'permission', 'expires_at', 'revoked_at', 'download_allowed', 'view_count', 'last_viewed_at', 'created_at'])->all(),
            'access_grants' => DB::table('design_project_grants')->where('user_id', $user->id)->orWhere('granted_by', $user->id)->get(['design_project_id', 'user_id', 'level', 'granted_by', 'created_at'])->all(),
            'activity' => DB::table('design_activity')->where('user_id', $user->id)->orderBy('id')->get(['id', 'action', 'subject_type', 'subject_id', 'project_id', 'meta', 'created_at'])->all(),
            'exports' => DB::table('design_exports')->where('user_id', $user->id)->get(['design_project_id', 'format', 'pages', 'scale', 'bytes', 'created_at'])->all(),
            'assistant' => [
                'proposals' => DB::table('design_ai_proposals')->where('user_id', $user->id)->get(['id', 'design_project_id', 'mode', 'status', 'prompt', 'model', 'tokens_in', 'tokens_out', 'created_at'])->all(),
                'image_requests' => DB::table('ai_generation_logs')->where('user_id', $user->id)->get(['id', 'prompt', 'provider', 'created_at'])->all(),
            ],
            'brand_kits_created' => BrandKit::withoutGlobalScopes()->where('user_id', $user->id)->get(['id', 'team_id', 'name', 'colors', 'fonts', 'tagline', 'website', 'contacts', 'voice', 'guidelines', 'created_at'])->all(),
            'templates_created' => DesignTemplate::withoutGlobalScopes()->where('user_id', $user->id)->get(['id', 'name', 'description', 'source', 'created_at'])->all(),
            'picture_folders' => DB::table('design_asset_folders')->where('user_id', $user->id)->pluck('name')->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function workspaces(User $user): array
    {
        $owned = DB::table('teams')->where('user_id', $user->id)->get(['id', 'name', 'personal_team'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'role' => 'owner', 'personal' => (bool) $t->personal_team]);
        $member = DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->where('team_user.user_id', $user->id)
            ->get(['teams.id', 'teams.name', 'team_user.role'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'role' => $t->role, 'personal' => false]);

        return $owned->concat($member)->values()->all();
    }

    /** @return array<string, mixed> */
    private function project(DesignProject $project, bool $withDocuments): array
    {
        $out = [
            'id' => $project->id,
            'name' => $project->name,
            'visibility' => $project->visibility,
            'status' => $project->status,
            'workspace_id' => $project->team_id,
            'size' => $project->width.' x '.$project->height.' '.$project->unit,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];

        if ($withDocuments) {
            $out['pages'] = DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->get()
                ->map(fn (DesignCanvas $c) => ['page' => $c->page_number, 'name' => $c->name, 'elements' => $c->elements])->all();
        }

        return $out;
    }
}
