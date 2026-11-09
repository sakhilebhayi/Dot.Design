<?php

namespace App\Services\Integrations\Notify;

use App\Services\Integrations\Contracts\NotificationGateway;
use App\Services\Integrations\IntegrationState;
use App\Services\Integrations\Support\CircuitBreaker;
use App\Services\Integrations\Support\LastOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dot.Notify's server-to-server event ingest (POST /api/ingest/events).
 *
 * Signing is Notify's scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's secret, sent as "sha256=<hex>" in
 * X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. The signature is made at SEND time, so a retry hours later
 * carries a fresh timestamp. Replays are harmless: Notify answers 200 for the same (company, platform, idempotency_key).
 *
 * Built to be harmless when things go wrong: a 5 second timeout, nothing here throws into the caller, a circuit breaker leaves a
 * struggling Notify alone, and with the flag off or no url/secret it does nothing at all. The secret and the recipient address
 * never reach a log line.
 */
class NotifyGateway implements NotificationGateway
{
    private const KEY = 'notify';

    public function enabled(): bool
    {
        return (bool) config('design_integrations.notify.enabled')
            && filled(config('design_integrations.notify.url'))
            && filled(config('design_integrations.notify.secret'));
    }

    public function deliver(DesignNotification $notification): string
    {
        if (! $this->enabled()) {
            return self::GIVE_UP;
        }

        $breaker = new CircuitBreaker(self::KEY);
        $last = new LastOutcome(self::KEY);

        if ($breaker->isOpen()) {
            Log::info('Dot.Notify is paused after repeated failures; the notification will be retried.', ['event' => $notification->event]);

            return self::RETRY;
        }

        try {
            $body = (string) json_encode($notification->toPayload((string) config('design_integrations.notify.platform', 'design')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = max(1, (int) config('design_integrations.notify.timeout', 5));

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('design_integrations.notify.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('design_integrations.notify.url'));
        } catch (ConnectionException) {
            $breaker->recordFailure();
            $last->remember(false, 'Dot.Notify could not be reached.');
            Log::warning('Dot.Notify unreachable; will retry.', ['event' => $notification->event]);

            return self::RETRY;
        } catch (Throwable $e) {
            $breaker->recordFailure();
            $last->remember(false, 'The call to Dot.Notify failed.');
            Log::warning('Dot.Notify call failed unexpectedly; will retry.', ['event' => $notification->event, 'error' => class_basename($e)]);

            return self::RETRY;
        }

        $status = $response->status();

        // 202 = accepted, 200 = replay of an event already accepted. Both mean Notify has it.
        if ($status >= 200 && $status < 300) {
            $breaker->recordSuccess();
            $last->remember(true);

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            $breaker->recordFailure();
            $last->remember(false, 'Dot.Notify is busy or down ('.$status.').');
            Log::warning('Dot.Notify unavailable; will retry.', ['event' => $notification->event, 'status' => $status]);

            return self::RETRY;
        }

        // 401: our secret is wrong. 404: Notify does not know the company. 413/422: the payload was refused. Retrying cannot fix
        // these, and they prove Notify itself is up, so they do not count towards the breaker.
        $breaker->recordSuccess();
        $last->remember(false, match ($status) {
            401 => 'Dot.Notify did not accept our signature. NOTIFY_INGEST_SECRET here must equal NOTIFY_INGEST_SECRET_DESIGN in Dot.Notify.',
            404 => 'Dot.Notify does not know this business.',
            default => 'Dot.Notify refused the notification ('.$status.').',
        });
        Log::error('Dot.Notify refused the notification.', ['event' => $notification->event, 'status' => $status]);

        return self::GIVE_UP;
    }

    public function status(): IntegrationState
    {
        $does = 'Emails workspace members when a design is shared with them, a review is requested or decided, or someone comments.';
        $setup = 'Set DESIGN_INTEGRATION_NOTIFY=true, NOTIFY_INGEST_URL (ending /api/ingest/events) and NOTIFY_INGEST_SECRET (the same value as NOTIFY_INGEST_SECRET_DESIGN in Dot.Notify). Each business must also switch the design.* events on in Dot.Notify.';

        if (! $this->enabled()) {
            return new IntegrationState('notify', 'Dot.Notify', IntegrationState::NOT_CONFIGURED, $does,
                'Email notifications are not switched on for this server yet. Updates still appear inside Dot.Design.', $setup);
        }

        if ((new CircuitBreaker(self::KEY))->isOpen()) {
            return new IntegrationState('notify', 'Dot.Notify', IntegrationState::UNAVAILABLE, $does,
                'Dot.Notify did not answer several times in a row, so emails are paused for a few minutes and will be retried.', $setup);
        }

        $last = (new LastOutcome(self::KEY))->get();

        if ($last === null) {
            return new IntegrationState('notify', 'Dot.Notify', IntegrationState::READY, $does,
                'Set up. No email has been sent yet, so the connection is not proven. A business must also switch the design events on in Dot.Notify, or nothing is emailed.', $setup);
        }

        return $last['ok']
            ? new IntegrationState('notify', 'Dot.Notify', IntegrationState::CONNECTED, $does,
                'The last notification was accepted by Dot.Notify. A business still chooses which events it emails.', $setup)
            : new IntegrationState('notify', 'Dot.Notify', IntegrationState::UNAVAILABLE, $does,
                $last['message'] ?? 'The last notification was not accepted.', $setup);
    }
}
