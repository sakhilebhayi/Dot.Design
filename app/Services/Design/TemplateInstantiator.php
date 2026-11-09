<?php

namespace App\Services\Design;

use App\Models\BrandKit;
use App\Models\DesignTemplate;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns a template into pages for a new design.
 *
 * Rules (docs/R45_BUSINESS.md):
 *  - the template is never modified; pages are deep copies (PHP arrays are values) with fresh element ids;
 *  - only elements with a `bind` are touched, and only text (replacing the `text` string, nothing else)
 *    and picture slots (setting `asset_id`, nothing else);
 *  - text comes from the brief; where the brief has none, the chosen brand kit may supply tagline,
 *    website and contact details; with no value at all the template's sample text stays and is reported
 *    as left, so the person is told it still needs filling;
 *  - a picture slot with no usable asset stays an empty slot;
 *  - brand colours and fonts are applied ONLY when $applyBrand is true, and only to props listed in an
 *    element's `tokens`; otherwise tokens stay as inert metadata and the literal colours render.
 */
class TemplateInstantiator
{
    private const CONTACT_FALLBACKS = ['phone', 'whatsapp', 'email', 'address', 'social_handle'];

    public function __construct(private readonly AssetAccess $assets) {}

    /**
     * @param  array<string, mixed>  $brief  validated brief (BriefValidator output)
     */
    public function instantiate(DesignTemplate $template, array $brief = [], ?BrandKit $kit = null, bool $applyBrand = false, ?User $user = null): InstantiationResult
    {
        $kit?->loadMissing('assets');

        $text = $this->textValues($brief, $kit);
        $images = $this->imageValues($template, $brief, $kit, $user);

        $pages = [];
        $filled = [];
        $left = [];
        $brandApplied = [];
        $warnings = [];

        foreach ((array) $template->pages as $pageIndex => $page) {
            unset($page['id'], $page['revision']);
            $page['elements'] = $this->walk(
                (array) ($page['elements'] ?? []),
                $pageIndex + 1,
                $text,
                $images,
                $kit,
                $applyBrand,
                $filled,
                $left,
                $brandApplied,
                $warnings,
            );
            $pages[] = $page;
        }

        $warnings = array_values(array_unique($warnings));

        return new InstantiationResult($pages, (int) $template->width, (int) $template->height, $filled, $left, $brandApplied, $warnings);
    }

    /**
     * Copy the elements with fresh ids, filling bound slots.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @param  array<string, array{value: string, source: string}>  $text
     * @param  array<string, array{asset_id: ?int, source: string, reason: ?string}>  $images
     */
    private function walk(array $elements, int $pageNumber, array $text, array $images, ?BrandKit $kit, bool $applyBrand, array &$filled, array &$left, array &$brandApplied, array &$warnings): array
    {
        $out = [];

        foreach ($elements as $element) {
            $element['id'] = $this->freshId();

            if (($element['type'] ?? null) === 'group') {
                $element['children'] = $this->walk((array) ($element['children'] ?? []), $pageNumber, $text, $images, $kit, $applyBrand, $filled, $left, $brandApplied, $warnings);
            }

            $bind = $element['bind'] ?? null;

            if (is_string($bind) && $bind !== '') {
                $element = $this->fillSlot($element, $bind, $pageNumber, $text, $images, $filled, $left, $warnings);
            }

            if ($applyBrand && $kit !== null && ! empty($element['tokens']) && is_array($element['tokens'])) {
                $element = $this->applyTokens($element, $kit, $brandApplied, $warnings);
            }

            $out[] = $element;
        }

        return $out;
    }

    private function fillSlot(array $element, string $bind, int $pageNumber, array $text, array $images, array &$filled, array &$left, array &$warnings): array
    {
        $type = $element['type'] ?? null;
        $base = ['slot' => $bind, 'element_id' => $element['id'], 'page' => $pageNumber, 'name' => $element['name'] ?? null];

        if ($type === 'text') {
            if (! isset($text[$bind])) {
                $left[] = $base + ['reason' => 'no_value'];

                return $element;
            }

            $previous = (string) ($element['text'] ?? '');
            $element['text'] = $text[$bind]['value'];
            $filled[] = $base + ['source' => $text[$bind]['source'], 'type' => 'text'];

            if (($element['autoFit'] ?? 'none') === 'none' && mb_strlen($element['text']) > max(24, (int) (mb_strlen($previous) * 2))) {
                $warnings[] = 'Some text you added is much longer than the sample text it replaced, so it may not fit. Check the design.';
            }

            return $element;
        }

        if ($type === 'image') {
            $image = $images[$bind] ?? null;

            if ($image === null || $image['asset_id'] === null) {
                $left[] = $base + ['reason' => $image['reason'] ?? 'no_picture'];

                return $element;
            }

            $element['asset_id'] = $image['asset_id'];
            $filled[] = $base + ['source' => $image['source'], 'type' => 'image'];

            return $element;
        }

        $left[] = $base + ['reason' => 'unsupported'];

        return $element;
    }

