<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Privacy\UserDataExport;
use Illuminate\Console\Command;

/**
 * Handles a person's request for a copy of their information when they cannot use the download in Studio settings: prints one
 * JSON document, or writes it to --file. The output holds personal information: handle the file like the data it is.
 */
class ExportUserData extends Command
{
    protected $signature = 'design:export-user-data {email : The email address of the account} {--file= : Write to this path instead of printing} {--no-documents : Leave the design documents out}';

    protected $description = 'Export everything Dot.Design holds about one person (POPIA access request).';

    public function handle(UserDataExport $export): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower((string) $this->argument('email'))])->first();

        if ($user === null) {
            $this->error('No account with that email address.');

            return self::FAILURE;
        }

        $json = json_encode($export->build($user, ! $this->option('no-documents')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($path = $this->option('file')) {
            file_put_contents((string) $path, $json);
            @chmod((string) $path, 0600);
            $this->info('Written to '.$path.' (permissions 0600).');

            return self::SUCCESS;
        }

        $this->line((string) $json);

        return self::SUCCESS;
    }
}
