<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('mode', 8)->default('simple');
            $table->boolean('snap')->default(true);
            $table->boolean('grid')->default(false);
            $table->boolean('rulers')->default(false);
            // system | reduce | full: the editor's own motion preference, independent of the OS one when set.
            $table->string('reduced_motion_pref', 8)->default('system');
            $table->string('last_format_key', 64)->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_user_settings');
    }
};
