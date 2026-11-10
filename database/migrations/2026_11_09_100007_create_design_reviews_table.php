<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            // The snapshot that was sent for review; promoted to kind "approved" on approval.
            $table->foreignId('version_id')->nullable()->constrained('design_versions')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            // pending | approved | changes_requested | cancelled | stale
            $table->string('status', 24)->default('pending');
            $table->text('note')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['design_project_id', 'status'], 'design_reviews_project_status_idx');
            $table->index(['reviewer_id', 'status'], 'design_reviews_reviewer_status_idx');
            $table->index(['requested_by', 'status'], 'design_reviews_requester_status_idx');
            $table->index('version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_reviews');
    }
};
