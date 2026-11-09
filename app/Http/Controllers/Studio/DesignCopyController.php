<?php

namespace App\Http\Controllers\Studio;

use App\Events\DesignProjectCreated;
use App\Models\BrandKit;
use App\Models\DesignProject;
use App\Services\Design\ActivityLog;
use App\Services\Design\DocumentContext;
use App\Services\Design\DocumentRejected;
use App\Services\Design\DocumentSanitiser;
use App\Services\Design\DocumentSchema;
use App\Services\Design\Formats;
use App\Services\Design\ProjectAssets;
use App\Services\Design\ProjectMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Make this for another place": the browser re-lays out the pages for another format (resize.js) and posts the result
 * here; the server makes it a NEW project. The original is never touched. The pages are treated like any client input:
 * every page goes through DocumentSanitiser (pictures must be ones the caller may use in the source project), and the
 * size of each page is the format's own size, not what the client says.
 */
class DesignCopyController extends StudioController
{
    public function store(Request $request, DesignProject $project, Formats $formats, DocumentSanitiser $sanitiser, ProjectAssets $assets, ProjectMeta $meta, ActivityLog $activity): JsonResponse
    {
        $this->authorize('view', $project);
        $this->authorize('create', DesignProject::class);

        $data = $request->validate([
            'format_key' => ['required', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'min:1', 'max:120'],
            'pages' => ['required', 'array', 'min:1'],
            'pages.*' => ['array'],
        ]);

        $format = $formats->find($data['format_key']);

        if ($format === null) {
            return $this->invalid('That size is not one of the formats we offer.', ['format_key' => ['Unknown format.']]);
        }

        $maxPages = DocumentSchema::shared()->limit('max_pages');

        if (count($data['pages']) > $maxPages) {
            return $this->invalid("A design can have at most {$maxPages} pages.", ['pages' => ['Too many pages.']]);
        }

        $user = $request->user();
        $context = new DocumentContext(fn (array $ids) => $assets->allowedForSave($user, $project, $ids));
        $clean = [];

        try {
            foreach (array_values($data['pages']) as $index => $page) {
                // The size is the format's, whatever the browser says; everything else is sanitised like a normal save.
                $page['width'] = $format['px']['width'];
                $page['height'] = $format['px']['height'];
                $page['elements'] = is_array($page['elements'] ?? null) ? $page['elements'] : [];
                unset($page['revision'], $page['id']);

                $clean[] = $sanitiser->sanitisePage($page, $context);
            }
        } catch (DocumentRejected $e) {
            return $this->invalid($e->getMessage(), $e->errors, $e->httpStatus);
        }

        $brief = $meta->brief($project);
        $link = $meta->brandLink($project);
        $kitVisible = $link['kit_id'] !== null && BrandKit::query()->whereKey($link['kit_id'])->exists();

        $copy = DB::transaction(function () use ($user, $project, $data, $format, $clean, $meta, $brief, $link, $kitVisible): DesignProject {
            $copy = new DesignProject([
                'user_id' => $user->id,
                'name' => mb_substr(trim($data['name'] ?? '') !== '' ? trim($data['name']) : $project->name.' ('.$format['label'].')', 0, 255),
                'type' => $format['type'] ?? $project->type,
                'width' => $format['px']['width'],
                'height' => $format['px']['height'],
                'unit' => 'px',
            ]);
            // The workspace is the caller's current one (HasWorkspaceScope fills team_id on create); visibility follows the source.
            $copy->forceFill(['visibility' => $project->visibility === 'team' ? 'team' : 'private'])->save();

            foreach ($clean as $page) {
                $fields = $page->fields;
                $background = $fields['background'] ?? null;
                $copy->addPage([
                    'name' => $fields['name'] ?? null,
                    'width' => $format['px']['width'],
                    'height' => $format['px']['height'],
                    'elements' => $page->elements ?? [],
                    'background' => $background,
                    'background_color' => is_array($background) && ($background['type'] ?? null) === 'solid' ? substr((string) $background['color'], 0, 7) : '#ffffff',
                    'duration_ms' => $fields['duration_ms'] ?? 5000,
                    'transition' => $fields['transition'] ?? 'none',
                    'guides' => [],
                    'revision' => 1,
                ]);
            }

            $values = ['format_key' => $format['key'], 'brief' => $brief === [] ? null : array_merge($brief, ['format_key' => $format['key']]), 'template_id' => $meta->templateId($project)];

            if ($kitVisible) {
                // The kit link is carried over only when the caller can see that kit; the version stays what the source had taken.
                $values['brand_kit_id'] = $link['kit_id'];
                $values['brand_version'] = $link['version'];
            }

            $meta->write($copy, array_filter($values, fn ($v) => $v !== null));

            return $copy;
        });

        event(new DesignProjectCreated($copy));
        $activity->record($user, $copy, 'project.copied_to_format', 'project', $copy->id, ['source_project' => $project->id, 'format' => $format['key'], 'pages' => count($clean)]);

        return response()->json([
            'status' => 'created',
            'id' => $copy->id,
            'name' => $copy->name,
            'format_key' => $format['key'],
            'url' => route('design.projects.show', $copy),
        ], 201);
    }
}
