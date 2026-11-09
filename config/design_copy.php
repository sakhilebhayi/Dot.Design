<?php

/*
|--------------------------------------------------------------------------
| Dot.Design plain-language copy bank (R-45)
|--------------------------------------------------------------------------
|
| Labels and help text the interface shows for template kinds, categories,
| purposes, slots and brand roles. The UI never invents wording: it reads from
| here (through the JSON endpoints) so Simple and Pro mode say the same thing.
| Format labels live in config/design_formats.php, goal and style labels in
| config/design_brief_flow.php.
*/

return [

    // Slot keys a template element can be bound to (element.bind). type: text | image.
    'slots' => [
        'business_name' => ['label' => 'Business name', 'type' => 'text', 'example' => 'Thandi\'s Bakery', 'help' => 'The name of your business, as customers know it.'],
        'initials' => ['label' => 'Monogram letters', 'type' => 'text', 'example' => 'TB', 'help' => 'One to three letters for a monogram. We make them from your business name.'],
        'tagline' => ['label' => 'Tagline', 'type' => 'text', 'example' => 'Fresh bread every morning', 'help' => 'A short line that says what you stand for.'],
        'headline' => ['label' => 'Headline', 'type' => 'text', 'example' => 'Winter special', 'help' => 'The big words people read first.'],
        'subheadline' => ['label' => 'Supporting line', 'type' => 'text', 'example' => 'Warm pies for cold days', 'help' => 'One line under the headline.'],
        'offer' => ['label' => 'Offer', 'type' => 'text', 'example' => '20% off all pies', 'help' => 'What the customer gets.'],
        'price' => ['label' => 'Price', 'type' => 'text', 'example' => 'R89', 'help' => 'Write it exactly as it should print, with the currency.'],
        'date' => ['label' => 'Date', 'type' => 'text', 'example' => 'Saturday, 14 June', 'help' => 'The day or dates.'],
        'time' => ['label' => 'Time', 'type' => 'text', 'example' => '09:00 to 14:00', 'help' => 'When it starts and ends.'],
        'venue' => ['label' => 'Venue', 'type' => 'text', 'example' => 'Community Hall, Main Road', 'help' => 'Where it happens.'],
        'address' => ['label' => 'Address', 'type' => 'text', 'example' => '12 Long Street, Cape Town', 'help' => 'Your street address.'],
        'phone' => ['label' => 'Phone number', 'type' => 'text', 'example' => '021 555 0123', 'help' => 'The number customers should call.'],
        'whatsapp' => ['label' => 'WhatsApp number', 'type' => 'text', 'example' => '082 555 0123', 'help' => 'The number customers can message.'],
        'email' => ['label' => 'Email address', 'type' => 'text', 'example' => 'hello@yourbusiness.co.za', 'help' => 'Where customers can email you.'],
        'website' => ['label' => 'Website', 'type' => 'text', 'example' => 'www.yourbusiness.co.za', 'help' => 'Your web address.'],
        'social_handle' => ['label' => 'Social media handle', 'type' => 'text', 'example' => '@yourbusiness', 'help' => 'Your name on Instagram, Facebook or TikTok.'],
        'cta' => ['label' => 'Call to action', 'type' => 'text', 'example' => 'Order today', 'help' => 'The next step you want people to take.'],
        'description' => ['label' => 'Description', 'type' => 'text', 'example' => 'Handmade, small batches, delivered to your door.', 'help' => 'A few sentences about it.'],
        'logo' => ['label' => 'Logo', 'type' => 'image', 'help' => 'Your logo. You can add it later.'],
        'photo_1' => ['label' => 'Picture 1', 'type' => 'image', 'help' => 'A photo of your product, place or team.'],
        'photo_2' => ['label' => 'Picture 2', 'type' => 'image', 'help' => 'A second photo.'],
        'photo_3' => ['label' => 'Picture 3', 'type' => 'image', 'help' => 'A third photo.'],
    ],

    'template_categories' => [
        'marketing' => 'Marketing',
        'social' => 'Social media',
        'identity' => 'Business identity',
        'commercial' => 'Menus, prices and vouchers',
        'document' => 'Documents',
    ],

    // Template kinds (design_templates.kind). The library agent authors templates with these keys.
    'template_kinds' => [
        'poster' => 'Poster',
        'flyer' => 'Flyer',
        'postcard' => 'Postcard',
        'instagram-post' => 'Instagram post',
        'instagram-story' => 'Instagram story',
        'facebook-post' => 'Facebook post',
        'facebook-cover' => 'Facebook cover',
        'linkedin-post' => 'LinkedIn post',
        'linkedin-banner' => 'LinkedIn banner',
        'x-post' => 'X post',
        'tiktok-cover' => 'TikTok cover',
        'youtube-thumbnail' => 'YouTube thumbnail',
        'whatsapp-status' => 'WhatsApp status',
        'pinterest-pin' => 'Pinterest pin',
        'business-card' => 'Business card',
        'letterhead' => 'Letterhead',
        'email-signature' => 'Email signature',
        'menu' => 'Menu',
        'price-list' => 'Price list',
        'voucher' => 'Voucher',
        'certificate' => 'Certificate',
        'label' => 'Label',
        'roll-up-banner' => 'Roll-up banner',
        'presentation' => 'Presentation',
        'presentation-cover' => 'Presentation cover',
        'web-banner' => 'Web banner',
        'email-header' => 'Email header',
        'report' => 'Report',
        'newsletter' => 'Newsletter',
        'ebook' => 'E-book',
        'invitation' => 'Invitation',
        'brochure' => 'Brochure',
        'logo' => 'Logo',
        'brand-guidelines' => 'Brand guidelines',
        'company-profile' => 'Company profile',
        'product-catalogue' => 'Product catalogue',
        'service-catalogue' => 'Service catalogue',
        'quotation' => 'Quotation',
        'packaging' => 'Packaging',
    ],

    // Where a template kind is filed when someone saves their own design as a template.
    'kind_categories' => [
        'instagram-post' => 'social', 'instagram-story' => 'social', 'facebook-post' => 'social', 'facebook-cover' => 'social',
        'linkedin-post' => 'social', 'linkedin-banner' => 'social', 'x-post' => 'social', 'tiktok-cover' => 'social',
        'youtube-thumbnail' => 'social', 'whatsapp-status' => 'social', 'pinterest-pin' => 'social',
        'business-card' => 'identity', 'letterhead' => 'identity', 'email-signature' => 'identity', 'label' => 'identity',
        'logo' => 'identity', 'brand-guidelines' => 'identity', 'company-profile' => 'identity',
        'product-catalogue' => 'commercial', 'service-catalogue' => 'commercial', 'quotation' => 'commercial', 'packaging' => 'commercial',
        'menu' => 'commercial', 'price-list' => 'commercial', 'voucher' => 'commercial', 'certificate' => 'commercial',
        'report' => 'document', 'newsletter' => 'document', 'ebook' => 'document', 'presentation' => 'document', 'presentation-cover' => 'document',
    ],

    // Font keys offered in a brand kit when config/design_fonts.php (the engine's list) is not present.
    'font_keys' => ['inter', 'dm-sans', 'poppins', 'montserrat', 'oswald', 'bebas-neue', 'anton', 'archivo-black', 'playfair-display', 'lora', 'merriweather', 'cormorant-garamond', 'fraunces', 'space-grotesk', 'caveat', 'pacifico', 'permanent-marker', 'jetbrains-mono'],

    // What a template is for (design_templates.purposes), matched against the brief's goal.
    'purposes' => [
        'advertise' => 'Advertise a business',
        'sale' => 'Announce a sale or special',
        'product' => 'Promote a product',
        'event' => 'Announce an event',
        'social' => 'Share on social media',
        'menu' => 'Menus and price lists',
        'stationery' => 'Business stationery',
        'document' => 'Documents and reports',
        'announcement' => 'General announcement',
    ],

    // Marketing objectives a template can be filed under (design_templates.objectives).
    'objectives' => [
        'awareness' => 'Get noticed',
        'sales' => 'Sell more',
        'leads' => 'Get enquiries',
        'bookings' => 'Get bookings',
        'footfall' => 'Bring people in',
        'trust' => 'Build trust',
        'inform' => 'Inform people',
    ],

    'brand_roles' => [
        'logo' => 'Logo',
        'logo-light' => 'Logo for dark backgrounds',
        'mark' => 'Icon or mark',
        'image' => 'Brand picture',
        'pattern' => 'Pattern',
        'background' => 'Background',
    ],

    'color_roles' => [
        'primary' => 'Main colour',
        'secondary' => 'Second colour',
        'accent' => 'Highlight colour',
        'neutral' => 'Neutral (text and backgrounds)',
        'extra' => 'Extra colour',
    ],

    // Phrases the recommender uses for its plain-language reasons. :placeholders are filled by code.
    'reasons' => [
        'goal' => 'Made for :phrase',
        'kind' => 'A :kind is a common choice for this',
        'style' => 'Matches the :style look you chose',
        'format' => 'Already the right size for :format',
        'family' => 'Close to the size of :format, so it adapts easily',
        'industry' => 'Designed with :industry in mind',
        'general' => 'Works for any kind of business',
        'popular' => 'Popular with other businesses',
        'yours' => 'Saved by your team',
        'fallback' => 'A good general starting point',
    ],
];
