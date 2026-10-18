<?php

namespace App\Policies;

use App\Models\DesignAsset;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DesignAssetPolicy
{
    use HandlesAuthorization;

    public function view(User $user, DesignAsset $asset): bool
    {
        return $user->id === $asset->user_id;
    }

    public function delete(User $user, DesignAsset $asset): bool
    {
        return $user->id === $asset->user_id;
    }
}
