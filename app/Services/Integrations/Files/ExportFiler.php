<?php

namespace App\Services\Integrations\Files;

use App\Models\DesignFileLink;
use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use App\Services\Integrations\Contracts\FileVault;
use App\Services\Integrations\IntegrationRefused;
use Illuminate\Support\Str;

/**
 * Files one exported design in Dot.Files, or saves it as a new version if that same export was filed before.
 *
 * The export itself is produced in the browser and uploaded here; this class never renders anything. It checks the bytes really
 * are the format claimed (so a renamed file cannot slip through under a design's name), refuses an SVG that carries script, and
 * keeps one pointer per (design, workspace, scope, format) so a second save is a new version, not a second copy.
 */
class ExportFiler
{
    public function __construct(private readonly FileVault $vault) {}

    /** @return list<string> */
    public function formats(): array
    {
        return array_values(array_map('strtolower', (array) config('design_integrations.files.formats', [])));
    }

    /**
     * The workspace a design is filed under: the design's own workspace when it has one, otherwise the person's current one.
     * The person must belong to it and it must be linked to an InfoDot company, which is how Dot.Files names a business.
     *
     * @throws IntegrationRefused
     */
    public function teamFor(DesignProject $project, User $user): Team
    {
        $id = $project->getAttribute('team_id');
        $team = is_numeric($id) ? Team::find((int) $id) : $user->currentTeam;

        if (! $team instanceof Team || ! $user->belongsToTeam($team)) {
            throw new IntegrationRefused('Choose the business this design belongs to before saving it to Dot.Files.');
        }

        if (! filled($team->infodot_team_uuid)) {
            throw new IntegrationRefused('This workspace is not linked to an InfoDot company, so Dot.Files cannot place the file. Personal workspaces cannot use Dot.Files.');
        }

        return $team;
    }

    /**
     * @return array{name: string, open_url: string|null, version: int|null, new_version: bool}
     *
     * @throws IntegrationRefused
     */
    public function file(DesignProject $project, User $user, string $scope, string $format, string $bytes): array
    {
        $format = strtolower($format);

        if (! in_array($format, $this->formats(), true)) {
            throw new IntegrationRefused('Dot.Design can save '.strtoupper(implode(', ', $this->formats())).' to Dot.Files.');
        }

        $this->assertContent($format, $bytes);

        $max = (int) config('design_integrations.files.max_bytes', 25 * 1024 * 1024);

        if ($bytes === '' || strlen($bytes) > $max) {
            throw new IntegrationRefused('The file is empty or larger than the 25 MB Dot.Files accepts from other apps.');
        }

        $team = $this->teamFor($project, $user);
        $name = (Str::slug((string) $project->name) ?: 'design').'-'.$scope.'.'.$format;
        $keys = ['design_project_id' => $project->id, 'team_id' => $team->id, 'scope' => $scope, 'format' => $format];
        $link = DesignFileLink::where($keys)->first();

        if ($link !== null) {
            try {
                $meta = $this->vault->update($team, $link->files_uuid, $bytes, $link->version, $name);

                $link->forceFill(['user_id' => $user->id, 'version' => $meta['version'] ?? $link->version, 'filed_at' => now()])->save();

                return ['name' => $name, 'open_url' => $this->safeUrl($meta['open_url'] ?? $link->open_url), 'version' => $link->version, 'new_version' => true];
            } catch (IntegrationRefused $e) {
                if ($e->status !== 404) {
                    throw $e;
                }

                // The document was removed in Dot.Files (or is in its trash): file it again as a new document.
                $link->delete();
            }
        }

        $meta = $this->vault->store($team, $name, $bytes);

        DesignFileLink::create($keys + [
            'user_id' => $user->id,
            'files_uuid' => (string) ($meta['uuid'] ?? ''),
            'version' => null,
            'open_url' => $this->safeUrl($meta['open_url'] ?? null),
            'filed_at' => now(),
        ]);

        return ['name' => $name, 'open_url' => $this->safeUrl($meta['open_url'] ?? null), 'version' => null, 'new_version' => false];
    }

    /** @throws IntegrationRefused */
    private function assertContent(string $format, string $bytes): void
    {
        $ok = match ($format) {
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'jpg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'pdf' => str_starts_with($bytes, '%PDF-'),
            'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'svg' => (bool) preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096)),
            default => false,
        };

        if (! $ok) {
            throw new IntegrationRefused('That file is not a valid '.strtoupper($format).'.');
        }

        if ($format === 'svg' && preg_match('/<script|javascript:|<foreignObject|\son[a-z]+\s*=/i', $bytes)) {
            throw new IntegrationRefused('That SVG contains scripting and cannot be saved to Dot.Files.');
        }
    }

    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
