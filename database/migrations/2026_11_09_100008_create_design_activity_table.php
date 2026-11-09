<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail. The id columns are plain indexed integers, not foreign keys, on purpose: a deleted project,
 * user or workspace must not erase (or null out) the record that it was deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 48);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'created_at'], 'design_activity_team_created_idx');
            $table->index(['project_id', 'created_at'], 'design_activity_project_created_idx');
            $table->index('user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_activity');
    }
};
