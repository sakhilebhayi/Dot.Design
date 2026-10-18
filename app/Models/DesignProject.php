<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

    /**
     * Append a page numbered max+1. The number is read and written inside a
     * transaction and backed by a unique (design_project_id, page_number)
     * index, so a concurrent request that picks the same number fails the
     * insert instead of creating a duplicate; the loser retries and takes
     * the next number.
     */
    public function addPage(array $attributes = []): DesignCanvas
    {
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($attributes) {
                    $next = (int) DesignCanvas::where('design_project_id', $this->id)->max('page_number') + 1;

                    return $this->canvases()->create($attributes + ['elements' => []] + ['page_number' => $next]);
                });
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempts >= 5) {
                    throw $e;
                }
            }
        }
    }
}
