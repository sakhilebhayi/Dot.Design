<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-45 asset library: workspace, folder, tags, licence and source. Quota stays per user. Existing rows are backfilled
 * with the owner's personal team, are licence `own`, and stay private to their owner (see HasWorkspaceScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_assets', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained('teams')->nullOnDelete();
            $table->string('folder', 80)->nullable()->after('name');
            $table->json('tags')->nullable()->after('folder');
            $table->string('license', 16)->default('own')->after('tags');
            $table->string('source', 24)->nullable()->after('license');

            $table->index(['team_id', 'license'], 'design_assets_team_license_idx');
            $table->index(['user_id', 'folder'], 'design_assets_user_folder_idx');
        });

        DB::table('teams')->where('personal_team', true)->orderBy('id')->each(function ($team): void {
            DB::table('design_assets')
                ->whereNull('team_id')
                ->where('user_id', $team->user_id)
                ->update(['team_id' => $team->id]);
        });
    }

    public function down(): void
    {
        Schema::table('design_assets', function (Blueprint $table) {
            $table->dropIndex('design_assets_team_license_idx');
            $table->dropIndex('design_assets_user_folder_idx');
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['folder', 'tags', 'license', 'source']);
        });
    }
};
