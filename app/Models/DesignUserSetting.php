<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DesignUserSetting extends Model
{
    public const MODES = ['simple', 'pro'];

    public const MOTION = ['system', 'reduce', 'full'];

    protected $fillable = ['user_id', 'mode', 'snap', 'grid', 'rulers', 'reduced_motion_pref', 'last_format_key', 'onboarded_at'];

    protected function casts(): array
    {
        return ['snap' => 'boolean', 'grid' => 'boolean', 'rulers' => 'boolean', 'onboarded_at' => 'datetime'];
    }
}
