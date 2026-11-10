<?php

namespace App\Services\Design\Assistant;

use App\Models\BrandKit;
use App\Models\DesignTemplate;
use App\Models\User;
use App\Services\Design\Formats;
use App\Services\Design\TemplateRepository;

/**
 * What the model is allowed to know about the product: a compact catalogue of the templates and formats the person
 * may use (slugs, kinds, styles, slot labels, never full documents) and a summary of their brand kit.
 */
class Catalogue
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly Formats $formats,
    ) {}

    /**
     * @return array{templates: list<array<string, mixed>>, formats: list<array<string, mixed>>}
     */
    public function build(User $user, ?string $formatKey = null): array
    {
        $labels = (array) config('design_copy.slots');
        $templates = [];

        foreach ($this->templates->candidates($user) as $template) {
            if ($formatKey !== null && $template->format_key !== $formatKey) {
                continue;
            }

            $templates[] = [
                'slug' => $template->slug,
                'name' => TextGuard::data((string) $template->name, 80),
                'kind' => $template->kind,
                'format_key' => $template->format_key,
                'styles' => array_values((array) $template->styles),
                'purposes' => array_values((array) $template->purposes),
                'industries' => array_values((array) $template->industries),
                'pages' => (int) $template->page_count,
                'slots' => array_values(array_map(
                    fn (array $slot) => $slot['key'].': '.($labels[$slot['key']]['label'] ?? $slot['key']),
                    array_filter($template->slotList(), fn ($slot) => is_array($slot) && isset($slot['key'])),
                )),
            ];
        }

        $formats = [];
        foreach ($this->formats->all() as $key => $format) {
            $formats[] = ['key' => $key, 'label' => $format['label'], 'size' => ($format['width'] ?? '').' x '.($format['height'] ?? '').' '.($format['unit'] ?? '')];
        }

        return ['templates' => $templates, 'formats' => $formats];
    }

    /** The user's kit for this request: the one named, else the workspace default, else the first one they can see. */
    public function kit(?int $kitId = null): ?BrandKit
    {
        $query = BrandKit::query()->with('assets');

        if ($kitId !== null) {
            return $query->whereKey($kitId)->first();
        }

        return $query->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** @return array<string, mixed>|null */
    public function brandSummary(?BrandKit $kit): ?array
    {
        if ($kit === null) {
            return null;
        }

        $voice = (array) ($kit->voice ?? []);

        return array_filter([
            'colors' => array_values(array_map(fn ($c) => ['role' => $c['role'] ?? null, 'key' => $c['key'] ?? null, 'hex' => $c['hex'] ?? null], array_filter((array) $kit->colors, 'is_array'))),
            'fonts' => (array) $kit->fonts,
            'tagline' => $kit->tagline ? TextGuard::data((string) $kit->tagline, 120) : null,
            'voice' => array_filter([
                'tone' => isset($voice['tone']) ? TextGuard::data((string) $voice['tone'], 120) : null,
                'keywords' => array_map(fn ($k) => TextGuard::data((string) $k, 40), array_slice((array) ($voice['keywords'] ?? []), 0, 8)),
                'avoid' => array_map(fn ($k) => TextGuard::data((string) $k, 40), array_slice((array) ($voice['avoid'] ?? []), 0, 8)),
            ]),
            'has_logo' => $kit->assetFor('logo') !== null || $kit->assetFor('logo-light') !== null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    public function templateBySlug(string $slug, User $user): ?DesignTemplate
    {
        $template = $this->templates->find($slug, $user);

        return $template instanceof DesignTemplate && $template->isPublished() ? $template : null;
    }
}
