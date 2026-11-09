<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Services\Design\FileEraser;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        // The rows below go by database cascade, which runs no model events: collect the person's picture and thumbnail files
        // first and remove them once the delete has committed, or they would stay on the private disk for ever.
        $eraser = app(FileEraser::class);
        $files = $eraser->forUser($user);

        DB::transaction(function () use ($user) {
            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });

        $eraser->delete($files);
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }
}
