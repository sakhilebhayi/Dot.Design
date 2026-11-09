<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-45 page fields (document schema v2). All nullable or defaulted: a v1 row is a valid v2 page that inherits the
 * project's size, has a solid background taken from background_color, and the default duration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_canvases', function (Blueprint $table) {
            $table->string('name', 80)->nullable()->after('page_number');
            $table->unsignedInteger('width')->nullable()->after('name');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->json('background')->nullable()->after('background_color');
            $table->unsignedInteger('duration_ms')->default(5000)->after('background');
            $table->string('transition', 16)->default('none')->after('duration_ms');
            $table->json('guides')->nullable()->after('transition');
        });
    }

    public function down(): void
    {
        Schema::table('design_canvases', function (Blueprint $table) {
            $table->dropColumn(['name', 'width', 'height', 'background', 'duration_ms', 'transition', 'guides']);
        });
    }
};
