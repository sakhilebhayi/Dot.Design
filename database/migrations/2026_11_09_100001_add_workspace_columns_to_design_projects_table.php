<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-45: a project belongs to a workspace (a Jetstream team). Existing rows are backfilled with the owner's personal
 * team and stay `private`, so nothing becomes visible to anyone who could not see it before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_projects', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained('teams')->nullOnDelete();
            $table->string('visibility', 16)->default('private')->after('team_id');
            $table->string('status', 16)->default('draft')->after('visibility');
            $table->string('thumbnail_path', 255)->nullable()->after('unit');
            $table->timestamp('thumbnail_updated_at')->nullable()->after('thumbnail_path');

            $table->index(['team_id', 'visibility', 'updated_at'], 'design_projects_team_visibility_idx');
            $table->index(['user_id', 'updated_at'], 'design_projects_user_updated_idx');
            $table->index('status');
        });

        // Backfill one personal team at a time: portable across SQLite and PostgreSQL, and a no-op on an empty table.
        DB::table('teams')->where('personal_team', true)->orderBy('id')->each(function ($team): void {
            DB::table('design_projects')
                ->whereNull('team_id')
                ->where('user_id', $team->user_id)
                ->update(['team_id' => $team->id]);
        });
    }

    public function down(): void
    {
        Schema::table('design_projects', function (Blueprint $table) {
            $table->dropIndex('design_projects_team_visibility_idx');
            $table->dropIndex('design_projects_user_updated_idx');
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['visibility', 'status', 'thumbnail_path', 'thumbnail_updated_at']);
        });
    }
};
