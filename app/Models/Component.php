<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable UI component definition. `is_brain_surface` flags components
 * that render Brain output (Why block, confidence badge, intent label) —
 * a category flag only in this MVP; no comprehension-gate/certification
 * pipeline is implemented.
 */
class Component extends Model
{
    protected $fillable = [
        'name', 'slug', 'category', 'is_brain_surface', 'version', 'description', 'props_schema',
    ];

    protected $casts = [
        'is_brain_surface' => 'boolean',
        'props_schema' => 'array',
    ];
}
