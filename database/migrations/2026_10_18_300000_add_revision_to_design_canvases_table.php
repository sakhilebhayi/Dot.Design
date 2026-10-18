<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A counter bumped by every successful save. The editor sends the value
     * it loaded; a save that arrives with an older one is refused (409) so
     * two tabs or devices cannot silently overwrite each other.
     */
    public function up(): void
    {
        Schema::table('design_canvases', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('design_canvases', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
