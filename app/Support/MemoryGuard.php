<?php

namespace App\Support;

/**
 * GD holds a decoded image at four bytes per pixel. Callers ask here before
 * allocating one, so an oversized request is refused with a message instead
 * of dying on PHP's uncatchable "allowed memory size exhausted" fatal.
 */
class MemoryGuard
{
    /** Bytes GD needs for a truecolour image of this size. */
    public static function imageBytes(int $width, int $height): int
    {
        return $width * $height * 4;
    }

    /**
     * Megabytes that would be needed if $bytes (plus a little slack for the
     * encoder and PHP itself) will not fit in what memory_limit has left;
     * null when it fits or when memory is unlimited.
     */
    public static function shortfallMegabytes(int $bytes): ?int
    {
        $limit = self::limitBytes();

        if ($limit === null) {
            return null;
        }

        $needed = $bytes + 8 * 1024 * 1024;

        return $needed > $limit - memory_get_usage(true) ? (int) ceil($needed / 1048576) : null;
    }

    private static function limitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
