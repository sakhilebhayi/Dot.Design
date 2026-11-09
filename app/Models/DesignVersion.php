<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of a project's pages. Rows are written once; only `kind` may be promoted (a review snapshot
 * becomes "approved"). The snapshot lives in `pages`, a list of page documents in storage form.
 */
class DesignVersion extends Model
{
    public const UPDATED_AT = null;

    public const KINDS = ['auto', 'manual', 'restore', 'approved'];

    protected $fillable = ['design_project_id', 'user_id', 'kind', 'label', 'pages', 'bytes'];

    protected $casts = ['pages' => 'array', 'created_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
