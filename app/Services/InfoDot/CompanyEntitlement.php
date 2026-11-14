<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks InfoDot whether a company still holds this platform (R-50).
 *
 * InfoDot decides; this class only reads its answer for one company, by that company's InfoDot uuid, with this
 * platform's own service secret. A clear answer is kept for ten minutes, a failure for one, so a signed-in person
 * costs at most one short call per window.
 *
 * Only InfoDot's explicit "no" for this platform's own row refuses anyone. Every other outcome (no secret, a timeout,
 * a refusal of the call itself, a body that is not what the contract says) is "unknown": the caller carries on as it
 * did before this check existed, and nothing shown to the person may read it as "active".
 */
class CompanyEntitlement
{
    /** Seconds a clear answer is kept. */
    public const KEEP_ANSWER = 600;

    /** Seconds a failure is kept before InfoDot is asked again. */
    public const KEEP_FAILURE = 60;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.entitlement.check', true);
    }

    public function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'design');
    }

    /** Where a person manages this platform for their company. */
    public function manageUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/').'/platforms/'.rawurlencode($this->platform());
    }

    /**
     * @return array{entitled: bool, status: string}|null null when InfoDot's answer could not be established
     */
    public function verdict(string $companyUuid): ?array
    {
        $kept = $this->kept($companyUuid);

        if ($kept !== null) {
            return isset($kept['failed']) ? null : $kept;
        }

        $answer = $this->ask($companyUuid);

        if (is_string($answer)) {
            // Stored and logged together, so a failure is reported once per window and never per request.
            Cache::put($this->key($companyUuid), ['failed' => true], self::KEEP_FAILURE);
            Log::warning('Company entitlement could not be checked with InfoDot; carrying on.', ['company' => $companyUuid, 'reason' => $answer]);

            return null;
        }

        Cache::put($this->key($companyUuid), $answer, self::KEEP_ANSWER);

        return $answer;
    }

    /**
     * The answer already held for a company, without asking InfoDot. Null when nothing clear is held.
     *
     * @return array{entitled: bool, status: string}|null
     */
    public function known(string $companyUuid): ?array
    {
        $kept = $this->kept($companyUuid);

        return $kept === null || isset($kept['failed']) ? null : $kept;
    }

    /** @return array<string, mixed>|null */
    private function kept(string $companyUuid): ?array
    {
        $kept = Cache::get($this->key($companyUuid));

        return is_array($kept) ? $kept : null;
    }

    private function key(string $companyUuid): string
    {
        return 'entitlement:'.$this->platform().':'.sha1($companyUuid);
    }

    /**
     * @return array{entitled: bool, status: string}|string the answer, or a short reason it could not be had
     */
    private function ask(string $companyUuid): array|string
    {
        $secret = config('ecosystem.infodot.secret');
        $base = (string) config('ecosystem.infodot.redeem_url', '');

        if (! filled($secret) || $base === '') {
            return 'not_configured';
        }

        if (! Str::isUuid($companyUuid)) {
            return 'company_uuid_malformed';
        }

        // The read endpoint sits beside the redeem endpoint: .../api/ecosystem/redeem -> .../api/ecosystem/companies/{uuid}/platforms.
        $url = Str::beforeLast(rtrim($base, '/'), '/').'/companies/'.$companyUuid.'/platforms';
        $platform = $this->platform();

        try {
            $response = Http::withoutRedirecting()
                ->withToken((string) $secret)
                ->withHeaders(['X-Dot-Platform' => $platform])
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout((int) config('ecosystem.entitlement.timeout', 3))
                ->get($url, ['platform' => $platform]);
        } catch (Throwable $e) {
            return 'unreachable:'.class_basename($e);
        }

        if ($response->status() !== 200) {
            return 'http_'.$response->status();
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['platforms'] ?? null) : null;

        if (! is_array($rows) || ($body['company']['uuid'] ?? null) !== $companyUuid) {
            return 'malformed_body';
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $platform) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return 'malformed_body';
                }

                return ['entitled' => $row['entitled'], 'status' => is_string($row['status'] ?? null) ? $row['status'] : ''];
            }
        }

        return 'own_row_missing';
    }
}
