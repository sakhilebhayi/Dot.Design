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

        if ($format === 'svg') {
            $this->assertInertSvg($bytes);
        }
    }

    /** Elements an exported poster never needs and a hostile SVG would use to run code, move the viewer or phone home. */
    private const SVG_FORBIDDEN_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'canvas', 'set', 'animate', 'animatetransform', 'animatemotion',
        'handler', 'listener', 'discard', 'html', 'body', 'meta', 'link',
    ];

    /**
     * Read the SVG as XML (no DTD, no entities, no network) and refuse anything active: scripts and embedded documents, event
     * handlers, links that are not a same-file fragment or an embedded image, and styles that load from elsewhere. The text a
     * person typed on the poster is element content, already escaped by the exporter, so words such as "onion = tasty" pass.
     *
     * @throws IntegrationRefused
     */
    private function assertInertSvg(string $bytes): void
    {
        $refuse = fn (string $why) => new IntegrationRefused('That SVG contains '.$why.' and cannot be saved to Dot.Files.');

        // A DTD is how an SVG pulls in files or blows up memory with nested entities: an export never has one.
        if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $bytes)) {
            throw $refuse('a DTD, entity or stylesheet instruction');
        }

        $reader = new \XMLReader;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $reader->XML($bytes, 'UTF-8', LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new IntegrationRefused('That is not a valid SVG.');
            }

            $inStyle = false;

            while (@$reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $name = strtolower($reader->localName);
                    $inStyle = $name === 'style' && ! $reader->isEmptyElement;

                    if (in_array($name, self::SVG_FORBIDDEN_ELEMENTS, true)) {
                        throw $refuse('an active element ('.$name.')');
                    }

                    if ($reader->hasAttributes && $reader->moveToFirstAttribute()) {
                        do {
                            $attribute = strtolower($reader->localName);
                            $value = trim((string) $reader->value);

                            if (str_starts_with($attribute, 'on')) {
                                throw $refuse('an event handler');
                            }

                            if (in_array($attribute, ['href', 'src'], true) && ! preg_match('/^(#[\w.:-]*|data:image\/(png|jpe?g|webp|gif);base64,[A-Za-z0-9+\/=\s]*)$/i', $value)) {
                                throw $refuse('a link that leaves the file');
                            }

                            if (preg_match('/^\s*(javascript|vbscript|data:text)/i', $value) || preg_match('/expression\s*\(|@import|url\(\s*[\'"]?(?!#|data:(image|font))/i', $value)) {
                                throw $refuse('active or external content in an attribute');
                            }
                        } while ($reader->moveToNextAttribute());

                        $reader->moveToElement();
                    }
                } elseif ($inStyle && in_array($reader->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA], true)
                    && preg_match('/@import|expression\s*\(|url\(\s*[\'"]?(?!#|data:(image|font))/i', (string) $reader->value)) {
                    throw $refuse('a style that loads from elsewhere');
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && strtolower($reader->localName) === 'style') {
                    $inStyle = false;
                } elseif ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF) {
                    throw $refuse('a DTD or entity');
                }
            }

            if (libxml_get_errors() !== []) {
                throw new IntegrationRefused('That is not a valid SVG.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
