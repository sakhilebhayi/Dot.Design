<?php

namespace Tests\Feature\Integrations;

use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Shared fixtures: a linked company workspace with an owner and members, and a project in it. */
abstract class IntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected const COMPANY = '5b0f2d3e-8a41-4c7e-9d62-3f1a7c9e0b44';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function workspace(?string $uuid = self::COMPANY): array
    {
        $owner = User::factory()->create(['name' => 'Olive Owner', 'email' => 'olive@example.test']);
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Northwind', 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        return [$owner, $team];
    }

    protected function member(Team $team, string $email = 'mia@example.test', string $role = 'editor'): User
    {
        $user = User::factory()->create(['name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function project(User $owner, string $name = 'Spring Sale Poster'): DesignProject
    {
        return DesignProject::create(['user_id' => $owner->id, 'name' => $name, 'type' => 'poster', 'width' => 800, 'height' => 600, 'unit' => 'px']);
    }
}
