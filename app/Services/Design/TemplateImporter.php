<?php

namespace App\Services\Design;

use App\Models\DesignTemplate;
use Illuminate\Support\Facades\File;

/**
 * Reads template definition files from database/templates/*.php, validates every template and upserts the
 * valid ones by slug (curated source). Idempotent: an unchanged definition (same content hash) is not touched,
 * a changed one bumps `version` and keeps its usage count.
 *
 * File convention (documented in docs/R45_BUSINESS.md): a file returns one definition array, a list of
 * definition arrays, an object with definitions(): iterable, or a Closure returning any of those.
 * Files whose name starts with "_" or an upper-case letter are helpers (builders, shared parts) and are
 * never loaded as definitions.
 */
class TemplateImporter
{
    public function __construct(private readonly TemplateChecker $checker, private readonly Formats $formats) {}

    public function directory(): string
    {
        return database_path('templates');
    }

    /** @return list<string> */
    public function files(?string $directory = null): array
    {
        $directory ??= $this->directory();

        if (! is_dir($directory)) {
            return [];
        }

        $files = array_filter(
            File::glob(rtrim($directory, '/').'/*.php') ?: [],
            fn (string $file) => ! preg_match('/^[_A-Z]/', basename($file)),
        );
        sort($files);

        return array_values($files);
    }

    /**
     * Load and validate everything. Returns the normalised valid definitions and the refused ones with reasons.
     *
     * @return array{valid: list<array<string, mixed>>, refused: list<array{file: string, slug: ?string, errors: list<string>}>}
     */
    public function read(?string $directory = null): array
    {
        $valid = [];
        $refused = [];
        $slugs = [];

        foreach ($this->files($directory) as $file) {
            try {
                $definitions = $this->load($file);
            } catch (\Throwable $e) {
                $refused[] = ['file' => basename($file), 'slug' => null, 'errors' => ['could not be loaded: '.$e->getMessage()]];

                continue;
            }

            foreach ($definitions as $index => $definition) {
                $definition = $this->normalise($definition);
                $slug = is_string($definition['slug'] ?? null) ? $definition['slug'] : null;
                $errors = $this->checker->checkDefinition($definition);

                if ($slug !== null && isset($slugs[$slug])) {
                    $errors[] = "slug [$slug] is also used in ".$slugs[$slug];
                }

                if ($errors !== []) {
                    $refused[] = ['file' => basename($file).(count($definitions) > 1 ? "#$index" : ''), 'slug' => $slug, 'errors' => $errors];

                    continue;
                }

                $slugs[$slug] = basename($file);
                $valid[] = $definition;
            }
        }

        return ['valid' => $valid, 'refused' => $refused];
    }

    /**
     * Upsert valid definitions by slug.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>, conflicts: list<string>}
     */
    public function import(array $definitions): array
    {
        $report = ['created' => [], 'updated' => [], 'unchanged' => [], 'conflicts' => []];

        foreach ($definitions as $definition) {
            $slug = $definition['slug'];
            $existing = DesignTemplate::withoutGlobalScopes()->where('slug', $slug)->first();

            if ($existing !== null && $existing->source !== 'curated') {
                $report['conflicts'][] = $slug;

                continue;
            }

            $row = [
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'source' => 'curated',
                'team_id' => null,
                'user_id' => null,
                'status' => $definition['status'] ?? 'published',
                'category' => $definition['category'],
                'kind' => $definition['kind'],
                'industries' => $definition['industries'] ?? [],
                'purposes' => $definition['purposes'] ?? [],
                'styles' => $definition['styles'] ?? [],
                'platforms' => $definition['platforms'] ?? [],
                'objectives' => $definition['objectives'] ?? [],
                'format_key' => $definition['format_key'] ?? null,
                'width' => $definition['width'],
                'height' => $definition['height'],
                'pages' => $definition['pages'],
                'slots' => $definition['slots'],
                'page_count' => count($definition['pages']),
                'popularity' => $definition['popularity'] ?? 50,
            ];
            $hash = sha1((string) json_encode($row));

            if ($existing === null) {
                DesignTemplate::create($row + ['slug' => $slug, 'version' => 1, 'content_hash' => $hash, 'published_at' => $row['status'] === 'published' ? now() : null]);
                $report['created'][] = $slug;
            } elseif ($existing->content_hash === $hash) {
                $report['unchanged'][] = $slug;
            } else {
                $existing->forceFill($row + [
                    'version' => $existing->version + 1,
                    'content_hash' => $hash,
                    'published_at' => $row['status'] === 'published' ? ($existing->published_at ?? now()) : $existing->published_at,
                ])->save();
                $report['updated'][] = $slug;
            }
        }

        return $report;
    }

