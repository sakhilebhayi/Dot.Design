<?php

namespace App\Services\Design\Assistant;

use App\Models\DesignProject;

final class ComposeOutcome
{
    /**
     * @param  list<array{id: string, text: string}>  $questions
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly string $status,
        public readonly ?DesignProject $project = null,
        public readonly array $questions = [],
        public readonly array $details = [],
    ) {}

    public static function created(DesignProject $project, array $details): self
    {
        return new self('ok', $project, [], $details);
    }

    public static function asking(array $questions): self
    {
        return new self('questions', null, $questions);
    }
}
