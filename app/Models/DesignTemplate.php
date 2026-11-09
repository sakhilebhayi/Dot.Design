<?php

namespace App\Models;

use App\Models\Concerns\ScopesToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ready-made starting point: full v2 pages (docs/R45_ARCHITECTURE.md, resources/design/document-schema.json)
 * whose elements may carry a `bind` slot key the brief fills.
 *
 * source: curated (shipped with the product, seeded by design:seed-templates, published by operators),
 *         team (saved by a member, visible to the workspace), user (saved by one person, private).
 */
class DesignTemplate extends Model
{
    use ScopesToWorkspace;

    public const SOURCES = ['curated', 'team', 'user'];

    public const STATUSES = ['draft', 'published', 'retired'];

    public const CATEGORIES = ['marketing', 'social', 'identity', 'commercial', 'document'];

    protected $fillable = [
        'slug', 'name', 'description', 'source', 'team_id', 'user_id', 'status', 'category', 'kind',
        'industries', 'purposes', 'styles', 'platforms', 'objectives', 'format_key', 'width', 'height',
        'pages', 'slots', 'page_count', 'popularity', 'uses', 'version', 'content_hash', 'published_at',
    ];

    protected $casts = [
        'industries' => 'array',
        'purposes' => 'array',
        'styles' => 'array',
        'platforms' => 'array',
        'objectives' => 'array',
        'pages' => 'array',
        'slots' => 'array',
        'width' => 'integer',
        'height' => 'integer',
        'popularity' => 'integer',
        'uses' => 'integer',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    /** Curated published templates are visible to everyone; operators also see curated drafts and retired ones. */
    public static function constrainToWorkspace(Builder $builder, User $user): void
    {
        $table = $builder->getModel()->getTable();
        $teamIds = static::workspaceTeamIds($user);
        $operator = (bool) ($user->is_platform_operator ?? false);

        $builder->where(function (Builder $query) use ($table, $user, $teamIds, $operator): void {
            $query->where(function (Builder $curated) use ($table, $operator): void {
                $curated->where("$table.source", 'curated');

                if (! $operator) {
                    $curated->where("$table.status", 'published');
                }
            })->orWhere("$table.user_id", $user->id);

            if ($teamIds !== []) {
                $query->orWhere(fn (Builder $team) => $team->where("$table.source", 'team')->whereIn("$table.team_id", $teamIds));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isCurated(): bool
    {
        return $this->source === 'curated';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Slot definitions as a list of ['key','label','type','default'?,...]. */
    public function slotList(): array
    {
        return array_values((array) $this->slots);
    }
}
