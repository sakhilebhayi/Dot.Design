<?php

namespace App\Services\Design;

/**
 * Reads resources/design/document-schema.json (the single source of truth for limits, enums
 * and value formats). Read-only and tiny on purpose: the backend agent's DocumentSchema is the
 * full runtime; this keeps the business services independent until the two are unified.
 */
class SchemaReader
{
    private static ?array $schema = null;

    public function schema(): array
    {
        return self::$schema ??= (array) json_decode((string) file_get_contents(resource_path('design/document-schema.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function limit(string $key): int|float
    {
        return $this->schema()['limits'][$key];
    }

    /** @return list<string> */
    public function enum(string $name): array
    {
        return $this->schema()['enums'][$name] ?? [];
    }

    /** A PCRE pattern (with delimiters) for one of value_types. */
    public function pattern(string $name): string
    {
        return '/'.str_replace('/', '\/', $this->schema()['value_types'][$name]).'/';
    }
}
