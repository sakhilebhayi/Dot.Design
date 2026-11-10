<?php

namespace App\Support\Ui;

use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\Formats;
use App\Services\Design\ProjectMeta;

/**
 * What the Start screen and the guided brief need that is not a controller's business: which format a `?format=` link means,
 * whether the assistant box is offered, the custom-size limits and the last brief the person wrote.
 */
class StartScreen
{
    /** Canvas limits for a custom size, in px at 72 dpi. The engine allows 1 to 20000; the create route allows up to 10000. */
    public const MIN_PX = 16;

    public const MAX_PX = 10000;

    /**
     * Old home-strip keys (StartFormats) and the catalogue format each one now means. `logo` has no catalogue format:
     * it opens the custom size panel at 1000 x 1000 px.
     */
    private const LEGACY = [
        'instagram-post' => 'instagram-post-square',
        'poster' => 'a3-portrait',
        'flyer' => 'a5-portrait',
        'banner' => 'facebook-post',
        'business-card' => 'business-card-za',
    ];

    public function __construct(private readonly Formats $formats, private readonly ProjectMeta $meta) {}

    /**
     * What a `?format=` value means: a catalogue format (also through an old key), a custom preset, or nothing.
     *
     * @return array{kind: 'format', format: array<string, mixed>}|array{kind: 'custom', width: int, height: int, unit: string, label: string}|null
     */
    public function resolveFormat(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        $key = self::LEGACY[$key] ?? $key;
        $format = $this->formats->find($key);

        if ($format !== null) {
            return ['kind' => 'format', 'format' => $format];
        }

        if ($key === 'logo') {
            return ['kind' => 'custom', 'width' => 1000, 'height' => 1000, 'unit' => 'px', 'label' => 'Logo'];
        }

        return null;
    }

    /** The assistant box is shown only when a model key is configured (or the assistant is switched on explicitly). */
    public static function assistantEnabled(): bool
    {
        $explicit = config('design.ai.enabled');

        return is_bool($explicit) ? $explicit : filled(config('services.anthropic.api_key'));
    }

    /** @return array{min_px: int, max_px: int, units: list<string>, dpi: int} */
    public function sizeLimits(): array
    {
        return ['min_px' => self::MIN_PX, 'max_px' => self::MAX_PX, 'units' => ['px', 'mm', 'cm', 'in'], 'dpi' => (int) config('design_formats.canvas_dpi', 72)];
    }

    /**
     * The person's most recent saved brief (business name and details), so a template's form can start filled.
     * Stored on the project by the guided brief; nothing is kept in the browser.
     *
     * @return array<string, mixed>
     */
    public function previousBrief(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $projects = DesignProject::query()->latest('id')->limit(12)->get();

        foreach ($projects as $project) {
            $brief = $this->meta->brief($project);
            if (($brief['business_name'] ?? '') !== '' || ! empty($brief['details'])) {
                return array_intersect_key($brief, array_flip(['business_name', 'details', 'what', 'goal', 'style']));
            }
        }

        return [];
    }

    /**
     * The font manifest the engine's font manager reads (config/design_fonts.php with `files` turned into URLs), so template
     * previews are drawn in the real typefaces.
     *
     * @return list<array<string, mixed>>
     */
    public function fontManifest(): array
    {
        return collect((array) config('design_fonts.fonts'))->map(function (array $font): array {
            $font['files'] = collect((array) ($font['files'] ?? []))->map(fn (string $path) => asset($path))->all();

            return $font;
        })->values()->all();
    }
}
