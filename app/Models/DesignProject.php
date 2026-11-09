<?php

namespace App\Models;

use App\Models\Concerns\HasStudioRelations;
use App\Models\Concerns\HasWorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class DesignProject extends Model
{
    use HasStudioRelations;
    use HasWorkspaceScope;

    public const STATUSES = ['draft', 'in_review', 'approved', 'archived'];

    public const VISIBILITIES = ['private', 'team'];

    protected $attributes = ['visibility' => 'private', 'status' => 'draft'];

    protected $fillable = ['user_id', 'name', 'type', 'width', 'height', 'unit'];

    /**
     * Visible = the owner's own, or shared with a workspace the user belongs to (visibility "team"), or one they hold an
     * explicit grant on, or one that names them as reviewer. Mirrored by DesignAccess::capabilities().
     */
    protected static function constrainToVisible(Builder $query, int $userId): void
    {
        $table = (new static)->getTable();

        $query->where("{$table}.user_id", $userId)
            ->orWhere(fn (Builder $team) => $team->where("{$table}.visibility", 'team')
                ->where(fn (Builder $member) => $member
                    ->whereIn("{$table}.team_id", DB::table('teams')->select('id')->where('user_id', $userId))
                    ->orWhereIn("{$table}.team_id", DB::table('team_user')->select('team_id')->where('user_id', $userId))))
            ->orWhereIn("{$table}.id", DB::table('design_project_grants')->select('design_project_id')->where('user_id', $userId))
            ->orWhereIn("{$table}.id", DB::table('design_reviews')->select('design_project_id')->where('reviewer_id', $userId)->where('status', '!=', 'cancelled'));
    }

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
