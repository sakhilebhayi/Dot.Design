<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pages were numbered with max()+1 and no constraint, so two
        // concurrent "add page" requests could leave a project with two pages
        // sharing a number. Renumber any such duplicates (keeping the oldest
        // row's number, moving later ones to the end of the project) so the
        // unique index can be added to existing data. No row is deleted.
        $maxByProject = [];
        $rows = DB::table('design_canvases')
            ->orderBy('design_project_id')->orderBy('page_number')->orderBy('id')
            ->get(['id', 'design_project_id', 'page_number']);

        foreach ($rows as $row) {
            $maxByProject[$row->design_project_id] = max($maxByProject[$row->design_project_id] ?? 0, (int) $row->page_number);
        }

        $seen = [];
        foreach ($rows as $row) {
            $project = $row->design_project_id;

            if (isset($seen[$project][$row->page_number])) {
                $next = ++$maxByProject[$project];
                DB::table('design_canvases')->where('id', $row->id)->update(['page_number' => $next]);
                $seen[$project][$next] = true;

                continue;
            }

            $seen[$project][$row->page_number] = true;
        }

        Schema::table('design_canvases', function (Blueprint $table) {
            $table->unique(['design_project_id', 'page_number'], 'design_canvases_project_page_unique');
        });
    }

    public function down(): void
    {
        Schema::table('design_canvases', function (Blueprint $table) {
            $table->dropUnique('design_canvases_project_page_unique');
        });
    }
};