    /** @param list<array<string, mixed>> $brandApplied */
    private function applyTokens(array $element, BrandKit $kit, array &$brandApplied, array &$warnings): array
    {
        foreach ($element['tokens'] as $prop => $ref) {
            if (! is_string($prop) || ! is_string($ref)) {
                continue;
            }

            $value = BrandTokens::resolve($kit, $ref);

            if ($value === null) {
                $warnings[] = "Your brand kit has no value for {$ref}, so that part keeps the template's own look.";

                continue;
            }

            $current = BrandTokens::current($element, $prop);

            if ($current === null || BrandTokens::same($prop, $current, $value)) {
                continue;
            }

            $element = BrandTokens::with($element, $prop, $value);
            $brandApplied[] = ['element_id' => $element['id'], 'prop' => $prop, 'token' => $ref, 'from' => $current, 'to' => $value];
        }

        return $element;
    }

    /** @return array<string, array{value: string, source: string}> */
    private function textValues(array $brief, ?BrandKit $kit): array
    {
        $values = [];
        $details = (array) ($brief['details'] ?? []);

        foreach ($details as $key => $value) {
            if (is_string($value) && $value !== '') {
                $values[$key] = ['value' => $value, 'source' => 'brief'];
            }
        }

        if (! empty($brief['business_name'])) {
            $values['business_name'] = ['value' => (string) $brief['business_name'], 'source' => 'brief'];
        }

        if (! isset($values['headline']) && ! empty($brief['what'])) {
            $values['headline'] = ['value' => (string) $brief['what'], 'source' => 'brief'];
        }

        if ($kit !== null) {
            if (! isset($values['tagline']) && $kit->tagline) {
                $values['tagline'] = ['value' => $kit->tagline, 'source' => 'brand'];
            }
            if (! isset($values['website']) && $kit->website) {
                $values['website'] = ['value' => $kit->website, 'source' => 'brand'];
            }
            foreach (self::CONTACT_FALLBACKS as $key) {
                $contact = ((array) $kit->contacts)[$key] ?? null;
                if (! isset($values[$key]) && is_string($contact) && $contact !== '') {
                    $values[$key] = ['value' => $contact, 'source' => 'brand'];
                }
            }
        }

        return $values;
    }

    /** @return array<string, array{asset_id: ?int, source: string, reason: ?string}> */
    private function imageValues(DesignTemplate $template, array $brief, ?BrandKit $kit, ?User $user): array
    {
        $candidates = [];

        if (! empty($brief['logo_asset_id'])) {
            $candidates['logo'] = ['id' => (int) $brief['logo_asset_id'], 'source' => 'brief'];
        } elseif ($kit !== null) {
            $dark = in_array('dark', (array) $template->styles, true);
            $asset = ($dark ? $kit->assetFor('logo-light') : null) ?? $kit->assetFor('logo');
            if ($asset !== null) {
                $candidates['logo'] = ['id' => (int) $asset->design_asset_id, 'source' => 'brand'];
            }
        }

        foreach (array_values((array) ($brief['photos'] ?? [])) as $i => $id) {
            if ($i < 3) {
                $candidates['photo_'.($i + 1)] = ['id' => (int) $id, 'source' => 'brief'];
            }
        }

        $visible = $this->assets->visibleIds($user, array_column($candidates, 'id'));
        $images = [];

        foreach ($candidates as $slot => $candidate) {
            $images[$slot] = in_array($candidate['id'], $visible, true)
                ? ['asset_id' => $candidate['id'], 'source' => $candidate['source'], 'reason' => null]
                : ['asset_id' => null, 'source' => $candidate['source'], 'reason' => 'not_visible'];
        }

        return $images;
    }

    private function freshId(): string
    {
        return Str::lower((string) Str::ulid());
    }
}
