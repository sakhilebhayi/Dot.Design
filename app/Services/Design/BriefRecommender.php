<?php

namespace App\Services\Design;

use App\Models\DesignTemplate;
use App\Models\User;

/**
 * Deterministic recommendations from a brief: no AI, no randomness, no clock. The same brief and
 * the same set of templates always produce the same ranked list, and every entry carries a
 * plain-language reason a business owner can read ("Made for announcing a sale. Matches the Bold
 * look you chose.").
 *
 * Scoring (points; see WEIGHTS):
 *   goal purpose match   30     template.purposes contains the goal's purpose
 *   kind position        24..4  the template's kind, best-first in the goal's list (3 points less per place)
 *   exact size           30     template format equals the size the person picked
 *   usual size           12/8/5 no size picked: the goal's first, second, third usual size
 *   same size family     10     close to the picked size (it adapts easily)
 *   style                25     template.styles contains the chosen style
 *   industry             15     template.industries contains the stated or guessed industry
 *   any business         3      template is for any business and the industry is unknown or unmatched
 *   own template         5      saved by the person's team or by themselves
 *   popularity           0..10  editorial popularity / 10
 *   usage                0..5   log10(times used + 1) * 2, capped
 * Ties break on popularity, then slug, so order never depends on database row order.
 */
