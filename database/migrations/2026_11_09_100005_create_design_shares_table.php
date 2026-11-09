<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // sha256 of the 40-character token; the token itself is shown once and never stored.
            $table->char('token_hash', 64)->unique();
            $table->string('permission', 16)->default('view');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('download_allowed')->default(false);
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();

            $table->index(['design_project_id', 'revoked_at'], 'design_shares_project_revoked_idx');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_shares');
    }
};
