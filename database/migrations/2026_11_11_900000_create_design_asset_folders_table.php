<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A folder in the asset library is a name on an asset. This table only lets a person keep a folder that holds nothing
     * yet (create it, then fill it), and rename or remove it. Folder names belong to the person who made them.
     */
    public function up(): void
    {
        Schema::create('design_asset_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_asset_folders');
    }
};
