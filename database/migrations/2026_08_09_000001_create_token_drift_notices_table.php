<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_drift_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_set_id')->constrained()->cascadeOnDelete();
            $table->string('platform_id');
            $table->unsignedInteger('pinned_version');
            $table->unsignedInteger('current_version');
            $table->timestamp('detected_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['token_set_id', 'platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_drift_notices');
    }
};
