<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit access to one project for a workspace member who could not otherwise see it.
 */
class DesignProjectGrant extends Model
{
    public const LEVELS = ['view', 'comment', 'edit'];

    protected $fillable = ['design_project_id', 'user_id', 'level', 'granted_by'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
