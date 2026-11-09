<?php

namespace App\Services\Design;

use App\Contracts\Design\ActivityRecorder;
use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that creates or changes a template row outside the seeding command: saving a design as a
 * team or personal template, editing its details, publishing and retiring (operators), and usage counts.
 */
class TemplateLibrary
{
    public const MAX_SAVED_PER_USER = 100;

    private const KEYS = [
        'text' => ['id', 'type', 'name', 'role', 'bind', 'x', 'y', 'width', 'height', 'rotation', 'opacity', 'locked', 'hidden', 'flip_x', 'flip_y', 'blend', 'shadow', 'animation', 'tokens', 'text', 'font', 'fontSize', 'fontWeight', 'italic', 'underline', 'strike', 'align', 'valign', 'lineHeight', 'letterSpacing', 'textCase', 'color', 'autoFit', 'stroke'],
        'shape' => ['id', 'type', 'name', 'role', 'bind', 'x', 'y', 'width', 'height', 'rotation', 'opacity', 'locked', 'hidden', 'flip_x', 'flip_y', 'blend', 'shadow', 'animation', 'tokens', 'kind', 'fill', 'stroke', 'radius', 'sides', 'points', 'inner', 'd'],
        'image' => ['id', 'type', 'name', 'role', 'bind', 'x', 'y', 'width', 'height', 'rotation', 'opacity', 'locked', 'hidden', 'flip_x', 'flip_y', 'blend', 'shadow', 'animation', 'tokens', 'asset_id', 'fit', 'focal', 'zoom', 'mask', 'radius', 'border', 'filters'],
        'icon' => ['id', 'type', 'name', 'role', 'bind', 'x', 'y', 'width', 'height', 'rotation', 'opacity', 'locked', 'hidden', 'flip_x', 'flip_y', 'blend', 'shadow', 'animation', 'tokens', 'icon', 'color', 'strokeWidth'],
        'group' => ['id', 'type', 'name', 'role', 'bind', 'x', 'y', 'width', 'height', 'rotation', 'opacity', 'locked', 'hidden', 'flip_x', 'flip_y', 'blend', 'shadow', 'animation', 'tokens', 'children'],
    ];

