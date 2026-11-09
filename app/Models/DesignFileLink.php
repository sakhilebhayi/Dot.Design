<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pointer from a design export to the Dot.Files document it was filed as. See the migration for why it exists. */
class DesignFileLink extends Model
{
    protected $fillable = ['design_project_id', 'team_id', 'user_id', 'scope', 'format', 'files_uuid', 'version', 'open_url', 'filed_at'];

    protected function casts(): array
    {
        return ['filed_at' => 'datetime', 'version' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }
}
