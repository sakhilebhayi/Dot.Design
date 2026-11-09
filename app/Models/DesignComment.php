<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DesignComment extends Model
{
    protected $fillable = [
        'design_project_id', 'page_id', 'element_id', 'x', 'y', 'user_id', 'guest_name', 'share_id', 'body', 'mentions',
        'parent_id', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'mentions' => 'array',
            'resolved_at' => 'datetime',
            'x' => 'float',
            'y' => 'float',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(DesignCanvas::class, 'page_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }
}
