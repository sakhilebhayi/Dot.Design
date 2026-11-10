<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            // page_id is kept when the page is deleted (the thread stays readable, unpinned).
            $table->foreignId('page_id')->nullable()->constrained('design_canvases')->nullOnDelete();
            $table->string('element_id', 64)->nullable();
            $table->double('x')->nullable();
            $table->double('y')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Share-link commenters are guests: a display name only, never an address.
            $table->string('guest_name', 60)->nullable();
            $table->foreignId('share_id')->nullable()->constrained('design_shares')->nullOnDelete();
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('design_comments')->cascadeOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['design_project_id', 'page_id', 'created_at'], 'design_comments_project_page_idx');
            $table->index(['design_project_id', 'parent_id'], 'design_comments_project_parent_idx');
            $table->index('user_id');
            $table->index('share_id');
            $table->index('page_id');
            $table->index('parent_id');
            $table->index('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_comments');
    }
};
