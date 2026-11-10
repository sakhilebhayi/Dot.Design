<?php

namespace App\Services\Design\Assistant;

/** Turns the model's flat tool answer into canonical operations (still untrusted; OpValidator decides). */
final class ModelOps
{
    private const NUMERIC = ['fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'x', 'y', 'width', 'height', 'opacity', 'rotation'];

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public static function fromToolInput(array $input): array
    {
        $ops = [];
        $sets = [];

        foreach ((array) ($input['set'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['prop'] ?? null)) {
                continue;
            }
            $prop = $item['prop'];
            $value = $item['value'] ?? null;

            if (in_array($prop, self::NUMERIC, true)) {
                if (! is_numeric($value)) {
                    continue;
                }
                $value = (float) $value;
            } elseif ($prop === 'shadow') {
                $value = self::shadow($value);
                if ($value === false) {
                    continue;
                }
            } elseif (! is_string($value)) {
                continue;
            }

            $sets[$item['id']][$prop] = $value;
        }

        foreach ($sets as $id => $props) {
            $ops[] = ['op' => 'set', 'id' => $id, 'props' => $props];
        }

        foreach ((array) ($input['add'] ?? []) as $item) {
            if (! is_array($item) || ! in_array($item['type'] ?? null, ['text', 'shape'], true)) {
                continue;
            }

            $element = $item['type'] === 'text'
                ? array_intersect_key($item, array_flip(['type', 'text', 'x', 'y', 'width', 'height', 'fontSize', 'fontWeight', 'font', 'color', 'align']))
                : ['type' => 'shape', 'kind' => $item['shape_kind'] ?? 'rect', 'x' => $item['x'] ?? null, 'y' => $item['y'] ?? null, 'width' => $item['width'] ?? null, 'height' => $item['height'] ?? null, 'fill' => $item['fill'] ?? null, 'opacity' => $item['opacity'] ?? 1];

            $behind = isset($item['behind_id']) && is_string($item['behind_id']) && $item['behind_id'] !== '' ? $item['behind_id'] : null;
            $ops[] = ['op' => 'add', 'element' => $element, 'behind_id' => $behind];
        }

        foreach ((array) ($input['reorder'] ?? []) as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['to'] ?? null)) {
                $ops[] = ['op' => 'reorder', 'id' => $item['id'], 'to' => $item['to']];
            }
        }

        return $ops;
    }

    /** @return array<string, mixed>|null|false null = remove, false = unusable */
    private static function shadow(mixed $value): array|null|false
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '' || strtolower($value) === 'none') {
            return null;
        }

        if (! preg_match('/^(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(#[0-9a-fA-F]{3,6})(?:\s+(\d?\.?\d+))?$/', $value, $m)) {
            return false;
        }

        return ['x' => (float) $m[1], 'y' => (float) $m[2], 'blur' => (float) $m[3], 'color' => $m[4], 'opacity' => isset($m[5]) ? (float) $m[5] : 0.25];
    }
}
