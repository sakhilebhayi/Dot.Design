<?php

namespace App\Models\Concerns;

use App\Models\DesignActivity;
use App\Models\DesignComment;
use App\Models\DesignExport;
use App\Models\DesignProjectGrant;
use App\Models\DesignReview;
use App\Models\DesignShare;
use App\Models\DesignVersion;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The R-45 children of a project, kept out of the model file so the studio's other branches can extend DesignProject
 * without colliding with these.
 */
trait HasStudioRelations
{
    /** Casts for the R-45 columns, merged here so the model's own casts() stays free for its other owners. */
    public function initializeHasStudioRelations(): void
    {
        $this->mergeCasts(['thumbnail_updated_at' => 'datetime']);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DesignVersion::class, 'design_project_id')->latest('id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DesignShare::class, 'design_project_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DesignComment::class, 'design_project_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DesignReview::class, 'design_project_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(DesignProjectGrant::class, 'design_project_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(DesignExport::class, 'design_project_id');
    }

    public function activity(): HasMany
    {
        return $this->hasMany(DesignActivity::class, 'project_id');
    }
}
