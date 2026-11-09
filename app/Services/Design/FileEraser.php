<?php

namespace App\Services\Design;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Finds and removes the files on the private design disk that belong to rows about to be deleted in bulk.
 *
 * A database cascade (deleting a user, a workspace purge) removes rows without running model events, so the pictures and
 * project thumbnails they point at would stay on the disk for ever. Callers collect the paths BEFORE deleting the rows
 * and remove the files AFTER the delete has committed, so a failed delete never loses a file a row still points at.
 */
class FileEraser
{
    /**
     * Everything a person owns: their uploads and the thumbnails of their projects.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function forUser(User $user): array
    {
        return $this->files(
            fn ($q) => $q->where('user_id', $user->id),
            fn ($q) => $q->where('user_id', $user->id),
        );
    }

    /**
     * Everything that belongs to a workspace: its assets, the assets placed in its projects, the thumbnails of its projects.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function forWorkspace(Team $team): array
    {
        $projectIds = DB::table('design_projects')->where('team_id', $team->id)->pluck('id');

        return $this->files(
            fn ($q) => $q->where('team_id', $team->id)->orWhereIn('design_project_id', $projectIds),
            fn ($q) => $q->where('team_id', $team->id),
        );
    }

    /**
     * Remove the files. A file that is already gone is not an error; a failure is counted, never thrown, because the rows
     * are already deleted and the caller must still finish.
     *
     * @param  list<array{disk: string, path: string}>  $files
     * @return array{deleted: int, failed: int}
     */
    public function delete(array $files): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                $disk = Storage::disk($file['disk']);

                if ($disk->exists($file['path'])) {
                    $disk->delete($file['path']) ? $deleted++ : $failed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * @return list<array{disk: string, path: string}>
     */
    private function files(callable $assets, callable $projects): array
    {
        $out = [];
        $assetDisk = (string) config('design.assets.disk');

        $rows = DB::table('design_assets')->where($assets)->whereNotNull('disk')->get(['disk', 'file_path']);

        foreach ($rows as $row) {
            if ($this->safe((string) $row->file_path)) {
                $out[$row->disk.'|'.$row->file_path] = ['disk' => (string) $row->disk, 'path' => ltrim((string) $row->file_path, '/')];
            }
        }

        $thumbs = DB::table('design_projects')->where($projects)->whereNotNull('thumbnail_path')->pluck('thumbnail_path');

        foreach ($thumbs as $path) {
            if ($this->safe((string) $path)) {
                $out[$assetDisk.'|'.$path] = ['disk' => $assetDisk, 'path' => ltrim((string) $path, '/')];
            }
        }

        return array_values($out);
    }

    /** Never follow a path that is a URL or climbs out of the disk (the same rule DesignAsset::deleteStoredFile applies). */
    private function safe(string $path): bool
    {
        return $path !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $path) && ! str_contains($path, '..') && ! str_contains($path, "\0");
    }
}
