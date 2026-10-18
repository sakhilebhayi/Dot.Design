<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real uploads. A row with a non-null `disk` is a file we store and
     * serve ourselves (file_path is then a path on that disk); a row with a
     * null `disk` is the old shape -- an external URL, today only the mock AI
     * placeholder -- and is never read from storage. Existing rows are valid
     * as they are, so every new column is nullable.
     *
     * (user_id, content_hash) is unique so the "same bytes, same user"
     * dedupe holds under concurrent uploads; NULL hashes (legacy and
     * placeholder rows) never collide.
     */
    public function up(): void
    {
        Schema::table('design_assets', function (Blueprint $table) {
            $table->foreignId('design_project_id')->nullable()->after('user_id')
                ->constrained('design_projects')->nullOnDelete();
            $table->string('disk', 64)->nullable()->after('file_path');
            $table->char('content_hash', 64)->nullable()->after('mime_type');
            $table->unsignedInteger('width')->nullable()->after('file_size');
            $table->unsignedInteger('height')->nullable()->after('width');

            $table->unique(['user_id', 'content_hash']);
            $table->index(['user_id', 'design_project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('design_assets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'content_hash']);
            $table->dropIndex(['user_id', 'design_project_id']);
            $table->dropConstrainedForeignId('design_project_id');
            $table->dropColumn(['disk', 'content_hash', 'width', 'height']);
        });
    }
};
