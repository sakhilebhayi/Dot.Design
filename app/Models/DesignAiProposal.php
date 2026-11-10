<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the assistant (or an automatic fix) offered. A proposal never changes a design by itself: improve
 * proposals are applied by the person pressing Apply, compose proposals record the project that was created.
 * Rows with a `model` are calls to the model and are what the daily caps count.
 */
class DesignAiProposal extends Model
{
    public const MODES = ['compose', 'improve', 'copy'];

    public const STATUSES = ['proposed', 'applied', 'dismissed', 'failed'];

    protected $fillable = [
        'design_project_id', 'team_id', 'user_id', 'mode', 'status', 'prompt', 'payload', 'model', 'tokens_in', 'tokens_out', 'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
