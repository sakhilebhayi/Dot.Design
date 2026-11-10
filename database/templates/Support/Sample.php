<?php

declare(strict_types=1);

namespace Database\Templates\Support;

/**
 * Neutral sample values for contact slots. They are obviously samples (a brief or the
 * brand kit replaces them), never an invented real business, person or number.
 */
final class Sample
{
    public const NAME = 'Your Business';

    public const TAGLINE = 'Say what you do in one line';

    public const PHONE = '012 345 6789';

    public const WHATSAPP = '082 345 6789';

    public const EMAIL = 'hello@yourbusiness.co.za';

    public const WEBSITE = 'www.yourbusiness.co.za';

    public const HANDLE = '@yourbusiness';

    public const ADDRESS = '12 Main Street, Your Town';

    /** Labels and help from the catalogue copy bank (config/design_copy.php), so the library and the UI agree. */
    public const SLOT_LABELS = [
        'business_name' => 'Business name', 'initials' => 'Monogram letters', 'tagline' => 'Tagline', 'headline' => 'Headline', 'subheadline' => 'Supporting line',
        'offer' => 'Offer', 'price' => 'Price', 'date' => 'Date', 'time' => 'Time', 'venue' => 'Venue', 'address' => 'Address',
        'phone' => 'Phone number', 'whatsapp' => 'WhatsApp number', 'email' => 'Email address', 'website' => 'Website',
        'social_handle' => 'Social media handle', 'cta' => 'Call to action', 'description' => 'Description', 'logo' => 'Logo',
        'photo_1' => 'Picture 1', 'photo_2' => 'Picture 2', 'photo_3' => 'Picture 3',
    ];

    public const SLOT_HELP = [
        'business_name' => 'The name of your business, as customers know it.', 'initials' => 'One to three letters for a monogram. We make them from your business name.', 'tagline' => 'A short line that says what you stand for.',
        'headline' => 'The big words people read first.', 'subheadline' => 'One line under the headline.', 'offer' => 'What the customer gets.',
        'price' => 'Write it exactly as it should print, with the currency.', 'date' => 'The day or dates.', 'time' => 'When it starts and ends.',
        'venue' => 'Where it happens.', 'address' => 'Your street address.', 'phone' => 'The number customers should call.',
        'whatsapp' => 'The number customers can message.', 'email' => 'Where customers can email you.', 'website' => 'Your web address.',
        'social_handle' => 'Your name on Instagram, Facebook or TikTok.', 'cta' => 'The next step you want people to take.',
        'description' => 'A few sentences about it.', 'logo' => 'Your logo. You can add it later.', 'photo_1' => 'A photo of your product, place or team.',
        'photo_2' => 'A second photo.', 'photo_3' => 'A third photo.',
    ];

    public const IMAGE_SLOTS = ['logo', 'photo_1', 'photo_2', 'photo_3'];
}
