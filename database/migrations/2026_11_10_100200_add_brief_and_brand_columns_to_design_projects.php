<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the guided brief, template instantiation and Brand Studio need on design_projects.
 * The architecture (docs/R45_ARCHITECTURE.md section 3) lists the same columns for the backend
 * migration, so every add is guarded: whichever branch migrates first wins, the other is a no-op.
 * brand_kit_id and template_id are plain nullable integers (no foreign key): deleting a kit or a
 * template must never delete a person's design, and the services null the pointer themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('design_projects', 'format_key')) {
                $table->string('format_key', 60)->nullable();
            }
            if (! Schema::hasColumn('design_projects', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable();
            }
            if (! Schema::hasColumn('design_projects', 'brief')) {
                $table->json('brief')->nullable();
            }
            if (! Schema::hasColumn('design_projects', 'brand_kit_id')) {
                $table->unsignedBigInteger('brand_kit_id')->nullable()->index();
            }
            if (! Schema::hasColumn('design_projects', 'brand_version')) {
                $table->unsignedInteger('brand_version')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally not dropping: another branch's migration may own these columns.
    }
};
