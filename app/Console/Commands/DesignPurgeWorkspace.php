<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Design\ActivityLog;
use App\Services\Design\FileEraser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Privacy deletion of everything Dot.Design holds for one workspace: designs with their pages, history, shares,
 * comments and reviews, uploaded pictures and thumbnails (database rows AND files), brand kits, the workspace's own
 * templates, assistant proposals, Dot.Files links and the workspace's audit trail. The team itself, its members and the
 * InfoDot link are left alone (InfoDot owns them). Shows the counts and deletes nothing unless --execute is given.
 *
 * Deleting a workspace through Jetstream does NOT do this: the foreign keys only detach its projects, which then stay,
 * private to their owners. This command is the erasure.
 *
 * One row is kept: a `workspace.purged` entry in the audit trail with the workspace id and the counts, no names and no
 * content, so there is a record that the erasure happened and when. Files already filed in Dot.Files are in another
 * system and are not touched.
 */
class DesignPurgeWorkspace extends Command
{
    protected $signature = 'design:purge-workspace
        {team : The workspace (team) id}
        {--execute : Really delete. Without it only the counts are shown}
        {--yes : Do not ask for confirmation}';

    protected $description = 'Erase all Dot.Design data of one workspace (rows and files). Shows counts unless --execute';

    public function handle(FileEraser $files, ActivityLog $activity): int
    {
        $team = Team::find($this->argument('team'));

        if (! $team) {
            $this->error('No workspace with that id. A workspace that was already deleted has no projects left to find by id; look up the owner instead (docs/R45_OPERATIONS.md).');

            return self::FAILURE;
        }

        $id = $team->id;
        $projectIds = DB::table('design_projects')->where('team_id', $id)->pluck('id');

        $counts = [
            'designs' => $projectIds->count(),
            'pages' => DB::table('design_canvases')->whereIn('design_project_id', $projectIds)->count(),
            'versions' => DB::table('design_versions')->whereIn('design_project_id', $projectIds)->count(),
            'shares' => DB::table('design_shares')->whereIn('design_project_id', $projectIds)->count(),
            'comments' => DB::table('design_comments')->whereIn('design_project_id', $projectIds)->count(),
            'reviews' => DB::table('design_reviews')->whereIn('design_project_id', $projectIds)->count(),
            'uploaded pictures' => DB::table('design_assets')->where(fn ($q) => $q->where('team_id', $id)->orWhereIn('design_project_id', $projectIds))->count(),
            'brand kits' => DB::table('brand_kits')->where('team_id', $id)->count(),
            'workspace templates' => DB::table('design_templates')->where('team_id', $id)->count(),
            'assistant proposals' => DB::table('design_ai_proposals')->where('team_id', $id)->count(),
            'Dot.Files links' => DB::table('design_file_links')->where('team_id', $id)->count(),
            'audit entries' => DB::table('design_activity')->where('team_id', $id)->count(),
        ];

        $this->info("Workspace #{$id} ({$team->name}): ".($this->option('execute') ? 'about to erase' : 'would erase'));
        $this->table(['What', 'Rows'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $fileList = $files->forWorkspace($team);
        $this->line(count($fileList).' stored file(s) on the private disk (pictures and thumbnails).');

        if (! $this->option('execute')) {
            $this->comment('Nothing was deleted. Run again with --execute to erase it.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm("Erase all of the above for workspace #{$id}? This cannot be undone.", false)) {
            $this->warn('Cancelled. Nothing was deleted.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($id, $projectIds): void {
            // Assets first: deleting a project would only detach the pictures placed in it.
            DB::table('design_assets')->where(fn ($q) => $q->where('team_id', $id)->orWhereIn('design_project_id', $projectIds))->delete();
            // Pages, versions, shares, comments, reviews, grants, exports, proposals and brand updates go with the project (cascade).
            DB::table('design_projects')->where('team_id', $id)->delete();
            DB::table('brand_kits')->where('team_id', $id)->delete();
            DB::table('design_templates')->where('team_id', $id)->delete();
            DB::table('design_ai_proposals')->where('team_id', $id)->delete();
            DB::table('design_file_links')->where('team_id', $id)->delete();
            DB::table('design_activity')->where('team_id', $id)->delete();
        });

        $result = $files->delete($fileList);

        $activity->record(null, null, 'workspace.purged', 'workspace', $id, $counts + ['files_deleted' => $result['deleted'], 'files_failed' => $result['failed']], $id);

        $this->info("Erased. {$result['deleted']} file(s) removed".($result['failed'] > 0 ? ", {$result['failed']} could NOT be removed (check storage/app/private/design-assets permissions)" : '').'.');
        $this->line('Left in place on purpose: the workspace and its members (InfoDot owns them), files already filed in Dot.Files, and one audit entry (workspace.purged) with the counts.');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
