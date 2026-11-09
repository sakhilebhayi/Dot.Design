<?php

namespace App\Console\Commands;

use App\Services\Design\TemplateImporter;
use Illuminate\Console\Command;

class SeedDesignTemplates extends Command
{
    protected $signature = 'design:seed-templates
        {--check : Validate every definition and change nothing (for CI)}
        {--path= : Read definition files from this directory instead of database/templates}
        {--retire-missing : Retire curated templates that no longer have a definition file}';

    protected $description = 'Validate the template definition files in database/templates and upsert them (idempotent, by slug)';

    public function handle(TemplateImporter $importer): int
    {
        $read = $importer->read($this->option('path') ?: null);
        $valid = $read['valid'];
        $refused = $read['refused'];

        foreach ($refused as $entry) {
            $this->error('REFUSED '.$entry['file'].($entry['slug'] ? " ({$entry['slug']})" : ''));
            foreach ($entry['errors'] as $error) {
                $this->line('   - '.$error);
            }
        }

        if ($this->option('check')) {
            $this->info(count($valid).' valid, '.count($refused).' refused. Nothing was written.');

            return $refused === [] ? self::SUCCESS : self::FAILURE;
        }

        $report = $importer->import($valid);

        $this->info(sprintf(
            'Templates: %d created, %d updated, %d unchanged, %d refused%s.',
            count($report['created']),
            count($report['updated']),
            count($report['unchanged']),
            count($refused),
            $report['conflicts'] !== [] ? ', '.count($report['conflicts']).' skipped (slug already used by a saved template)' : '',
        ));

        foreach ($report['conflicts'] as $slug) {
            $this->warn("Skipped [$slug]: that slug belongs to a template someone saved. Rename it in the definition.");
        }

        if ($this->option('retire-missing')) {
            $retired = $importer->retireMissing(array_column($valid, 'slug'));
            $this->info(count($retired).' curated template(s) retired because their definition file is gone.');
        }

        return ($refused === [] && $report['conflicts'] === []) ? self::SUCCESS : self::FAILURE;
    }
}
