<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which Dot.Files document a design export was filed as, so saving the same export again adds a NEW VERSION of
 * that document instead of a second copy. Holds pointers only (uuids and a version number), never file content.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('design_file_links')) {
            return;
        }

        Schema::create('design_file_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // "all" or "page-3"; the format is the file extension (png, svg, ...).
            $table->string('scope', 40);
            $table->string('format', 10);
            $table->string('files_uuid', 36);
            $table->unsignedInteger('version')->nullable();
            $table->string('open_url', 2048)->nullable();
            $table->timestamp('filed_at');
            $table->timestamps();

            $table->unique(['design_project_id', 'team_id', 'scope', 'format'], 'design_file_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_file_links');
    }
};
