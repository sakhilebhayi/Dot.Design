<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * The business catalogue's template vocabulary (config/design_copy.php and config/design_brief_flow.php
 * on feat/r45-business). Template::start rejects anything outside it, so a seeded template can never
 * carry a kind, purpose, objective, industry or style the recommender does not know.
 */
final class Vocab
{
    public const KINDS = [
        'poster', 'flyer', 'postcard', 'instagram-post', 'instagram-story', 'facebook-post', 'facebook-cover', 'linkedin-post',
        'linkedin-banner', 'x-post', 'tiktok-cover', 'youtube-thumbnail', 'whatsapp-status', 'pinterest-pin', 'business-card',
        'letterhead', 'email-signature', 'menu', 'price-list', 'voucher', 'certificate', 'label', 'roll-up-banner', 'presentation',
        'presentation-cover', 'web-banner', 'email-header', 'report', 'newsletter', 'ebook', 'invitation', 'brochure',
    ];

    /** Kinds the library needs that the catalogue does not list yet (see docs/R45_LIBRARY.md, "Vocabulary additions"). */
    public const EXTRA_KINDS = ['logo', 'brand-guidelines', 'company-profile', 'product-catalogue', 'service-catalogue', 'quotation', 'packaging'];

    public const CATEGORIES = ['marketing', 'social', 'identity', 'commercial', 'document'];

    public const PURPOSES = ['advertise', 'sale', 'product', 'event', 'social', 'menu', 'stationery', 'document', 'announcement'];

    public const OBJECTIVES = ['awareness', 'sales', 'leads', 'bookings', 'footfall', 'trust', 'inform'];

    public const INDUSTRIES = ['food', 'beauty', 'fitness', 'retail', 'construction', 'education', 'health', 'automotive', 'property', 'professional', 'tech', 'events', 'agriculture', 'community', 'general'];

    public const STYLES = ['bold', 'clean', 'elegant', 'playful', 'corporate', 'dark', 'earthy'];

    public static function check(array $meta): void
    {
        $bad = [];
        if (! in_array($meta['kind'], [...self::KINDS, ...self::EXTRA_KINDS], true)) {
            $bad[] = "kind {$meta['kind']}";
        }
        foreach ([['category', [$meta['category']], self::CATEGORIES], ['purposes', $meta['purposes'], self::PURPOSES], ['objectives', $meta['objectives'] ?? [], self::OBJECTIVES], ['industries', $meta['industries'], self::INDUSTRIES], ['styles', $meta['styles'], self::STYLES]] as [$label, $values, $allowed]) {
            foreach ($values as $v) {
                if (! in_array($v, $allowed, true)) {
                    $bad[] = "{$label} {$v}";
                }
            }
        }
        if ($bad !== []) {
            throw new \InvalidArgumentException("Template {$meta['slug']} uses values outside the catalogue vocabulary: ".implode(', ', $bad));
        }
    }
}
