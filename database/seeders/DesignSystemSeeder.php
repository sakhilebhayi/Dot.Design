<?php

namespace Database\Seeders;

use App\Models\Component;
use App\Models\TokenSet;
use Illuminate\Database\Seeder;

/**
 * Seeds a handful of real starter tokens and example components for the
 * design-token/component-library domain (MVP scaffold), plus consumption
 * records for the ~20 Dot Ecosystem platforms (from Dot.Brain's
 * brain.platforms.md registry), all pinned to version 0 as placeholders.
 */
class DesignSystemSeeder extends Seeder
{
    /**
     * Platform IDs from Dot.Brain's brain.platforms.md §2 registry.
     */
    private const PLATFORMS = [
        'dot-brain', 'dot-memory', 'dot-analytics', 'dot-pulse', 'dot-plug',
        'dot-mines', 'dot-notify', 'dot-billing', 'dot-charts', 'dot-farms',
        'dot-hr', 'dot-dopemine', 'dot-emall', 'dot-ehail', 'dot-agents',
        'dot-auction', 'dot-central', 'dot-projects', 'dot-tasks',
        'dot-design', 'dot-finance',
    ];

    public function run(): void
    {
        $colors = TokenSet::updateOrCreate(
            ['slug' => 'core-palette'],
            [
                'name' => 'Core Palette',
                'description' => 'Base ecosystem color tokens.',
                'version' => 1,
            ]
        );

        foreach ([
            ['name' => 'Primary', 'slug' => 'color-primary', 'value' => '#d946ef'],
            ['name' => 'Primary Dark', 'slug' => 'color-primary-dark', 'value' => '#a21caf'],
            ['name' => 'Surface', 'slug' => 'color-surface', 'value' => '#18181b'],
            ['name' => 'Text', 'slug' => 'color-text', 'value' => '#f4f4f5'],
            ['name' => 'Muted Text', 'slug' => 'color-text-muted', 'value' => '#71717a'],
        ] as $token) {
            $colors->tokens()->updateOrCreate(
                ['slug' => $token['slug']],
                ['name' => $token['name'], 'category' => 'color', 'value' => $token['value'], 'version' => 1]
            );
        }

        $spacing = TokenSet::updateOrCreate(
            ['slug' => 'spacing-scale'],
            [
                'name' => 'Spacing Scale',
                'description' => 'Base spacing scale, in rem.',
                'version' => 1,
            ]
        );

        foreach ([
            ['name' => 'Space XS', 'slug' => 'space-xs', 'value' => '0.25rem'],
            ['name' => 'Space SM', 'slug' => 'space-sm', 'value' => '0.5rem'],
            ['name' => 'Space MD', 'slug' => 'space-md', 'value' => '1rem'],
            ['name' => 'Space LG', 'slug' => 'space-lg', 'value' => '2rem'],
            ['name' => 'Space XL', 'slug' => 'space-xl', 'value' => '4rem'],
        ] as $token) {
            $spacing->tokens()->updateOrCreate(
                ['slug' => $token['slug']],
                ['name' => $token['name'], 'category' => 'spacing', 'value' => $token['value'], 'version' => 1]
            );
        }

        $type = TokenSet::updateOrCreate(
            ['slug' => 'type-scale'],
            [
                'name' => 'Type Scale',
                'description' => 'Base type scale, in rem.',
                'version' => 1,
            ]
        );

        foreach ([
            ['name' => 'Body', 'slug' => 'type-body', 'value' => '0.85rem'],
            ['name' => 'Heading SM', 'slug' => 'type-heading-sm', 'value' => '1.1rem'],
            ['name' => 'Heading LG', 'slug' => 'type-heading-lg', 'value' => '1.6rem'],
        ] as $token) {
            $type->tokens()->updateOrCreate(
                ['slug' => $token['slug']],
                ['name' => $token['name'], 'category' => 'type', 'value' => $token['value'], 'version' => 1]
            );
        }

        // Example components, including one Brain-surface component.
        Component::updateOrCreate(
            ['slug' => 'button'],
            [
                'name' => 'Button',
                'category' => 'general',
                'is_brain_surface' => false,
                'version' => 1,
                'description' => 'Standard call-to-action button.',
                'props_schema' => ['label' => 'string', 'variant' => 'primary|secondary|ghost'],
            ]
        );

        Component::updateOrCreate(
            ['slug' => 'card'],
            [
                'name' => 'Card',
                'category' => 'general',
                'is_brain_surface' => false,
                'version' => 1,
                'description' => 'Generic content container.',
                'props_schema' => ['title' => 'string', 'body' => 'string'],
            ]
        );

        Component::updateOrCreate(
            ['slug' => 'confidence-badge'],
            [
                'name' => 'Confidence Badge',
                'category' => 'brain-surface',
                'is_brain_surface' => true,
                'version' => 1,
                'description' => 'Renders a Dot.Brain recommendation confidence score. Not yet certified — comprehension gate is roadmap, not implemented.',
                'props_schema' => ['score' => 'float(0-1)', 'label' => 'string'],
            ]
        );

        // Consumption records: every ecosystem platform, pinned to version 0
        // (placeholder — nothing has actually synced yet).
        foreach ([$colors, $spacing, $type] as $tokenSet) {
            foreach (self::PLATFORMS as $platformId) {
                $tokenSet->consumptionRecords()->updateOrCreate(
                    ['platform_id' => $platformId],
                    ['pinned_version' => 0]
                );
            }
        }
    }
}
