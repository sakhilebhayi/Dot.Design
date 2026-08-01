<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single design token (color, type, spacing, motion).
 * Belongs to a versioned TokenSet; carries its own `version` integer.
 */
class DesignToken extends Model
{
    protected $fillable = [
        'token_set_id', 'name', 'slug', 'category', 'value', 'version', 'description',
    ];

    public function tokenSet(): BelongsTo
    {
        return $this->belongsTo(TokenSet::class);
    }
}
