<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenDriftNotice extends Model
{
    protected $fillable = [
        'token_set_id', 'platform_id', 'pinned_version',
        'current_version', 'detected_at', 'cleared_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function tokenSet(): BelongsTo
    {
        return $this->belongsTo(TokenSet::class);
    }
}
