<?php

namespace Tests\Support;

use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Small builders for the studio tests: users with personal teams, company workspaces with roles, projects with a page.
 */
trait StudioWorld
{
    protected function person(?string $name = null): User
    {
        $user = User::factory()->withPersonalTeam()->create($name ? ['name' => $name] : []);

        return $user->fresh();
    }

    /** A company workspace owned by $owner. */
    protected function company(User $owner, string $name = 'Acme Ltd'): Team
    {
        return Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
    }

    protected function join(Team $team, User $user, string $role): User
    {
        $team->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    protected function removeFrom(Team $team, User $user): void
    {
        $team->users()->detach($user->id);
    }

    /** A workspace owner and one member of each role. @return array{team: Team, owner: User, admin: User, editor: User, viewer: User} */
    protected function workspace(): array
    {
        $owner = $this->person('Olivia Owner');
        $team = $this->company($owner);

        return [
            'team' => $team,
            'owner' => $owner,
            'admin' => $this->join($team, $this->person('Adam Admin'), 'admin'),
            'editor' => $this->join($team, $this->person('Edna Editor'), 'editor'),
            'viewer' => $this->join($team, $this->person('Vic Viewer'), 'viewer'),
        ];
    }

    protected function project(User $owner, ?Team $team = null, string $visibility = 'private', array $attributes = []): DesignProject
    {
        $project = new DesignProject($attributes + ['user_id' => $owner->id, 'name' => 'Test project', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px']);
        $project->forceFill(['visibility' => $visibility] + ($team ? ['team_id' => $team->id] : []))->save();
        $project->addPage(['elements' => []]);

        return $project->fresh();
    }

    /** @return array<string, mixed> */
    protected function textElement(string $id = 't1', string $text = 'Hello'): array
    {
        return ['id' => $id, 'type' => 'text', 'x' => 10, 'y' => 20, 'width' => 300, 'height' => 60, 'text' => $text, 'fontSize' => 32, 'color' => '#111111'];
    }

    protected function role(User $user, Team $team): ?string
    {
        return DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)->value('role');
    }
}
