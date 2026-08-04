<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGenerationLog extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'design_project_id', 'prompt', 'result_url', 'provider', 'tokens_used'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DesignProject::class, 'design_project_id');
    }
}
