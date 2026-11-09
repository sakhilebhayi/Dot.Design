<?php

namespace App\Console\Commands;

use App\Models\DesignTemplate;
use Illuminate\Console\Command;

class ExportDesignTemplates extends Command
{
    protected $signature = 'design:export-templates
        {--json : Print the templates as one JSON document (the only format)}
        {--out= : Write the JSON to this file instead of printing it}
        {--slug=* : Only these template slugs}
        {--status=published : Only templates with this status, or "all"}';

    protected $description = 'Export the stored templates (pages, slots, format) as JSON for the offline QA tools';

    public function handle(): int
    {
        $query = DesignTemplate::query()->orderBy('slug');

        if (($status = (string) $this->option('status')) !== 'all') {
            $query->where('status', $status);
        }

        if ($this->option('slug')) {
            $query->whereIn('slug', $this->option('slug'));
        }

        $rows = $query->get()->map(fn (DesignTemplate $t): array => [
            'slug' => $t->slug,
            'name' => $t->name,
            'kind' => $t->kind,
            'category' => $t->category,
            'styles' => (array) $t->styles,
            'industries' => (array) $t->industries,
            'format_key' => $t->format_key,
            'width' => (int) $t->width,
            'height' => (int) $t->height,
            'page_count' => (int) $t->page_count,
            'slots' => (array) $t->slots,
            'pages' => (array) $t->pages,
        ])->all();

        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($path = $this->option('out')) {
            file_put_contents($path, $json);
            $this->info(count($rows)." templates written to {$path}");

            return self::SUCCESS;
        }

        $this->output->write($json);

        return self::SUCCESS;
    }
}
