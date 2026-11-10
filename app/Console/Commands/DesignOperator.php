<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or withdraws platform-operator rights (publish curated templates, edit the design-system catalogue). The
 * column is not mass-assignable on purpose; this is the one supported way to change it, so nobody has to run SQL on the
 * live database. The person must already exist (signing in through InfoDot creates the account).
 */
class DesignOperator extends Command
{
    protected $signature = 'design:operator
        {email : The person\'s email address}
        {--revoke : Withdraw operator rights instead of granting them}';

    protected $description = 'Grant (or --revoke) platform-operator rights for one person';

    public function handle(): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $this->argument('email'))])->first();

        if (! $user) {
            $this->error('No account with that email. The person must sign in once (through InfoDot) so the account exists.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_platform_operator' => $grant])->save();

        $this->info(($grant ? 'Granted' : 'Withdrew').' operator rights: '.$user->name.' (#'.$user->id.').');
        $this->line('Operators now: '.User::query()->where('is_platform_operator', true)->count());

        return self::SUCCESS;
    }
}
