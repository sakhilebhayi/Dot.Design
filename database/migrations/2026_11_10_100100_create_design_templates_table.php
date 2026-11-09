<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('source', 10)->default('curated'); // curated | team | user
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('published'); // draft | published | retired
            $table->string('category', 24);
            $table->string('kind', 40);
            $table->json('industries')->nullable();
            $table->json('purposes')->nullable();
            $table->json('styles')->nullable();
            $table->json('platforms')->nullable();
            $table->json('objectives')->nullable();
            $table->string('format_key', 60)->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('pages');
            $table->json('slots')->nullable();
            $table->unsignedSmallInteger('popularity')->default(0); // editorial weight 0-100
            $table->unsignedInteger('uses')->default(0);            // times instantiated (best effort, no personal data)
            $table->unsignedInteger('version')->default(1);
            $table->string('content_hash', 64)->nullable();         // lets design:seed-templates skip unchanged rows
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'source']);
            $table->index('kind');
            $table->index('category');
            $table->index('format_key');
            $table->index(['team_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_templates');
    }
};