class BriefRecommender
{
    public const WEIGHTS = [
        'purpose' => 30,
        'kind_top' => 24,
        'kind_step' => 3,
        'kind_floor' => 4,
        'format_exact' => 30,
        'format_usual' => [12, 8, 5],
        'format_family' => 10,
        'style' => 25,
        'industry' => 15,
        'any_business' => 3,
        'own' => 5,
    ];

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly Formats $formats,
        private readonly BriefFlow $flow,
    ) {}

    /**
     * Ranked templates and recommended formats for a validated brief, from what the user can see.
     *
     * @param  array<string, mixed>  $brief  as returned by BriefValidator
     * @return array{templates: list<array<string, mixed>>, formats: list<array<string, mixed>>, industry: ?string, goal: ?array<string, mixed>}
     */
    public function recommend(array $brief, ?User $user = null, ?int $limit = null): array
    {
        $ranked = $this->rank($brief, $this->templates->candidates($user), $user);
        $limit ??= (int) config('design_brief_flow.limits.recommendations', 12);

        return [
            'templates' => array_slice($ranked, 0, $limit),
            'formats' => $this->recommendFormats($brief),
            'industry' => $this->inferIndustry($brief),
            'goal' => isset($brief['goal']) ? $this->flow->goal($brief['goal']) : null,
        ];
    }

    /**
     * Rank a given set of templates. Pure: nothing is read from the database here.
     *
     * @param  iterable<DesignTemplate>  $templates
     * @return list<array<string, mixed>> entries with template summary fields plus score, reasons, reason, match
     */
    public function rank(array $brief, iterable $templates, ?User $user = null): array
    {
        $goal = isset($brief['goal']) ? $this->flow->goal($brief['goal']) : null;
        $industry = $this->inferIndustry($brief);
        $style = $brief['style'] ?? null;
        $chosenFormat = $brief['format_key'] ?? null;
        $chosenFamily = $chosenFormat && $this->formats->has($chosenFormat) ? $this->formats->get($chosenFormat)['family'] : null;
        $usual = $goal['formats'] ?? [];
        $kinds = $goal['kinds'] ?? [];
        $purpose = $goal['purpose'] ?? null;

        $scored = [];

        foreach ($templates as $template) {
            $score = 0;
            $reasons = [];
            $match = ['purpose' => false, 'kind' => false, 'format' => false, 'style' => false, 'industry' => false];

            $purposes = (array) $template->purposes;
            $templateKindIndex = array_search($template->kind, $kinds, true);

            if ($purpose !== null && in_array($purpose, $purposes, true)) {
                $score += self::WEIGHTS['purpose'];
                $match['purpose'] = true;
                $reasons[] = $this->phrase('goal', ['phrase' => $goal['phrase']]);
            }

            if ($templateKindIndex !== false) {
                $score += max(self::WEIGHTS['kind_floor'], self::WEIGHTS['kind_top'] - self::WEIGHTS['kind_step'] * $templateKindIndex);
                $match['kind'] = true;

                if (! $match['purpose']) {
                    $reasons[] = $this->phrase('kind', ['kind' => $this->kindLabel($template->kind)]);
                }
            }

            // Size
            $templateFormat = $template->format_key;
            if ($chosenFormat !== null && $templateFormat === $chosenFormat) {
                $score += self::WEIGHTS['format_exact'];
                $match['format'] = true;
                $reasons[] = $this->phrase('format', ['format' => $this->formatLabel($chosenFormat)]);
            } elseif ($chosenFamily !== null && $templateFormat !== null && $this->formats->has($templateFormat) && $this->formats->get($templateFormat)['family'] === $chosenFamily) {
                $score += self::WEIGHTS['format_family'];
                $reasons[] = $this->phrase('family', ['format' => $this->formatLabel($chosenFormat)]);
            } elseif ($chosenFormat === null && $templateFormat !== null) {
                $position = array_search($templateFormat, array_slice($usual, 0, count(self::WEIGHTS['format_usual'])), true);
                if ($position !== false) {
                    $score += self::WEIGHTS['format_usual'][$position];
                }
            }

            // Look
            if ($style !== null && in_array($style, (array) $template->styles, true)) {
                $score += self::WEIGHTS['style'];
                $match['style'] = true;
                $reasons[] = $this->phrase('style', ['style' => config("design_brief_flow.styles.$style.label", $style)]);
            }

            // Kind of business
            $industries = (array) $template->industries;
            if ($industry !== null && in_array($industry, $industries, true)) {
                $score += self::WEIGHTS['industry'];
                $match['industry'] = true;
                $reasons[] = $this->phrase('industry', ['industry' => config("design_brief_flow.industries.$industry.label", $industry)]);
            } elseif ($industries === [] || in_array('general', $industries, true)) {
                $score += self::WEIGHTS['any_business'];
            }

            if (in_array($template->source, ['team', 'user'], true) && ($template->user_id === $user?->id || $template->source === 'team')) {
                $score += self::WEIGHTS['own'];
                $reasons[] = $this->phrase('yours');
            }

            $score += (int) min(10, intdiv(max(0, (int) $template->popularity), 10));
            $score += (int) min(5, floor(log10((int) $template->uses + 1) * 2));

            $relevant = $match['purpose'] || $match['kind'] || ($goal === null) || ($brief['goal'] ?? null) === 'something_else';

            $scored[] = ['template' => $template, 'score' => $score, 'reasons' => $reasons, 'match' => $match, 'relevant' => $relevant];
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], (int) $b['template']->popularity, $a['template']->slug]
            <=> [$a['score'], (int) $a['template']->popularity, $b['template']->slug]);

        $relevant = array_values(array_filter($scored, fn (array $row) => $row['relevant']));
        $fallback = array_values(array_filter($scored, fn (array $row) => ! $row['relevant']));

        // Keep the list useful when few templates fit: top up with general good starting points, labelled as such.
        $minimum = 4;
        if (count($relevant) < $minimum) {
            foreach (array_slice($fallback, 0, $minimum - count($relevant)) as $row) {
                $row['reasons'] = [$this->phrase('fallback')];
                $relevant[] = $row;
            }
        }

        return array_map(fn (array $row) => $this->entry($row), $relevant);
    }

    /**
     * Recommended sizes for the brief: the one chosen first, then the goal's usual sizes, then same-family
     * variants of the chosen one. Each carries a plain reason.
     *
     * @return list<array<string, mixed>>
     */
    public function recommendFormats(array $brief): array
    {
        $goal = isset($brief['goal']) ? $this->flow->goal($brief['goal']) : null;
        $out = [];
        $seen = [];

        $push = function (string $key, string $reason) use (&$out, &$seen): void {
            if (isset($seen[$key]) || ! $this->formats->has($key)) {
                return;
            }
            $seen[$key] = true;
            $out[] = $this->flow->formatOption($key) + ['reason' => $reason, 'popular' => $this->formats->get($key)['popular']];
        };

        $chosen = $brief['format_key'] ?? null;
        if ($chosen !== null) {
            $push($chosen, 'The size you picked');
        }

        foreach ($goal['formats'] ?? [] as $i => $key) {
            $push($key, $i === 0
                ? 'The most-used size for '.$goal['phrase']
                : 'Also popular for '.$goal['phrase']);
        }

        if ($chosen !== null && $this->formats->has($chosen)) {
            foreach ($this->formats->familyOf($chosen) as $key => $format) {
                $push($key, 'Same shape as '.$this->formatLabel($chosen).', so it is quick to adapt');
            }
        }

        return $out;
    }

    /** Best guess at the kind of business from the words typed, or the industry the person stated. */
    public function inferIndustry(array $brief): ?string
    {
        if (! empty($brief['industry'])) {
            return $brief['industry'];
        }

        $text = mb_strtolower(implode(' ', array_filter([
            $brief['business_name'] ?? null,
            $brief['what'] ?? null,
            $brief['details']['description'] ?? null,
            $brief['details']['offer'] ?? null,
            $brief['details']['headline'] ?? null,
            $brief['details']['tagline'] ?? null,
        ])));

        if (trim($text) === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ((array) config('design_brief_flow.industries') as $key => $industry) {
            $score = 0;
            foreach ($industry['keywords'] as $keyword) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/u', $text)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $row */
    private function entry(array $row): array
    {
        /** @var DesignTemplate $template */
        $template = $row['template'];
        $reasons = array_slice($row['reasons'], 0, 3);

        if ($reasons === []) {
            $reasons = [$this->phrase('fallback')];
        }

        return $this->templates->summary($template) + [
            'score' => $row['score'],
            'reasons' => $reasons,
            'reason' => implode('. ', $reasons).'.',
            'match' => $row['match'],
        ];
    }

    private function phrase(string $key, array $replace = []): string
    {
        $text = (string) config("design_copy.reasons.$key");

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    private function formatLabel(string $key): string
    {
        return $this->formats->get($key)['label'];
    }

    private function kindLabel(string $kind): string
    {
        return mb_strtolower((string) config("design_copy.template_kinds.$kind", str_replace('-', ' ', $kind)));
    }
}
