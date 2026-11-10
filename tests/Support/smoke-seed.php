<?php

/*
 | Creates the one signed-in user the production-like smoke test (tests/Support/signed-in-smoke.sh) uses: verified,
 | with a personal workspace, flagged as platform operator so the operator pages answer too. Reads the credentials from
 | the environment (SMOKE_EMAIL, SMOKE_PASSWORD), prints nothing secret. Never run this against a real database: the
 | smoke script points DB_DATABASE at a throw-away SQLite file.
 */

use App\Models\DesignTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = getenv('SMOKE_EMAIL') ?: 'smoke@example.test';
$password = getenv('SMOKE_PASSWORD');

if (! is_string($password) || $password === '') {
    fwrite(STDERR, "SMOKE_PASSWORD is not set.\n");
    exit(1);
}

if (! str_contains((string) config('database.connections.'.config('database.default').'.database'), 'smoke')) {
    fwrite(STDERR, "Refusing to seed: the database is not a smoke-test file.\n");
    exit(1);
}

$user = User::forceCreate([
    'name' => 'Smoke Tester',
    'email' => $email,
    'email_verified_at' => now(),
    'password' => Hash::make($password),
    'is_platform_operator' => true,
]);

$team = Team::forceCreate(['user_id' => $user->id, 'name' => "Smoke Tester's Team", 'personal_team' => true]);
$user->forceFill(['current_team_id' => $team->id])->save();

$template = DesignTemplate::withoutGlobalScopes()->where('status', 'published')->where('kind', 'poster')->orderBy('id')->value('slug')
    ?? DesignTemplate::withoutGlobalScopes()->where('status', 'published')->orderBy('id')->value('slug');

echo json_encode(['user' => $user->id, 'team' => $team->id, 'template' => $template])."\n";
