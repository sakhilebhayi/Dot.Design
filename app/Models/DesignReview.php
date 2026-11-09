<?php

namespace App\Models;

use App\Support\Design\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignReview extends Model
{
    protected $fillable = ['design_project_id', 'version_id', 'requested_by', 'reviewer_id', 'status', 'note', 'decision_note', 'decided_at'];

    protected function casts(): array
    {
        return ['status' => ReviewStatus::class, 'decided_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
