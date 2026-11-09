<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Your brand changed" notice for one project. Informational: it never changes the design. */
class DesignBrandUpdate extends Model
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    protected $fillable = ['brand_kit_id', 'design_project_id', 'from_version', 'to_version', 'summary', 'status', 'resolved_at', 'resolved_by'];

    protected $casts = ['summary' => 'array', 'resolved_at' => 'datetime'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class, 'brand_kit_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }
}
