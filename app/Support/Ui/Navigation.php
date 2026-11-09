<?php

namespace App\Support\Ui;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The destinations of the signed-in shell.
 *
 * Every planned destination lists the route names it may live under; an item is shown only when one of them is registered, so a
 * destination built later (templates, brand, assets, review) appears in the rail and the phone bar the moment its route exists,
 * and never as a dead link before that.
 */
class Navigation
{
    /**
     * Planned destinations, in display order, per group.
     * `routes`: candidate route names (first registered one wins). `match`: route-name patterns that mark the item current.
     * `primary`: shown in the phone tab bar when there is room (the rest open in the "More" sheet).
     *
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public static function plan(): array
    {
        return [
            ['key' => 'studio', 'label' => 'Studio', 'items' => [
                ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'routes' => ['dashboard'], 'match' => ['dashboard'], 'primary' => true],
                ['key' => 'designs', 'label' => 'Designs', 'icon' => 'layers', 'routes' => ['design.projects.index'], 'match' => ['design.projects.*'], 'primary' => true],
                ['key' => 'templates', 'label' => 'Templates', 'icon' => 'template', 'routes' => ['design.templates.index', 'templates.index'], 'match' => ['design.templates.*', 'templates.*'], 'primary' => true],
                ['key' => 'brand', 'label' => 'Brand', 'icon' => 'swatch', 'routes' => ['design.brand.index', 'brand.index', 'design.brand-kits.index'], 'match' => ['design.brand.*', 'brand.*', 'design.brand-kits.*'], 'primary' => true],
                ['key' => 'assets', 'label' => 'Assets', 'icon' => 'images', 'routes' => ['design.assets.index', 'assets.index'], 'match' => ['design.assets.index', 'assets.*'], 'primary' => false],
                ['key' => 'review', 'label' => 'Review', 'icon' => 'badge-check', 'routes' => ['design.reviews.index', 'reviews.index'], 'match' => ['design.reviews.*', 'reviews.*'], 'primary' => false],
            ]],
            ['key' => 'library', 'label' => 'Library', 'items' => [
                ['key' => 'tokens', 'label' => 'Token sets', 'icon' => 'palette', 'routes' => ['design-system.token-sets.index'], 'match' => ['design-system.token-sets.*'], 'primary' => false],
                ['key' => 'components', 'label' => 'Components', 'icon' => 'layout-grid', 'routes' => ['design-system.components.index'], 'match' => ['design-system.components.*'], 'primary' => false],
            ]],
        ];
    }

    /**
     * @return array{groups: list<array{key: string, label: string, items: list<array<string, mixed>>}>, primary: list<array<string, mixed>>, secondary: list<array<string, mixed>>, profile: ?string, team: ?string}
     */
    public static function build(?Request $request = null): array
    {
        $request ??= request();
        $groups = [];
        $primary = [];
        $secondary = [];

        foreach (self::plan() as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                $name = collect($item['routes'])->first(fn (string $candidate) => Route::has($candidate));
                if ($name === null) {
                    continue;
                }
                $built = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'route' => $name,
                    'href' => route($name),
                    'current' => $request->routeIs(...$item['match']),
                    'primary' => $item['primary'],
                ];
                $items[] = $built;
                if ($item['primary']) {
                    $primary[] = $built;
                } else {
                    $secondary[] = $built;
                }
            }
            if ($items !== []) {
                $groups[] = ['key' => $group['key'], 'label' => $group['label'], 'items' => $items];
            }
        }

        $user = $request->user();
        $team = $user && $user->currentTeam && Route::has('teams.show') ? route('teams.show', $user->currentTeam->id) : null;

        return [
            'groups' => $groups,
            'primary' => array_slice($primary, 0, 4),
            // anything that did not fit in the phone bar goes to the sheet
            'secondary' => array_merge(array_slice($primary, 4), $secondary),
            'profile' => Route::has('profile.show') ? route('profile.show') : null,
            'team' => $team,
        ];
    }
}
