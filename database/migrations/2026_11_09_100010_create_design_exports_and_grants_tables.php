<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Export audit: the browser produces the file; the server only records that it happened.
        Schema::create('design_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 8);
            $table->string('pages', 255)->default('all');
            $table->decimal('scale', 5, 2)->default(1);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['design_project_id', 'created_at'], 'design_exports_project_created_idx');
            $table->index('user_id');
            $table->index('created_at');
        });

        // Explicit access to one project for a workspace member who could not otherwise see it (a private project).
        Schema::create('design_project_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // view | comment | edit
            $table->string('level', 8)->default('view');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['design_project_id', 'user_id'], 'design_grants_project_user_unique');
            $table->index('user_id');
            $table->index('granted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_project_grants');
        Schema::dropIfExists('design_exports');
    }
};
