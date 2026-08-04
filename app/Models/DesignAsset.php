<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignAsset extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'name', 'type', 'file_path', 'mime_type', 'file_size', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
