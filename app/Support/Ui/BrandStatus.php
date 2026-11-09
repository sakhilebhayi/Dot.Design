<?php

namespace App\Support\Ui;

use App\Models\BrandKit;
use App\Models\DesignBrandUpdate;
use App\Models\User;
use App\Services\Design\BrandKitService;
use Illuminate\Database\QueryException;

/**
 * What the home screen says about the workspace's brand kit: the default kit of the current workspace (or the person's own),
 * its colours and fonts, and how many designs are waiting on a brand update. It never invents a kit: with none, it returns null.
 */
class BrandStatus
{
    /** @return array{id: int, name: string, colors: list<string>, fonts: list<string>, version: ?int, outdated: int}|null */
    public static function for(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        try {
            $kit = app(BrandKitService::class)->defaultFor($user) ?? BrandKit::query()->orderByDesc('is_default')->orderByDesc('id')->first();

            if (! $kit) {
                return null;
            }

            $outdated = DesignBrandUpdate::query()
                ->where('brand_kit_id', $kit->id)
                ->where('status', DesignBrandUpdate::PENDING)
                ->whereHas('project')
                ->count();
        } catch (QueryException) {
            return null;
        }

        $colors = collect((array) $kit->colors)
            ->map(fn ($c) => is_array($c) ? ($c['hex'] ?? null) : null)
            ->filter(fn ($hex) => is_string($hex) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $hex))
            ->take(8)->values()->all();

        $fonts = collect((array) $kit->fonts)->filter(fn ($f) => is_string($f) && $f !== '')->unique()->values()->all();

        return ['id' => $kit->id, 'name' => (string) $kit->name, 'colors' => $colors, 'fonts' => $fonts, 'version' => (int) $kit->version, 'outdated' => $outdated];
    }
}