    public function __construct(
        private readonly TemplateChecker $checker,
        private readonly Formats $formats,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Save a design as a template. Pictures are NOT kept (a template holds empty picture slots, never someone's upload);
     * text stays as sample text; elements named in $bindings become fillable slots.
     *
     * @param  array{name: string, description?: ?string, kind: string, category?: ?string, scope: string, styles?: list<string>, industries?: list<string>, purposes?: list<string>, bindings?: array<string, string>}  $meta
     */
    public function saveFromProject(DesignProject $project, User $user, array $meta): DesignTemplate
    {
        $scope = $meta['scope'] === 'team' ? 'team' : 'user';
        $team = null;

        if ($scope === 'team') {
            $team = $user->allTeams()->firstWhere('id', $user->current_team_id) ?? $user->allTeams()->first();
            if ($team === null || ! $user->hasTeamPermission($team, 'create')) {
                throw ValidationException::withMessages(['scope' => 'Your role in this workspace cannot save templates for the team.']);
            }
        }

        $saved = DesignTemplate::query()->where('user_id', $user->id)->whereIn('source', ['team', 'user'])->count();
        if ($saved >= self::MAX_SAVED_PER_USER) {
            throw ValidationException::withMessages(['name' => 'You have saved the maximum of '.self::MAX_SAVED_PER_USER.' templates. Delete one you no longer use.']);
        }

        $project->loadMissing('canvases');
        $bindings = (array) ($meta['bindings'] ?? []);
        $pages = [];

        foreach ($project->canvases as $canvas) {
            $pages[] = $this->pageFrom($canvas, $project, $bindings);
        }

        $format = app(ProjectMeta::class)->formatKey($project) ?? $this->formats->matchingKey((int) $project->width, (int) $project->height);
        $definition = [
            'slug' => $this->uniqueSlug((string) $meta['name']),
            'name' => $meta['name'],
            'description' => $meta['description'] ?? null,
            'category' => $meta['category'] ?? config("design_copy.kind_categories.{$meta['kind']}", 'marketing'),
            'kind' => $meta['kind'],
            'styles' => array_values($meta['styles'] ?? []),
            'industries' => array_values($meta['industries'] ?? []),
            'purposes' => array_values($meta['purposes'] ?? []),
            'format_key' => $format,
            'width' => (int) $project->width,
            'height' => (int) $project->height,
            'pages' => $pages,
        ];

        // A project whose size is not a catalog format is still saveable: drop the format key and the size check goes with it.
        if ($format === null) {
            unset($definition['format_key']);
        }

        $errors = $this->checker->checkDefinition($definition);
        if ($errors !== []) {
            throw ValidationException::withMessages(['template' => 'This design cannot be saved as a template yet: '.implode('; ', array_slice($errors, 0, 3)).'.']);
        }

        $bound = $this->checker->boundKeys($pages);

        return DesignTemplate::create([
            'slug' => $definition['slug'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'source' => $scope,
            'team_id' => $team?->id,
            'user_id' => $user->id,
            'status' => 'published',
            'category' => $definition['category'],
            'kind' => $definition['kind'],
            'industries' => $definition['industries'],
            'purposes' => $definition['purposes'],
            'styles' => $definition['styles'],
            'platforms' => $format ? [$this->formats->get($format)['platform']] : [],
            'objectives' => [],
            'format_key' => $format,
            'width' => $definition['width'],
            'height' => $definition['height'],
            'pages' => $pages,
            'slots' => array_map(fn (string $key) => ['key' => $key], $bound),
            'page_count' => count($pages),
            'popularity' => 0,
            'version' => 1,
            'content_hash' => sha1((string) json_encode($pages)),
            'published_at' => now(),
        ]);
    }

    /**
     * @param  array{name?: string, description?: ?string, styles?: list<string>, industries?: list<string>, purposes?: list<string>}  $changes
     */
    public function updateDetails(DesignTemplate $template, array $changes): DesignTemplate
    {
        $template->fill(array_intersect_key($changes, array_flip(['name', 'description', 'styles', 'industries', 'purposes'])));

        $errors = $this->checker->checkDefinition([
            'slug' => $template->slug, 'name' => $template->name, 'description' => $template->description,
            'category' => $template->category, 'kind' => $template->kind, 'styles' => $template->styles,
            'industries' => $template->industries, 'purposes' => $template->purposes,
            'pages' => [['elements' => []]], 'width' => $template->width, 'height' => $template->height,
        ]);
        $errors = array_values(array_filter($errors, fn (string $e) => ! str_starts_with($e, 'pages')));

        if ($errors !== []) {
            throw ValidationException::withMessages(['template' => implode('; ', $errors)]);
        }

        $template->save();

        return $template;
    }

    public function publish(DesignTemplate $template, User $user): DesignTemplate
    {
        $template->forceFill(['status' => 'published', 'published_at' => $template->published_at ?? now()])->save();
        $this->activity->record('template.published', $user, $template->team_id, null, 'design_template', $template->id);

        return $template;
    }

    public function retire(DesignTemplate $template, User $user): DesignTemplate
    {
        $template->forceFill(['status' => 'retired'])->save();
        $this->activity->record('template.retired', $user, $template->team_id, null, 'design_template', $template->id);

        return $template;
    }

    /** Best-effort usage counter: a failure here must never stop someone from creating their design. */
    public function recordUse(DesignTemplate $template): void
    {
        try {
            DB::table('design_templates')->where('id', $template->id)->increment('uses');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param array<string, string> $bindings element id => slot key */
    private function pageFrom(\App\Models\DesignCanvas $canvas, DesignProject $project, array $bindings): array
    {
        $page = [
            'elements' => $this->cleanElements((array) $canvas->elements, $bindings),
        ];

        $width = $this->column($canvas, 'width');
        $height = $this->column($canvas, 'height');
        if ($width !== null && (int) $width !== (int) $project->width) {
            $page['width'] = (int) $width;
        }
        if ($height !== null && (int) $height !== (int) $project->height) {
            $page['height'] = (int) $height;
        }

        $name = $this->column($canvas, 'name');
        if (is_string($name) && $name !== '') {
            $page['name'] = mb_substr($name, 0, 80);
        }

        $background = $this->column($canvas, 'background');
        $background = is_string($background) ? json_decode($background, true) : $background;
        $color = is_array($background) ? ($background['color'] ?? null) : $canvas->background_color;
        $type = is_array($background) ? ($background['type'] ?? 'solid') : 'solid';

        if (is_array($background) && $type === 'gradient' && is_array($background['gradient'] ?? null)) {
            $page['background'] = ['type' => 'gradient', 'gradient' => $background['gradient'], 'color' => is_string($color) ? $color : '#ffffff'];
        } elseif (is_string($color) && $color !== '') {
            $page['background'] = ['type' => 'solid', 'color' => $color];
        }

        return $page;
    }

    private function column(\App\Models\DesignCanvas $canvas, string $column): mixed
    {
        return Schema::hasColumn('design_canvases', $column) ? DB::table('design_canvases')->where('id', $canvas->id)->value($column) : null;
    }

    /**
     * Upgrade v1 elements, drop unknown keys and pictures, apply requested bindings.
     *
     * @param  array<int, mixed>  $elements
     * @param  array<string, string>  $bindings
     * @return list<array<string, mixed>>
     */
    private function cleanElements(array $elements, array $bindings): array
    {
        $out = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (($element['type'] ?? null) === 'rectangle') {
                $element['type'] = 'shape';
                $element['kind'] = 'rect';
            }
            if (($element['type'] ?? null) === 'text' && ! isset($element['font'])) {
                $element['font'] = 'inter';
            }

            $type = $element['type'] ?? null;
            if (! isset(self::KEYS[$type])) {
                continue;
            }

            $element = array_intersect_key($element, array_flip(self::KEYS[$type]));
            $element = array_filter($element, fn ($v) => $v !== null);

            if (isset($element['id'], $bindings[$element['id']])) {
                $element['bind'] = $bindings[$element['id']];
            }

            if ($type === 'image') {
                unset($element['asset_id']);
            }
            if ($type === 'group') {
                $element['children'] = $this->cleanElements((array) ($element['children'] ?? []), $bindings);
                if ($element['children'] === []) {
                    continue;
                }
            }

            $out[] = $element;
        }

        return $out;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';

        return substr($base, 0, 90).'-'.Str::lower(Str::random(6));
    }
}
