<?php

namespace App\Services\Design;

use App\Events\DesignProjectCreated;
use App\Models\BrandKit;
use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Models\User;
use App\Support\Db\Columns;
use Illuminate\Support\Facades\DB;

/**
 * Creates the project (and its first pages) for "start from a brief": from a chosen template with slots
 * filled, or blank at the chosen size. The brief is stored on the project. Pages are written through the
 * query builder with explicit JSON so this keeps working whichever casts the backend branch adds to
 * DesignCanvas; the v2 page columns (name, width, height, background, duration_ms, transition, guides) are
 * written only when they exist.
 */
class StarterProjectBuilder
{
    /** Page-level keys of a v2 page that map to design_canvases columns when present. */
    private const V2_JSON_COLUMNS = ['background', 'guides'];

    private const V2_COLUMNS = ['name', 'width', 'height', 'duration_ms', 'transition'];

    public function __construct(
        private readonly Formats $formats,
        private readonly TemplateInstantiator $instantiator,
        private readonly TemplateLibrary $library,
        private readonly ProjectMeta $meta,
        private readonly BriefFlow $flow,
    ) {}

    /**
     * @param  array<string, mixed>  $brief  validated
     * @return array{project: DesignProject, result: ?InstantiationResult, format_key: ?string}
     */
    public function create(User $user, array $brief, ?DesignTemplate $template = null, ?BrandKit $kit = null, bool $applyBrand = false, ?string $name = null): array
    {
        $formatKey = $template?->format_key ?? $brief['format_key'] ?? $this->defaultFormat($brief);
        $result = $template !== null ? $this->instantiator->instantiate($template, $brief, $kit, $applyBrand, $user) : null;

        if ($result !== null) {
            [$width, $height] = [$result->width, $result->height];
        } else {
            $px = $this->formats->pixels($formatKey);
            [$width, $height] = [$px['width'], $px['height']];
        }

        $format = $this->formats->find($formatKey ?? '');
        $project = DB::transaction(function () use ($user, $brief, $template, $kit, $applyBrand, $name, $formatKey, $format, $result, $width, $height): DesignProject {
            $project = DesignProject::create([
                'user_id' => $user->id,
                'name' => $this->projectName($name, $brief, $template, $format),
                'type' => $format['type'] ?? 'graphic',
                'width' => $width,
                'height' => $height,
                'unit' => 'px',
            ]);

            $pages = $result?->pages ?? [['elements' => []]];
            foreach ($pages as $index => $page) {
                $this->insertPage($project, $index + 1, $page);
            }

            $values = [
                'format_key' => $formatKey,
                'template_id' => $template?->id,
                'brief' => $this->storableBrief($brief),
            ];

            if ($user->current_team_id !== null && Columns::has('design_projects', 'team_id')) {
                $values['team_id'] = DB::table('design_projects')->where('id', $project->id)->value('team_id') ?? $user->current_team_id;
            }

            if ($kit !== null && $applyBrand) {
                $values['brand_kit_id'] = $kit->id;
                $values['brand_version'] = $kit->version;
            }

            $this->meta->write($project, $values);

            return $project;
        });

        if ($template !== null) {
            $this->library->recordUse($template);
        }

        event(new DesignProjectCreated($project));

        return ['project' => $project->fresh(), 'result' => $result, 'format_key' => $formatKey];
    }

    /** The size to use when the person chose neither a template nor a size: the goal's most-used one. */
    public function defaultFormat(array $brief): string
    {
        $goal = isset($brief['goal']) ? $this->flow->goal($brief['goal']) : null;

        return $goal['formats'][0] ?? 'a4-portrait';
    }

    /** @param array<string, mixed> $page */
    private function insertPage(DesignProject $project, int $number, array $page): void
    {
        $background = is_array($page['background'] ?? null) ? $page['background'] : [];
        $color = is_string($background['color'] ?? null) ? $background['color'] : '#ffffff';

        $row = [
            'design_project_id' => $project->id,
            'page_number' => $number,
            'elements' => json_encode(array_values((array) ($page['elements'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // v1 exporters read this 7-character column; the full background lives in `background` when that column exists.
            'background_color' => substr($color, 0, 7),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (self::V2_COLUMNS as $column) {
            if (isset($page[$column]) && Columns::has('design_canvases', $column)) {
                $row[$column] = $page[$column];
            }
        }
        foreach (self::V2_JSON_COLUMNS as $column) {
            if (isset($page[$column]) && Columns::has('design_canvases', $column)) {
                $row[$column] = json_encode($page[$column], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        DB::table('design_canvases')->insert($row);
    }

    /** @return array<string, mixed> the brief without anything derived or transient */
    private function storableBrief(array $brief): array
    {
        return array_intersect_key($brief, array_flip(['goal', 'what', 'business_name', 'details', 'logo_asset_id', 'photos', 'style', 'format_key', 'industry', 'brand_kit_id', 'apply_brand', 'skipped']));
    }

    /** @param array<string, mixed>|null $format */
    private function projectName(?string $name, array $brief, ?DesignTemplate $template, ?array $format): string
    {
        if ($name !== null && trim($name) !== '') {
            return mb_substr(trim($name), 0, 255);
        }

        $what = $template?->name ?? $format['label'] ?? 'Design';
        $business = $brief['business_name'] ?? null;

        return mb_substr($business ? "$business - $what" : $what, 0, 255);
    }
}
