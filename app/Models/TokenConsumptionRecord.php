<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks which ecosystem platform consumes which token set, pinned to which
 * version. Simple join/tracking table — no drift detection or breaking-change
 * notice mechanism is implemented in this MVP.
 */
class TokenConsumptionRecord extends Model
{
    protected $fillable = ['token_set_id', 'platform_id', 'pinned_version', 'last_synced_at'];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function tokenSet(): BelongsTo
    {
        return $this->belongsTo(TokenSet::class);
    }
}
