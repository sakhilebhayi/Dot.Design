<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A first-time moment the person has already seen announced. See App\Support\Ui\Milestones. */
class DesignMilestone extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'key', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }
}
