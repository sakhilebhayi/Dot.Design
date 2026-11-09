<?php

namespace App\Services\Design\Assistant;

use App\Models\DesignTemplate;

/** A plan after code has checked it against the catalogue. Everything here is safe to build from. */
final class ValidPlan
{
    /**
     * @param  array<string, string>  $copy  slot key => cleaned text, model-written and fact-checked
     * @param  list<array{id: string, text: string}>  $questions
     * @param  list<string>  $notes  plain-language things the person should know (dropped copy, substituted template)
     */
    public function __construct(
        public readonly ?DesignTemplate $template,
        public readonly string $formatKey,
        public readonly ?string $style,
        public readonly bool $useBrand,
        public readonly array $copy,
        public readonly ?string $businessName,
        public readonly array $questions,
        public readonly array $notes,
    ) {}
}
