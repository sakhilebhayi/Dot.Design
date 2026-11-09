<?php

namespace App\Models;

use App\Models\Concerns\ScopesToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workspace's brand: colours (with roles), fonts, type styles, voice, logos.
 * `version` goes up whenever any of it changes; designs record the version they
 * last took from the kit, and never change until someone applies an update.
 */
class BrandKit extends Model
{
    use ScopesToWorkspace;

    /** What counts as "the kit changed". Anything else (name, is_default) does not bump the version. */
    public const CONTENT_FIELDS = ['colors', 'fonts', 'type_styles', 'guidelines', 'voice', 'tagline', 'website', 'contacts'];

    protected $fillable = [
        'team_id', 'user_id', 'name', 'is_default', 'version',
        'colors', 'fonts', 'type_styles', 'guidelines', 'voice', 'tagline', 'website', 'contacts',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'version' => 'integer',
        'colors' => 'array',
        'fonts' => 'array',
        'type_styles' => 'array',
        'voice' => 'array',
        'contacts' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(BrandAsset::class);
    }

    /** The hex of a colour by its key (primary, secondary, accent, neutral or an extra key), or by role as a fallback. */
    public function colorHex(string $key): ?string
    {
        $colors = (array) $this->colors;

        foreach ($colors as $color) {
            if (($color['key'] ?? null) === $key) {
                return $color['hex'] ?? null;
            }
        }

        foreach ($colors as $color) {
            if (($color['role'] ?? null) === $key) {
                return $color['hex'] ?? null;
            }
        }

        return null;
    }

    public function fontKey(string $slot): ?string
    {
        return ((array) $this->fonts)[$slot] ?? null;
    }

    /** The asset row for a role (logo, logo-light, mark...), first one if several. */
    public function assetFor(string $role): ?BrandAsset
    {
        return $this->assets->firstWhere('role', $role);
    }
}
