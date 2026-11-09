<?php

namespace App\Services\Design;

use App\Models\DesignAsset;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * "Can this person use this picture?" in one place. Goes through the DesignAsset model (so whatever
 * workspace scope the model carries applies) AND the policy, so it keeps working unchanged when the
 * backend agent widens asset visibility from owner-only to workspace-wide.
 */
class AssetAccess
{
    /**
     * @param  list<int>  $ids
     * @return list<int> the ids the user may use
     */
    public function visibleIds(?User $user, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === [] || $user === null) {
            return [];
        }

        return DesignAsset::query()->whereIn('id', $ids)->get()
            ->filter(fn (DesignAsset $asset) => Gate::forUser($user)->allows('view', $asset))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function canUse(?User $user, int $id): bool
    {
        return in_array($id, $this->visibleIds($user, [$id]), true);
    }
}
