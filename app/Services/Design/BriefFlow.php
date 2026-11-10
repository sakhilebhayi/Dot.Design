<?php

namespace App\Services\Design;

/**
 * The guided brief as data: which questions to ask, in what order, with what help text.
 * Pure config reading; the UI renders whatever comes back and invents no wording.
 */
class BriefFlow
{
    public function __construct(private readonly Formats $formats) {}

    /** The whole flow, for the first request of the guided brief. */
    public function describe(): array
    {
        $fields = (array) config('design_brief_flow.fields');

        return [
            'steps' => $this->steps(),
            'goals' => $this->goals(),
            'styles' => $this->styles(),
            'fields' => array_map(fn (array $f, string $key) => ['key' => $key] + $f, $fields, array_keys($fields)),
            'limits' => (array) config('design_brief_flow.limits'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return array_map(fn (array $step) => $step + ['skippable' => ! $step['essential']], array_values((array) config('design_brief_flow.steps')));
    }

    /** @return list<array<string, mixed>> */
    public function goals(): array
    {
        $out = [];

        foreach ((array) config('design_brief_flow.goals') as $key => $goal) {
            $out[] = [
                'key' => $key,
                'label' => $goal['label'],
                'help' => $goal['help'],
                'icon' => $goal['icon'],
                'purpose' => $goal['purpose'],
                'phrase' => $goal['phrase'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function styles(): array
    {
        $out = [];

        foreach ((array) config('design_brief_flow.styles') as $key => $style) {
            $out[] = ['key' => $key] + $style;
        }

        return $out;
    }

    public function hasGoal(string $goal): bool
    {
        return config("design_brief_flow.goals.$goal") !== null;
    }

    /** @return array<string, mixed>|null the goal's config with its key added */
    public function goal(string $goal): ?array
    {
        $config = config("design_brief_flow.goals.$goal");

        return is_array($config) ? ['key' => $goal] + $config : null;
    }

    /** The flow narrowed to one goal: only the detail fields that make sense for it, and the sizes it usually needs. */
    public function forGoal(string $goal): ?array
    {
        $config = $this->goal($goal);

        if ($config === null) {
            return null;
        }

        $fields = (array) config('design_brief_flow.fields');

        $detailFields = [];
        foreach ($config['fields'] as $key) {
            $detailFields[] = ['key' => $key] + $fields[$key];
        }

        $steps = [];
        foreach ($this->steps() as $step) {
            if ($step['key'] === 'details') {
                $step['fields'] = $detailFields;
            }
            if ($step['key'] === 'where') {
                $step['options'] = array_map(fn (string $key) => $this->formatOption($key), $config['formats']);
                $step['see_all'] = true;
            }
            $steps[] = $step;
        }

        return [
            'goal' => ['key' => $goal, 'label' => $config['label'], 'help' => $config['help'], 'phrase' => $config['phrase']],
            'steps' => $steps,
            'styles' => $this->styles(),
        ];
    }

    /** @return array<string, mixed> */
    public function formatOption(string $key): array
    {
        $format = $this->formats->get($key);

        return [
            'key' => $key,
            'label' => $format['label'],
            'use' => $format['use'],
            'size_label' => $format['size_label'],
            'kind' => $format['kind'],
            'notes' => $format['notes'],
            'orientation' => $format['orientation'],
        ];
    }
}
