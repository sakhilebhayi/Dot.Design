<?php

namespace App\Policies;

use App\Models\DesignAsset;
use App\Models\User;
use App\Support\Design\DesignAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class DesignAssetPolicy
{
    use HandlesAuthorization;

    public function view(User $user, DesignAsset $asset): bool
    {
        return DesignAccess::canViewAsset($user, $asset);
    }

    /** Rename, re-file, re-tag: the uploader's call only. */
    public function update(User $user, DesignAsset $asset): bool
    {
        return (int) $asset->user_id === $user->id;
    }

    public function delete(User $user, DesignAsset $asset): bool
    {
        return DesignAccess::canDeleteAsset($user, $asset);
    }
}
