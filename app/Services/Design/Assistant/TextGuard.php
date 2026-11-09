<?php

namespace App\Services\Design\Assistant;

/**
 * Text hygiene for everything that goes to, or comes back from, the model.
 *
 *  - data():  neutralise a user string before it is placed inside a delimited block of a prompt, so it can never
 *             close the block or open a new one. It is data, never instructions.
 *  - clean(): make model-written text safe to put on a design (plain words, one length, no markup).
 *  - unsupported(): the facts a model may NOT invent. Every number, web address, email address and handle in a
 *             candidate string must already be in the text the person supplied; otherwise the string is refused.
 */
final class TextGuard
{
    private const URL = '~(?:https?://|www\.)[^\s<>"\']+|\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:co\.za|org\.za|com|org|net|io|za|co|app|shop|store|me|biz|info)\b(?:/[^\s<>"\']*)?~iu';

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/u';

    private const HANDLE = '/(?<![\w@])@[A-Za-z0-9._]{2,30}/u';

    /** Phone-like runs: at least seven digits with spaces, dashes, dots or brackets between them. */
    private const PHONE = '/\+?\d[\d \-().\x{00A0}]{5,}\d/u';

    private const NUMBER = '/\d+(?:[.,]\d+)*/u';

    public static function data(string $value, int $max = 4000): string
    {
        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $value);
        $value = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $value);
        $value = str_replace(['<', '>'], ["\u{2039}", "\u{203A}"], $value);

        return mb_substr(trim($value), 0, $max);
    }

    /** Plain single-line (or multi-line) text for a design: no markup, no template syntax, bounded. */
    public static function clean(mixed $value, int $max, bool $multiline = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $value);
        $value = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $value);
        $value = $multiline ? (string) preg_replace('/[ \t]+/u', ' ', $value) : (string) preg_replace('/\s+/u', ' ', $value);

        if (preg_match('/<\s*[\/!?a-zA-Z]|&#|&lt;|&gt;|\{\{|\}\}|\$\{|\[\[|javascript:/i', $value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            return null;
        }

        return $value;
    }

    /**
     * What is in $candidate that is not in $source: numbers, web addresses, email addresses and handles.
     *
     * @return list<string>
     */
    public static function unsupported(string $candidate, string $source): array
    {
        $bad = [];
        $have = self::facts($source);

        $text = $candidate;

        foreach (self::grab(self::EMAIL, $text) as $email) {
            $text = str_replace($email, ' ', $text);
            if (! in_array(mb_strtolower($email), $have['emails'], true)) {
                $bad[] = $email;
            }
        }

        foreach (self::grab(self::URL, $text) as $url) {
            $text = str_replace($url, ' ', $text);
            if (! in_array(self::host($url), $have['urls'], true)) {
                $bad[] = $url;
            }
        }

        foreach (self::grab(self::HANDLE, $text) as $handle) {
            $text = str_replace($handle, ' ', $text);
            if (! in_array(mb_strtolower($handle), $have['handles'], true)) {
                $bad[] = $handle;
            }
        }

        foreach (self::grab(self::PHONE, $text) as $phone) {
            $digits = preg_replace('/\D/', '', $phone);
            if (strlen((string) $digits) >= 7) {
                $text = str_replace($phone, ' ', $text);
                if (! in_array($digits, $have['phones'], true) && ! str_contains($have['all_digits'], (string) $digits)) {
                    $bad[] = trim($phone);
                }
            }
        }

        foreach (self::grab(self::NUMBER, $text) as $number) {
            if (! in_array(self::digits($number), $have['numbers'], true)) {
                $bad[] = $number;
            }
        }

        return array_values(array_unique($bad));
    }

    public static function supported(string $candidate, string $source): bool
    {
        return self::unsupported($candidate, $source) === [];
    }

    /** @return array{emails: list<string>, urls: list<string>, handles: list<string>, phones: list<string>, numbers: list<string>, all_digits: string} */
    public static function facts(string $source): array
    {
        $text = $source;
        $emails = array_map('mb_strtolower', self::grab(self::EMAIL, $text));
        foreach ($emails as $email) {
            $text = str_ireplace($email, ' ', $text);
        }

        $urls = [];
        foreach (self::grab(self::URL, $text) as $url) {
            $urls[] = self::host($url);
            $text = str_replace($url, ' ', $text);
        }

        $handles = array_map('mb_strtolower', self::grab(self::HANDLE, $text));

        $phones = [];
        foreach (self::grab(self::PHONE, $text) as $phone) {
            $digits = (string) preg_replace('/\D/', '', $phone);
            if (strlen($digits) >= 7) {
                $phones[] = $digits;
            }
        }

        $numbers = [];
        foreach (self::grab(self::NUMBER, $source) as $number) {
            $numbers[] = self::digits($number);
        }

        // A number written with thousand separators ("1 299") is a single figure to a person.
        foreach (self::grab('/\d{1,3}(?:[ \x{00A0}]\d{3})+(?:[.,]\d+)?/u', $source) as $spaced) {
            $numbers[] = self::digits($spaced);
        }

        return [
            'emails' => array_values(array_unique($emails)),
            'urls' => array_values(array_unique($urls)),
            'handles' => array_values(array_unique($handles)),
            'phones' => array_values(array_unique($phones)),
            'numbers' => array_values(array_unique($numbers)),
            'all_digits' => (string) preg_replace('/\D/', '', $source),
        ];
    }

    private static function digits(string $number): string
    {
        return (string) preg_replace('/\D/', '', $number);
    }

    /** The domain of an address: the person's own site, whatever page of it is meant. */
    private static function host(string $url): string
    {
        $url = mb_strtolower(trim($url, " \t\n\r\0\x0B.,;:!?)\"'"));
        $url = (string) preg_replace('~^https?://~', '', $url);
        $url = (string) preg_replace('~^www\.~', '', $url);

        return explode('/', $url, 2)[0];
    }

    /** @return list<string> */
    private static function grab(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $m);

        return array_values(array_filter(array_map(fn ($s) => rtrim((string) $s, '.,;:!?'), $m[0] ?? []), fn ($s) => $s !== ''));
    }
}
