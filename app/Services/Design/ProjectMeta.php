<?php

namespace App\Services\Design;

use App\Models\DesignProject;
use App\Support\Db\Columns;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the design_projects columns the business agent owns (brief, format_key, template_id,
 * brand_kit_id, brand_version) straight through the query builder. This avoids touching the shared
 * DesignProject model (the backend agent edits its $fillable and $casts on another branch) and avoids
 * any double JSON encoding if that branch adds a cast for `brief`.
 */
class ProjectMeta
{
    /** @return array<string, mixed> */
    public function brief(DesignProject $project): array
    {
        $raw = $this->column($project, 'brief');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function formatKey(DesignProject $project): ?string
    {
        $value = $this->column($project, 'format_key');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function templateId(DesignProject $project): ?int
    {
        $value = $this->column($project, 'template_id');

        return $value === null ? null : (int) $value;
    }

    /** @return array{kit_id: ?int, version: ?int} */
    public function brandLink(DesignProject $project): array
    {
        $kit = $this->column($project, 'brand_kit_id');
        $version = $this->column($project, 'brand_version');

        return ['kit_id' => $kit === null ? null : (int) $kit, 'version' => $version === null ? null : (int) $version];
    }

    /** @param array<string, mixed> $values keys among brief (array), format_key, template_id, brand_kit_id, brand_version, team_id */
    public function write(DesignProject $project, array $values): void
    {
        $row = [];

        foreach ($values as $column => $value) {
            if (! Columns::has('design_projects', $column)) {
                continue;
            }
            $row[$column] = $column === 'brief' ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
        }

        if ($row !== []) {
            DB::table('design_projects')->where('id', $project->id)->update($row);
            foreach (array_keys($row) as $column) {
                $project->offsetUnset($column);
            }
        }
    }

    private function column(DesignProject $project, string $column): mixed
    {
        if (! Columns::has('design_projects', $column)) {
            return null;
        }

        return DB::table('design_projects')->where('id', $project->id)->value($column);
    }
}
