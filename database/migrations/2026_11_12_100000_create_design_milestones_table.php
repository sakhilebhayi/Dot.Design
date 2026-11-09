<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which first-time moments a person has already been told about (first design, first file, first approval), so each message
     * appears once. A row is only a memory of "seen"; the moment itself is always read from the person's real designs, exports and reviews.
     */
    public function up(): void
    {
        Schema::create('design_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 40);
            $table->timestamp('seen_at')->useCurrent();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_milestones');
    }
};
