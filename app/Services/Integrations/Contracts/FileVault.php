<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Team;
use App\Services\Integrations\IntegrationRefused;

/** Files an exported design in the workspace's own filing room (Dot.Files). */
interface FileVault extends ReportsStatus
{
    public function enabled(): bool;

    /**
     * File a new document.
     *
     * @return array<string, mixed> {uuid, name, size, mime, reference, open_url}
     *
     * @throws IntegrationRefused
     */
    public function store(Team $team, string $name, string $bytes, ?string $folder = null): array;

    /**
     * Save new content for a document this platform filed, as a new version in Dot.Files.
     *
     * @return array<string, mixed> meta plus {version, checksum}
     *
     * @throws IntegrationRefused status 409 means someone saved a newer version there first
     */
    public function update(Team $team, string $uuid, string $bytes, ?int $baseVersion = null, ?string $name = null): array;
}
