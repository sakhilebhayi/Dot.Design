<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DesignProject extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'name', 'type', 'width', 'height', 'unit'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canvases(): HasMany
    {
        return $this->hasMany(DesignCanvas::class)->orderBy('page_number');
    }
}
