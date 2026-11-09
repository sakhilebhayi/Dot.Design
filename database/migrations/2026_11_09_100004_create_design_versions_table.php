<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // auto | manual | restore | approved
            $table->string('kind', 16)->default('auto');
            $table->string('label', 80)->nullable();
            $table->json('pages');
            $table->unsignedInteger('bytes')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['design_project_id', 'created_at'], 'design_versions_project_created_idx');
            $table->index(['design_project_id', 'kind', 'created_at'], 'design_versions_project_kind_idx');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_versions');
    }
};
