<?php

namespace App\Services\Design\Assistant;

use App\Models\BrandKit;
use App\Models\User;
use App\Services\Design\Formats;

/**
 * Checks the model's plan against what really exists. The model can only pick from the catalogue; code decides what
 * is used. Unknown templates, formats and styles are replaced by safe choices (and said so), copy is cleaned and
 * fact-checked against the person's own words, and a business name is accepted only if the person typed it.
 */
class PlanValidator
{
    private const COPY_LIMITS = ['headline' => 80, 'subheadline' => 120, 'tagline' => 80, 'offer' => 80, 'cta' => 40, 'description' => 400];

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly Formats $formats,
        private readonly LiteralExtractor $literals,
    ) {}

    /**
     * @param  array<string, mixed>  $plan  the model's tool input (untrusted)
     * @param  string  $source  everything the person typed (prompt and answers), the only source of facts
     * @param  array<string, string>  $literals  deterministic literals, which win over the model
     */
    public function validate(array $plan, User $user, string $source, array $literals, ?string $requestedFormat, ?BrandKit $kit, bool $answered): ValidPlan
    {
        $notes = [];

        $template = null;
        $slug = is_string($plan['template_slug'] ?? null) ? $plan['template_slug'] : 'blank';
        if ($slug !== 'blank') {
            $template = $this->catalogue->templateBySlug($slug, $user);
            if ($template === null) {
                $notes[] = 'The assistant picked a template that is not available, so you start from a blank page.';
            }
        }

        $planFormat = is_string($plan['format_key'] ?? null) && $this->formats->has($plan['format_key']) ? $plan['format_key'] : null;
        $formatKey = $requestedFormat ?? $template?->format_key ?? $planFormat;

        if ($template !== null && $requestedFormat !== null && $template->format_key !== $requestedFormat) {
            $template = $this->sameFormatAlternative($user, $requestedFormat, $template);
            if ($template === null) {
                $notes[] = 'No template fits that size, so you start from a blank page of the right size.';
            }
        }

        $formatKey ??= 'a4-portrait';

        $style = is_string($plan['style'] ?? null) && config("design_brief_flow.styles.{$plan['style']}") !== null ? $plan['style'] : null;

        $name = $literals['business_name'] ?? $this->literals->groundedName(is_string($plan['business_name'] ?? null) ? $plan['business_name'] : null, $source);

        $copy = [];
        foreach ((array) ($plan['copy'] ?? []) as $slot => $value) {
            if (! is_string($slot) || ! isset(self::COPY_LIMITS[$slot]) || $value === null) {
                continue;
            }

            if (isset($literals[$slot])) {
                continue; // the person's own words win
            }

            $text = TextGuard::clean($value, self::COPY_LIMITS[$slot]);
            if ($text === null) {
                continue;
            }

            if ($name !== null && mb_strtolower($text) === mb_strtolower($name) && $slot !== 'headline') {
                continue; // the name has its own slot
            }

            if (! TextGuard::supported($text, $source)) {
                $notes[] = 'The assistant suggested a '.mb_strtolower((string) config("design_copy.slots.$slot.label", $slot)).' with a number or contact detail you did not give, so it was left out.';

                continue;
            }

            $copy[$slot] = $text;
        }

        $questions = [];
        if (! $answered) {
            foreach ((array) ($plan['questions'] ?? []) as $question) {
                $id = is_array($question) ? ($question['id'] ?? null) : null;
                $text = is_array($question) ? TextGuard::clean($question['text'] ?? null, 160) : null;
                if (! is_string($id) || ! in_array($id, Prompts::QUESTION_IDS, true) || $text === null) {
                    continue;
                }
                if ($this->alreadyKnown($id, $literals, $name)) {
                    continue;
                }
                $questions[$id] = ['id' => $id, 'text' => $text];
            }
        }

        return new ValidPlan(
            $template,
            $formatKey,
            $style,
            $kit !== null && ($plan['palette'] ?? 'brand') !== 'template',
            $copy,
            $name,
            array_slice(array_values($questions), 0, 2),
            array_values(array_unique($notes)),
        );
    }

    private function alreadyKnown(string $id, array $literals, ?string $name): bool
    {
        return match ($id) {
            'business_name' => $name !== null,
            'date' => isset($literals['date']),
            'venue' => isset($literals['address']),
            'offer' => isset($literals['price']),
            default => false,
        };
    }

    private function sameFormatAlternative(User $user, string $formatKey, $chosen)
    {
        foreach ($this->catalogue->build($user, $formatKey)['templates'] as $candidate) {
            $style = array_intersect((array) $chosen->styles, $candidate['styles']);
            if ($style !== [] || $candidate['kind'] === $chosen->kind) {
                return $this->catalogue->templateBySlug($candidate['slug'], $user);
            }
        }

        $first = $this->catalogue->build($user, $formatKey)['templates'][0] ?? null;

        return $first === null ? null : $this->catalogue->templateBySlug($first['slug'], $user);
    }
}
