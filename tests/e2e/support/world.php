<?php

// Direct database setup for the end-to-end specs (the "seeder helper"): users, workspaces with roles, projects.
// Usage: php tests/e2e/support/world.php <command> '<json>'  -> prints one JSON line. Only ever runs against the throwaway
// database that tests/e2e/run.mjs creates (refuses anything else).

use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.sqlite.database'), 'dot-design-e2e-')) {
    fwrite(STDERR, "refusing to run outside the e2e database\n");
    exit(2);
}

$cmd = $argv[1] ?? '';
$in = json_decode($argv[2] ?? '{}', true) ?: [];
$out = [];

switch ($cmd) {
    case 'user':
        $user = User::factory()->withPersonalTeam()->create([
            'name' => $in['name'], 'email' => $in['email'], 'password' => Hash::make($in['password'] ?? 'e2e-pass-123'),
        ]);
        $out = ['id' => $user->id, 'email' => $user->email, 'team_id' => $user->personalTeam()->id];
        break;

    case 'company':
        $owner = User::where('email', $in['owner'])->firstOrFail();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $in['name'] ?? 'Acme Ltd', 'personal_team' => false] + (isset($in['infodot_team_uuid']) ? ['infodot_team_uuid' => $in['infodot_team_uuid']] : []));
        $owner->forceFill(['current_team_id' => $team->id])->save();
        foreach ($in['members'] ?? [] as $m) {
            $u = User::where('email', $m['email'])->firstOrFail();
            $team->users()->attach($u->id, ['role' => $m['role']]);
            $u->forceFill(['current_team_id' => $team->id])->save();
        }
        $out = ['team_id' => $team->id];
        break;

    case 'project':
        $owner = User::where('email', $in['owner'])->firstOrFail();
        $teamId = $in['team_id'] ?? $owner->personalTeam()->id;
        $p = new DesignProject(['user_id' => $owner->id, 'name' => $in['name'] ?? 'E2E project', 'type' => 'graphic', 'width' => $in['width'] ?? 1200, 'height' => $in['height'] ?? 628, 'unit' => 'px']);
        $p->forceFill(['visibility' => $in['visibility'] ?? 'private', 'team_id' => $teamId])->save();
        $p->addPage(['elements' => $in['elements'] ?? []]);
        $out = ['id' => $p->id, 'page_id' => $p->fresh()->canvases()->first()->id];
        break;

    case 'role':
        $u = User::where('email', $in['email'])->firstOrFail();
        DB::table('team_user')->where('team_id', $in['team_id'])->where('user_id', $u->id)->update(['role' => $in['role']]);
        $out = ['ok' => true];
        break;

    case 'current-team':
        User::where('email', $in['email'])->update(['current_team_id' => $in['team_id']]);
        $out = ['ok' => true];
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
