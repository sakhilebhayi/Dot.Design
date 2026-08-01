<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design-token / component-library domain (MVP scaffold).
 *
 * This is a second, deliberately separate domain living alongside the
 * existing canvas/AI design-creation tool (design_projects, design_canvases,
 * design_assets, ai_generation_logs — see 2026_06_27_000001_create_design_tables.php).
 *
 * Scope note: these tables model Dot.Brain's "enterprise design system" framing
 * (platforms/dot-design.md §2) — design tokens, components, and which ecosystem
 * platforms consume which token-set version. Deliberately NOT included in this
 * MVP: comprehension-observation telemetry (no live telemetry infra exists yet),
 * event emission, and any cross-platform distribution API.
 *
 * Tenancy: these tables are intentionally global/shared, not team-scoped.
 * A design system is the same catalog every platform (and every team within
 * this app) consumes — unlike design_projects/design_canvases/design_assets,
 * which are per-user creative work. There is no team_id/user_id column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A versioned set of tokens (e.g. "Core Palette v2", "Spacing Scale v1").
        // Tokens belong to a token set; a token set groups tokens that version together.
        Schema::create('token_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        // Design token (color, type, spacing, motion, ...).
        // Keeps versioning simple: a token carries its own `version` integer
        // rather than a full version-history table, per MVP scope.
        Schema::create('design_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_set_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->enum('category', ['color', 'type', 'spacing', 'motion'])->default('color');
            $table->string('value');
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['token_set_id', 'slug']);
        });

        // Reusable UI component definition.
        // `is_brain_surface` flags components that render Brain output
        // (Why blocks, confidence badges, intent labels) — per Dot.Brain's
        // platform doc this is just a category flag for the MVP, not separate
        // certification infrastructure (no comprehension gate is implemented).
        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category')->default('general');
            $table->boolean('is_brain_surface')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->json('props_schema')->nullable();
            $table->timestamps();
        });

        // Consumption record: which ecosystem platform consumes which token
        // set, pinned to which version. Simple join/tracking table — no
        // drift-detection or breaking-change notice mechanism implemented.
        Schema::create('token_consumption_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_set_id')->constrained()->cascadeOnDelete();
            $table->string('platform_id');
            $table->unsignedInteger('pinned_version')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['token_set_id', 'platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_consumption_records');
        Schema::dropIfExists('components');
        Schema::dropIfExists('design_tokens');
        Schema::dropIfExists('token_sets');
    }
};
