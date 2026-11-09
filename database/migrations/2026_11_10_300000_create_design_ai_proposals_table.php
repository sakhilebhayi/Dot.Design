<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_ai_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_project_id')->nullable()->constrained('design_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16);   // compose | improve | copy
            $table->string('status', 16); // proposed | applied | dismissed | failed
            $table->text('prompt')->nullable();
            $table->json('payload')->nullable();
            // Null for automatic (no model) proposals; a call to the model always sets it, so it is what the caps count.
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('error', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'design_ai_user_created_idx');
            $table->index(['team_id', 'created_at'], 'design_ai_team_created_idx');
            $table->index('design_project_id');
            $table->index(['status', 'created_at'], 'design_ai_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_ai_proposals');
    }
};
