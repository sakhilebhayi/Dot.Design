<?php

namespace App\Services\Design\Assistant;

/**
 * Deterministic extraction of the facts a person types into a request: phone numbers, prices, dates, times,
 * addresses, web addresses, email addresses, social handles and (only when stated plainly) the business name.
 * Whatever is found here wins over anything the model writes for the same slot. No model is involved.
 *
 * The business name is taken only from explicit phrasings ("called X", "named X", "business is X", "my business X",
 * or a quoted name right after such a word). A softer reading ("for Thandi's Bakery") is the model's job, and the
 * name it returns is accepted only when it appears verbatim in the text (see groundedName()).
 */
final class LiteralExtractor
{
    private const MONTH = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';

    private const WEEKDAY = '(?:Mon|Tues?|Wed(?:nes)?|Thu(?:rs)?|Fri|Sat(?:ur)?|Sun)(?:day)?';

    private const NAME = '\p{Lu}[\p{L}\p{N}\'\x{2019}\-]*(?:[ \t]+(?:&[ \t]+)?\p{Lu}[\p{L}\p{N}\'\x{2019}\-]*){0,4}';

    /**
     * @return array<string, string> slot key => value exactly as typed
     */
    public function extract(string $text): array
    {
        $found = [];

        $email = $this->first('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/u', $text);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $found['email'] = $email;
        }
        $withoutEmail = $email !== null ? str_replace($email, ' ', $text) : $text;

        $url = $this->first('~(?:https?://|www\.)[^\s<>"\']+|\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:co\.za|org\.za|com|org|net|io|za|co|app|shop|store|me|biz|info)\b(?:/[^\s<>"\']*)?~iu', $withoutEmail);
        if ($url !== null) {
            $found['website'] = $url;
        }

        $handle = $this->first('/(?<![\w@])@[A-Za-z0-9._]{2,30}/u', $withoutEmail);
        if ($handle !== null) {
            $found['social_handle'] = $handle;
        }

        $phone = $this->phone($text);
        if ($phone !== null) {
            $found['phone'] = $phone;
        }

        $price = $this->first('/(?<![A-Za-z])(?:ZAR\s?|[R$\x{20AC}\x{00A3}]\s?)\d{1,3}(?:[ ,]\d{3})+(?:[.,]\d{1,2})?|(?<![A-Za-z])(?:ZAR\s?|[R$\x{20AC}\x{00A3}]\s?)\d+(?:[.,]\d{1,2})?/u', $text);
        if ($price !== null) {
            $found['price'] = $price;
        }

        $date = $this->date($text);
        if ($date !== null) {
            $found['date'] = $date;
        }

        $time = $this->time($text);
        if ($time !== null) {
            $found['time'] = $time;
        }

        $address = $this->first('/\b\d{1,5}[A-Za-z]?\s+\p{Lu}[\p{L}\'. ]{2,40}\s+(?:Street|St|Road|Rd|Avenue|Ave|Drive|Dr|Lane|Ln|Boulevard|Blvd|Crescent|Cres|Way|Close)\b(?:,\s*\p{Lu}[\p{L} ]{2,30}){0,2}/u', $text);
        if ($address !== null) {
            $found['address'] = $address;
        }

        $name = $this->businessName($text);
        if ($name !== null) {
            $found['business_name'] = $name;
        }

        return $found;
    }

    /** A name the model proposed is only usable when the person actually typed it. Returns the person's own spelling. */
    public function groundedName(?string $proposed, string $text): ?string
    {
        if ($proposed === null) {
            return null;
        }

        $proposed = trim($proposed);
        if ($proposed === '' || mb_strlen($proposed) > 60) {
            return null;
        }

        $pos = mb_stripos($text, $proposed);

        return $pos === false ? null : mb_substr($text, $pos, mb_strlen($proposed));
    }

    private function businessName(string $text): ?string
    {
        $patterns = [
            '/\b(?:called|named|name is|business is|company is|brand is|shop is|salon is|restaurant is|cafe is)\s+["\x{201C}\x{2018}\']?('.self::NAME.')["\x{201D}\x{2019}\']?/u',
            '/\bmy\s+(?:business|company|brand|shop|store|salon|restaurant|cafe|studio|firm)\s+["\x{201C}\x{2018}\']?('.self::NAME.')["\x{201D}\x{2019}\']?/u',
            '/\b(?:called|named)\s+["\x{201C}]([^"\x{201D}]{2,60})["\x{201D}]/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $name = trim($m[1], " .,;:\t");
                if ($name !== '' && ! preg_match('/^\d+$/', $name)) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function phone(string $text): ?string
    {
        preg_match_all('/(?<![\w.])(?:\+\d{1,3}|0{1,2}\d{0,2})[\s\-]?\(?\d{2,3}\)?[\s\-]?\d{3}[\s\-]?\d{3,4}(?![\w])/u', $text, $m);

        foreach ($m[0] as $candidate) {
            $digits = (string) preg_replace('/\D/', '', $candidate);
            if (strlen($digits) >= 9 && strlen($digits) <= 15) {
                return trim($candidate);
            }
        }

        return null;
    }

    private function date(string $text): ?string
    {
        $weekday = '(?:'.self::WEEKDAY.',?\s+)?';
        $patterns = [
            '/\b'.$weekday.'\d{1,2}(?:st|nd|rd|th)?\s+(?:'.self::MONTH.')\b(?:\s+\d{4})?/iu',
            '/\b'.$weekday.'(?:'.self::MONTH.')\s+\d{1,2}(?:st|nd|rd|th)?\b(?:,?\s+\d{4})?/iu',
            '/\b\d{4}-\d{2}-\d{2}\b/u',
            '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/u',
        ];

        foreach ($patterns as $pattern) {
            $value = $this->first($pattern, $text);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function time(string $text): ?string
    {
        $clock = '\d{1,2}(?:[:h]\d{2})?\s?(?:am|pm)';

        return $this->first('/\b'.$clock.'\s*(?:-|to|until|till)\s*'.$clock.'\b/iu', $text)
            ?? $this->first('/\b\d{1,2}[:h]\d{2}\s*(?:-|to|until|till)\s*\d{1,2}[:h]\d{2}\b/iu', $text)
            ?? $this->first('/\b'.$clock.'\b/iu', $text)
            ?? $this->first('/\b(?:[01]?\d|2[0-3]):[0-5]\d\b/u', $text);
    }

    private function first(string $pattern, string $text): ?string
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $value = rtrim(trim($m[0]), '.,;:!?');

        return $value === '' ? null : $value;
    }
}
