<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned set of design tokens (e.g. "Core Palette", "Spacing Scale").
 * Global/shared — not tenant-scoped. See migration for tenancy rationale.
 */
class TokenSet extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'version'];

    public function tokens(): HasMany
    {
        return $this->hasMany(DesignToken::class);
    }

    public function consumptionRecords(): HasMany
    {
        return $this->hasMany(TokenConsumptionRecord::class);
    }

    public function driftNotices(): HasMany
    {
        return $this->hasMany(TokenDriftNotice::class);
    }
}
