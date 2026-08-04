<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DesignCanvas carries no user_id column of its own -- ownership flows
 * through design_project_id, and DesignProject::class applies HasUserScope.
 * Any query that traverses the project() relationship (e.g. whereHas) is
 * therefore already scoped; this model is intentionally not given the
 * trait directly since it has no user_id column for the trait to filter on.
 */
class DesignCanvas extends Model
{
    protected $fillable = ['design_project_id', 'page_number', 'elements', 'background_color', 'background_image'];

    protected $casts = ['elements' => 'array'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }
}
