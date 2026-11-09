<?php

namespace Tests\Feature\Integrations;

use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\DesignShare;
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

    protected function project(User $owner, string $name = 'Spring Sale Poster', ?Team $team = null): DesignProject
    {
        $project = DesignProject::create(['user_id' => $owner->id, 'name' => $name, 'type' => 'poster', 'width' => 800, 'height' => 600, 'unit' => 'px']);

        if ($team !== null) {
            $project->forceFill(['team_id' => $team->id, 'visibility' => 'team'])->save();
        }

        return $project;
    }

    protected function comment(DesignProject $project, ?User $author, string $body = 'Secret feedback text'): DesignComment
    {
        return DesignComment::create(['design_project_id' => $project->id, 'user_id' => $author?->id, 'guest_name' => $author ? null : 'Guest', 'body' => $body]);
    }

    protected function review(DesignProject $project, User $requester, User $reviewer, string $status = 'pending'): DesignReview
    {
        return DesignReview::create(['design_project_id' => $project->id, 'requested_by' => $requester->id, 'reviewer_id' => $reviewer->id, 'status' => $status]);
    }

    protected function share(DesignProject $project, User $by): DesignShare
    {
        return DesignShare::create(['design_project_id' => $project->id, 'created_by' => $by->id, 'token_hash' => hash('sha256', uniqid('', true)), 'permission' => 'view']);
    }
}
