<?php

namespace App\Services\Integrations\Files;

use App\Models\Team;
use App\Services\Integrations\Contracts\FileVault;
use App\Services\Integrations\IntegrationRefused;
use App\Services\Integrations\IntegrationState;
use App\Services\Integrations\Support\CircuitBreaker;
use App\Services\Integrations\Support\LastOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dot.Files' platform file API (Dot.Files docs/programme/PLATFORM_FILE_API.md, v1): file an exported design in the workspace's
 * own filing room, and save a re-export as a NEW version of the same document.
 *
 * Every call is a signed POST: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's secret, in X-Dot-Signature, with
 * X-Dot-Platform and X-Dot-Timestamp (300 second window). Dot.Files scopes every call to one company (the signed team_uuid) and
 * to the files THIS platform filed, so Design can never read a person's own uploads or another platform's files.
 *
 * Calls happen while a person waits, so failures come back as a sentence they can act on (IntegrationRefused) rather than
 * a retry; a breaker stops hammering a Dot.Files that keeps failing. The secret never reaches a log line.
 */
class FilesVault implements FileVault
{
    private const KEY = 'files';

    public function enabled(): bool
    {
        return (bool) config('design_integrations.files.enabled')
            && filled(config('design_integrations.files.url'))
            && filled(config('design_integrations.files.secret'));
    }

    public function store(Team $team, string $name, string $bytes, ?string $folder = null): array
    {
        return $this->call('store', $team, array_filter([
            'name' => $name,
            'content_b64' => base64_encode($bytes),
            'folder' => $folder !== null ? mb_substr($folder, 0, 255) : (string) config('design_integrations.files.folder', ''),
        ], fn ($v) => $v !== ''));
    }

    public function update(Team $team, string $uuid, string $bytes, ?int $baseVersion = null, ?string $name = null): array
    {
        return $this->call('update', $team, array_filter([
            'uuid' => $uuid,
            'content_b64' => base64_encode($bytes),
            'base_version' => $baseVersion,
            'name' => $name,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $endpoint, Team $team, array $payload): array
    {
        if (! $this->enabled()) {
            throw new IntegrationRefused('Dot.Files is not set up on this server yet.');
        }

        if (! filled($team->infodot_team_uuid)) {
            throw new IntegrationRefused('This workspace is not linked to an InfoDot company, so Dot.Files cannot place the file.');
        }

        $breaker = new CircuitBreaker(self::KEY);
        $last = new LastOutcome(self::KEY);

        if ($breaker->isOpen()) {
            throw new IntegrationRefused('Dot.Files did not answer several times in a row. Try again in a couple of minutes.');
        }

        try {
            $body = (string) json_encode(['team_uuid' => $team->infodot_team_uuid] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = max(1, (int) config('design_integrations.files.timeout', 20));

            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->acceptJson()
                ->withHeaders([
                    'X-Dot-Platform' => (string) config('design_integrations.files.platform', 'design'),
                    'X-Dot-Timestamp' => $timestamp,
                    'X-Dot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('design_integrations.files.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('design_integrations.files.url'), '/').'/api/platform/files/'.$endpoint);
        } catch (ConnectionException) {
            $breaker->recordFailure();
            $this->fail($last, 'Dot.Files could not be reached. Try again in a moment.');
        } catch (Throwable $e) {
            report($e);
            $breaker->recordFailure();
            $this->fail($last, 'The call to Dot.Files failed. Try again in a moment.');
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $breaker->recordSuccess();
            $last->remember(true);

            return (array) $response->json();
        }

        // 429 and 5xx say Dot.Files is struggling; everything else proves it is up and is a problem with the request.
        $status === 429 || $status >= 500 ? $breaker->recordFailure() : $breaker->recordSuccess();

        $this->fail($last, match (true) {
            $status === 401 => 'Dot.Files did not accept our signature. FILES_PLATFORM_SECRET here must equal DOT_SERVICE_SECRET_DESIGN in Dot.Files, and Dot.Files must list the design platform.',
            $status === 404 => 'Dot.Files no longer has this document, or does not know this business.',
            $status === 409 => 'Someone saved a newer version of this file in Dot.Files. Open it there before saving over it.',
            $status === 413 => 'The file is larger than the 25 MB Dot.Files accepts from other apps. Download it and upload it in Dot.Files itself.',
            $status === 422 => 'Dot.Files refused the file. It accepts PDF, PNG, JPG, GIF, WebP, SVG and a few other formats.',
            $status === 429 => 'Dot.Files is busy. Try again in a minute.',
            default => 'Dot.Files had an error ('.$status.'). Try again in a moment.',
        }, $status);
    }

    private function fail(LastOutcome $last, string $message, ?int $status = null): never
    {
        $last->remember(false, $message);

        throw new IntegrationRefused($message, $status);
    }

    public function status(): IntegrationState
    {
        $does = 'Files a finished design in your workspace\'s Dot.Files filing room, and saves a re-export as a new version of the same file.';
        $setup = 'Set DESIGN_INTEGRATION_FILES=true and FILES_PLATFORM_SECRET (the same value as DOT_SERVICE_SECRET_DESIGN in Dot.Files). Dot.Files must also list `design` under platform_secrets in its config/ecosystem.php, which it does not yet.';

        if (! $this->enabled()) {
            return new IntegrationState('files', 'Dot.Files', IntegrationState::NOT_CONFIGURED, $does,
                'Saving to Dot.Files is not switched on for this server yet. You can still download your designs.', $setup);
        }

        if ((new CircuitBreaker(self::KEY))->isOpen()) {
            return new IntegrationState('files', 'Dot.Files', IntegrationState::UNAVAILABLE, $does,
                'Dot.Files did not answer several times in a row, so saving there is paused for a couple of minutes.', $setup);
        }

        $last = (new LastOutcome(self::KEY))->get();

        if ($last === null) {
            return new IntegrationState('files', 'Dot.Files', IntegrationState::READY, $does,
                'Set up. Nothing has been saved to Dot.Files yet, so the connection is not proven. Files saved there are visible to everyone in the business.', $setup);
        }

        return $last['ok']
            ? new IntegrationState('files', 'Dot.Files', IntegrationState::CONNECTED, $does,
                'The last save to Dot.Files worked. Files saved there are visible to everyone in the business.', $setup)
            : new IntegrationState('files', 'Dot.Files', IntegrationState::UNAVAILABLE, $does,
                $last['message'] ?? 'The last save to Dot.Files did not work.', $setup);
    }
}
