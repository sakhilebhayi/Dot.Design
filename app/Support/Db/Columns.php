<?php

namespace App\Support\Db;

use Illuminate\Support\Facades\Schema;

/**
 * Schema::hasColumn() asks the database's catalogue every time (a heavy pg_catalog query on PostgreSQL, 5 to 12 per page
 * in the profile). Columns are only ever added by migrations, so a positive answer is remembered for the process. A
 * negative answer is never remembered: a migration may add the column a moment later.
 */
final class Columns
{
    /** @var array<string, true> */
    private static array $known = [];

    public static function has(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        if (isset(self::$known[$key])) {
            return true;
        }

        if (Schema::hasColumn($table, $column)) {
            return self::$known[$key] = true;
        }

        return false;
    }
}
