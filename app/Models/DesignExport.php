<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit row for an export. The browser produces the file; this records that it happened (format, pages, scale).
 */
class DesignExport extends Model
{
    public const UPDATED_AT = null;

    public const FORMATS = ['png', 'jpg', 'svg', 'pdf', 'gif', 'webm'];

    protected $fillable = ['design_project_id', 'user_id', 'format', 'pages', 'scale', 'bytes'];

    protected $casts = ['scale' => 'float', 'created_at' => 'datetime'];
}
