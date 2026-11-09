<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A named folder in one person's asset library. Assets point at it by name (design_assets.folder). */
class DesignAssetFolder extends Model
{
    /** Letters, numbers, spaces and a few separators: the same rule uploads use for a folder name. */
    public const NAME_PATTERN = '/^[\p{L}\p{N} _\-\.&\/]+$/u';

    protected $fillable = ['user_id', 'name'];
}
