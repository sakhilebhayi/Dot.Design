<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit row. Writing goes through ActivityLog; an update or delete through the model is refused.
 */
class DesignActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'design_activity';

    protected $fillable = ['team_id', 'project_id', 'user_id', 'action', 'subject_type', 'subject_id', 'meta'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit trail is append-only.'));
        static::deleting(fn () => throw new LogicException('The audit trail is append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
