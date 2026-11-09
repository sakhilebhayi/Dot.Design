<?php

namespace App\Support\Ui;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * What the home screen may honestly say about the workspace's brand kit.
 *
 * The brand kit model and table belong to the business track and may not exist yet. Until they do (or when the user has no kit),
 * this returns null and the screen says "No brand kit yet". It never invents a kit.
 */
class BrandStatus
{
    /** @return array{name: string, colors: list<string>, fonts: list<string>, version: ?int}|null */
    public static function for(?User $user): ?array
    {
        $model = '\\App\\Models\\BrandKit';
        if (! $user || ! class_exists($model) || ! Schema::hasTable('brand_kits')) {
            return null;
        }

        try {
            $kit = $model::query()
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                    if ($user->current_team_id && Schema::hasColumn('brand_kits', 'team_id')) {
                        $query->orWhere('team_id', $user->current_team_id);
                    }
                })
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->first();
        } catch (QueryException) {
            return null;
        }

        if (! $kit) {
            return null;
        }

        $colors = collect(self::asArray($kit->colors ?? []))
            ->map(fn ($c) => is_array($c) ? ($c['hex'] ?? null) : $c)
            ->filter(fn ($hex) => is_string($hex) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $hex))
            ->take(8)->values()->all();
        $fonts = collect(self::asArray($kit->fonts ?? []))->filter(fn ($f) => is_string($f) && $f !== '')->values()->all();

        return ['name' => (string) $kit->name, 'colors' => $colors, 'fonts' => $fonts, 'version' => isset($kit->version) ? (int) $kit->version : null];
    }

    /** @return array<mixed> */
    private static function asArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
