<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            // A workspace is a Jetstream team. team_id null = a personal kit that only its owner can see.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            // Bumped on every change to what the kit says (colours, fonts, type styles, voice, logos...).
            $table->unsignedInteger('version')->default(1);
            $table->json('colors')->nullable();
            $table->json('fonts')->nullable();
            $table->json('type_styles')->nullable();
            $table->text('guidelines')->nullable();
            $table->json('voice')->nullable();
            $table->string('tagline', 160)->nullable();
            $table->string('website', 200)->nullable();
            $table->json('contacts')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'is_default']);
            $table->index(['user_id', 'is_default']);
        });

        Schema::create('brand_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_kit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_asset_id')->constrained('design_assets')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('label', 80)->nullable();
            $table->timestamps();

            $table->unique(['brand_kit_id', 'design_asset_id', 'role']);
        });

        // The "your brand changed" notification list: one open row per project per kit. Nothing here changes a design.
        Schema::create('design_brand_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_kit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_project_id')->constrained('design_projects')->cascadeOnDelete();
            $table->unsignedInteger('from_version');
            $table->unsignedInteger('to_version');
            $table->json('summary')->nullable();
            $table->string('status', 12)->default('pending'); // pending | applied | dismissed
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['design_project_id', 'status']);
            $table->index(['brand_kit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_brand_updates');
        Schema::dropIfExists('brand_assets');
        Schema::dropIfExists('brand_kits');
    }
};
