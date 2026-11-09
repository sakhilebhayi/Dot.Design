<?php

namespace Database\Seeders;

use App\Services\Design\TemplateImporter;
use Illuminate\Database\Seeder;

/** Loads whatever template definitions exist in database/templates (the library agent's files). Safe to run repeatedly. */
class DesignTemplateSeeder extends Seeder
{
    public function run(TemplateImporter $importer): void
    {
        $read = $importer->read();
        $importer->import($read['valid']);

        foreach ($read['refused'] as $entry) {
            $this->command?->warn('Template refused: '.$entry['file'].' - '.implode('; ', array_slice($entry['errors'], 0, 3)));
        }
    }
}
