<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The R-45 migrations are additive and must be safe on a populated database: existing projects and assets keep every
 * value, gain a workspace (the owner's personal team) and stay private; the whole set rolls back and forward cleanly.
 */
class StudioMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const FILES = [
        '2026_11_09_100010_create_design_exports_and_grants_tables.php',
        '2026_11_09_100009_create_design_user_settings_table.php',
        '2026_11_09_100008_create_design_activity_table.php',
        '2026_11_09_100007_create_design_reviews_table.php',
        '2026_11_09_100006_create_design_comments_table.php',
        '2026_11_09_100005_create_design_shares_table.php',
        '2026_11_09_100004_create_design_versions_table.php',
        '2026_11_09_100003_add_workspace_columns_to_design_assets_table.php',
        '2026_11_09_100002_add_studio_columns_to_design_canvases_table.php',
        '2026_11_09_100001_add_workspace_columns_to_design_projects_table.php',
    ];

    private function migrations(string $command): void
    {
        $files = $command === 'migrate' ? array_reverse(self::FILES) : self::FILES;

        foreach ($files as $file) {
            Artisan::call($command, ['--path' => 'database/migrations/'.$file, '--force' => true] + ($command === 'migrate:rollback' ? [] : ['--realpath' => false]));
        }
    }

    public function test_legacy_rows_survive_and_are_backfilled_with_the_owners_personal_team(): void
    {
        $this->migrations('migrate:rollback');
        $this->assertFalse(Schema::hasColumn('design_projects', 'team_id'));
        $this->assertFalse(Schema::hasTable('design_versions'));

        $now = now()->toDateTimeString();
        $ann = DB::table('users')->insertGetId(['name' => 'Ann', 'email' => 'ann@x.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $bob = DB::table('users')->insertGetId(['name' => 'Bob', 'email' => 'bob@x.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $annPersonal = DB::table('teams')->insertGetId(['user_id' => $ann, 'name' => "Ann's", 'personal_team' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('teams')->insert(['user_id' => $ann, 'name' => 'Ann Co', 'personal_team' => false, 'created_at' => $now, 'updated_at' => $now]);

        $p1 = DB::table('design_projects')->insertGetId(['user_id' => $ann, 'name' => 'Old poster', 'type' => 'poster', 'width' => 100, 'height' => 200, 'unit' => 'px', 'created_at' => $now, 'updated_at' => $now]);
        $p2 = DB::table('design_projects')->insertGetId(['user_id' => $bob, 'name' => 'No team', 'type' => 'graphic', 'width' => 1, 'height' => 1, 'unit' => 'px', 'created_at' => $now, 'updated_at' => $now]);
        $c1 = DB::table('design_canvases')->insertGetId(['design_project_id' => $p1, 'page_number' => 1, 'elements' => json_encode([['type' => 'rectangle', 'fill' => '#fff']]), 'background_color' => '#eeeeee', 'revision' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $a1 = DB::table('design_assets')->insertGetId(['user_id' => $ann, 'name' => 'logo.png', 'type' => 'image', 'file_path' => 'x', 'created_at' => $now, 'updated_at' => $now]);

        $this->migrations('migrate');

        $project = DB::table('design_projects')->find($p1);
        $this->assertSame($annPersonal, (int) $project->team_id, 'the personal team, not the company');
        $this->assertSame('private', $project->visibility);
        $this->assertSame('draft', $project->status);
        $this->assertSame('Old poster', $project->name);
        $this->assertNull(DB::table('design_projects')->find($p2)->team_id, 'an owner with no personal team stays unfiled');

        $canvas = DB::table('design_canvases')->find($c1);
        $this->assertSame(4, (int) $canvas->revision);
        $this->assertSame('#eeeeee', $canvas->background_color);
        $this->assertSame(5000, (int) $canvas->duration_ms);
        $this->assertSame('none', $canvas->transition);
        $this->assertNull($canvas->width);
        $this->assertStringContainsString('rectangle', $canvas->elements, 'v1 elements are not rewritten');

        $asset = DB::table('design_assets')->find($a1);
        $this->assertSame($annPersonal, (int) $asset->team_id);
        $this->assertSame('own', $asset->license);

        foreach (['design_versions', 'design_shares', 'design_comments', 'design_reviews', 'design_activity', 'design_user_settings', 'design_exports', 'design_project_grants'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_every_foreign_key_and_list_query_column_is_indexed(): void
    {
        $indexed = function (string $table, string $column): bool {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['columns'][0] ?? null) === $column) {
                    return true;
                }
            }
            foreach (Schema::getForeignKeys($table) as $fk) {
                if ($fk['columns'] === [$column]) {
                    // SQLite does not index foreign keys on its own; a covering index must exist.
                    return false;
                }
            }

            return false;
        };

        $expected = [
            'design_projects' => ['team_id', 'status'],
            'design_assets' => ['team_id'],
            'design_versions' => ['design_project_id', 'user_id'],
            'design_shares' => ['design_project_id', 'created_by', 'token_hash'],
            'design_comments' => ['design_project_id', 'page_id', 'user_id', 'share_id', 'parent_id', 'resolved_by'],
            'design_reviews' => ['design_project_id', 'version_id', 'reviewer_id', 'requested_by'],
            'design_activity' => ['team_id', 'project_id', 'user_id'],
            'design_exports' => ['design_project_id', 'user_id'],
            'design_project_grants' => ['design_project_id', 'user_id', 'granted_by'],
        ];

        foreach ($expected as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue($indexed($table, $column), "{$table}.{$column} needs an index");
            }
        }
    }
}
