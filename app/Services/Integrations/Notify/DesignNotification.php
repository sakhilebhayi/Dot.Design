<?php

namespace App\Services\Integrations\Notify;

/**
 * One notification for one person: "this happened in this company, tell this person". Plain values only, so it can sit on
 * a queue and be rebuilt cheaply. `data` holds the catalogue keys of the event and nothing else.
 */
final class DesignNotification
{
    /** @param  array<string, string>  $data */
    public function __construct(
        public readonly string $event,
        public readonly string $companyUuid,
        public readonly string $recipientEmail,
        public readonly array $data,
        public readonly string $idempotencyKey,
    ) {}

    /** @return array<string, mixed> the exact body Dot.Notify validates (POST /api/ingest/events) */
    public function toPayload(string $platform): array
    {
        return [
            'platform' => $platform,
            'company_uuid' => $this->companyUuid,
            'event' => $this->event,
            'recipient' => ['email' => $this->recipientEmail],
            'data' => $this->data,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
