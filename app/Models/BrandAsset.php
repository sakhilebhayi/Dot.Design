<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A design asset (an uploaded picture) filed in a brand kit under a role such as logo. */
class BrandAsset extends Model
{
    public const ROLES = ['logo', 'logo-light', 'mark', 'image', 'pattern', 'background'];

    /** Roles where a kit holds only one asset; setting a new one replaces the old. */
    public const SINGLE_ROLES = ['logo', 'logo-light', 'mark'];

    protected $fillable = ['brand_kit_id', 'design_asset_id', 'role', 'label'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class, 'brand_kit_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DesignAsset::class, 'design_asset_id');
    }
}