    /**
     * Retire curated templates that no longer have a definition file. Returns the slugs retired.
     *
     * @param  list<string>  $keepSlugs
     * @return list<string>
     */
    public function retireMissing(array $keepSlugs): array
    {
        $rows = DesignTemplate::withoutGlobalScopes()->where('source', 'curated')->where('status', '!=', 'retired')->whereNotIn('slug', $keepSlugs)->get();

        foreach ($rows as $row) {
            $row->forceFill(['status' => 'retired'])->save();
        }

        return $rows->pluck('slug')->all();
    }

    /** @return list<array<string, mixed>> */
    private function load(string $file): array
    {
        $value = require $file;

        if ($value instanceof \Closure) {
            $value = $value();
        }
        if (is_object($value) && method_exists($value, 'definitions')) {
            $value = iterator_to_array($value->definitions(), false);
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        if (! is_array($value)) {
            throw new \RuntimeException('the file must return a template definition or a list of them');
        }

        return array_is_list($value) ? $value : [$value];
    }

    /**
     * Fill in what can be derived: canvas size from the format, slots from the bound elements.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function normalise(array $definition): array
    {
        $format = isset($definition['format_key']) && is_string($definition['format_key']) ? $this->formats->find($definition['format_key']) : null;

        if ($format !== null) {
            $definition['width'] ??= $format['px']['width'];
            $definition['height'] ??= $format['px']['height'];
            $definition['platforms'] ??= [$format['platform']];
        }

        foreach (['industries', 'purposes', 'styles', 'platforms', 'objectives'] as $list) {
            $definition[$list] = array_values((array) ($definition[$list] ?? []));
        }

        if (is_array($definition['pages'] ?? null)) {
            $definition['slots'] = $this->slots($definition);
        }

        return $definition;
    }

    /**
     * One slot per bound key, in order of appearance, with the template's own sample text as the default.
     * Entries the author wrote (label, required, default) override the derived ones.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function slots(array $definition): array
    {
        $samples = [];
        $order = [];
        $walk = function (array $elements) use (&$walk, &$samples, &$order): void {
            foreach ($elements as $el) {
                if (! is_array($el)) {
                    continue;
                }
                if (! empty($el['bind']) && is_string($el['bind'])) {
                    $order[$el['bind']] = true;
                    if (($el['type'] ?? null) === 'text' && ! isset($samples[$el['bind']])) {
                        $samples[$el['bind']] = $el['text'] ?? null;
                    }
                }
                if (is_array($el['children'] ?? null)) {
                    $walk($el['children']);
                }
            }
        };

        foreach ((array) $definition['pages'] as $page) {
            if (is_array($page) && is_array($page['elements'] ?? null)) {
                $walk($page['elements']);
            }
        }

        $authored = [];
        foreach ((array) ($definition['slots'] ?? []) as $slot) {
            if (is_array($slot) && isset($slot['key'])) {
                $authored[$slot['key']] = $slot;
            }
        }

        $out = [];
        foreach (array_keys($order) as $key) {
            $copy = config("design_copy.slots.$key", []);
            $out[] = ($authored[$key] ?? []) + array_filter([
                'key' => $key,
                'label' => $copy['label'] ?? $key,
                'type' => $copy['type'] ?? 'text',
                'default' => $samples[$key] ?? null,
            ], fn ($v) => $v !== null);
        }

        // Authored slots that match no element are kept so the checker can report them.
        foreach ($authored as $key => $slot) {
            if (! isset($order[$key])) {
                $out[] = $slot;
            }
        }

        return $out;
    }
}
