<?php

/*
|--------------------------------------------------------------------------
| Dot.Design guided brief (R-45)
|--------------------------------------------------------------------------
|
| The progressive question flow that turns "I want to make something" into a
| format, a template and filled-in text. Read through App\Services\Design\BriefFlow.
| Everything here is plain language, because the person answering is a business
| owner, not a designer. No AI is involved: the same answers always give the
| same recommendations.
|
| goals     what the person wants to do. Each goal names:
|             purpose  the template purpose it matches
|             kinds    template kinds, best first (primary weight for the first three)
|             fields   which key details to ask for, in order
|             formats  recommended formats ("where will you use it"), best first
| fields    every detail we can ask for: label, help, example, input type, max length.
|           Each key is also a template slot key (see config/design_copy.php).
| styles    the visual styles on offer, in plain words.
| industries keyword lists used to guess the kind of business from what was typed.
| steps     the order of the flow. `essential` steps cannot be skipped.
*/

return [

    'steps' => [
        ['key' => 'goal', 'essential' => true, 'title' => 'What would you like to make?', 'help' => 'Pick the closest one. You can change your mind later.', 'example' => null],
        ['key' => 'what', 'essential' => false, 'title' => 'What are you promoting?', 'help' => 'A few words about the thing itself: a product, an offer, an event or your business.', 'example' => 'Winter pie special at Thandi\'s Bakery'],
        ['key' => 'business_name', 'essential' => true, 'title' => 'What is your business called?', 'help' => 'We put it on your design for you, spelled exactly the way you type it.', 'example' => 'Thandi\'s Bakery'],
        ['key' => 'details', 'essential' => false, 'title' => 'Which details should people see?', 'help' => 'Only fill in what you want printed. Leave the rest empty.', 'example' => null],
        ['key' => 'logo', 'essential' => false, 'title' => 'Do you have a logo or a picture of your business?', 'help' => 'Add it now or skip. You can add it in the editor at any time.', 'example' => null],
        ['key' => 'style', 'essential' => false, 'title' => 'What look do you want?', 'help' => 'This picks the starting designs we show you. You can change every colour and font afterwards.', 'example' => null],
        ['key' => 'where', 'essential' => false, 'title' => 'Where will you use it?', 'help' => 'This sets the size, so your design looks right where people will see it.', 'example' => null],
    ],

    'goals' => [
        'advertise_business' => [
            'label' => 'Advertise my business',
            'help' => 'Let people know who you are and what you do.',
            'icon' => 'megaphone',
            'purpose' => 'advertise',
            'phrase' => 'advertising your business',
            'kinds' => ['flyer', 'poster', 'instagram-post', 'facebook-post', 'postcard', 'brochure', 'linkedin-post', 'whatsapp-status'],
            'fields' => ['tagline', 'offer', 'phone', 'whatsapp', 'email', 'website', 'address', 'social_handle'],
            'formats' => ['a5-portrait', 'instagram-post-square', 'a4-portrait', 'facebook-post', 'whatsapp-status', 'instagram-story'],
        ],
        'announce_sale' => [
            'label' => 'Announce a sale or special',
            'help' => 'Discounts, specials and limited-time offers.',
            'icon' => 'tag',
            'purpose' => 'sale',
            'phrase' => 'announcing a sale or special',
            'kinds' => ['instagram-post', 'poster', 'flyer', 'whatsapp-status', 'facebook-post', 'instagram-story', 'web-banner'],
            'fields' => ['headline', 'offer', 'price', 'date', 'phone', 'whatsapp', 'website', 'address', 'cta'],
            'formats' => ['instagram-post-square', 'whatsapp-status', 'a4-portrait', 'facebook-post', 'instagram-story', 'a5-portrait'],
        ],
        'promote_product' => [
            'label' => 'Promote a product',
            'help' => 'Show off something you sell.',
            'icon' => 'package',
            'purpose' => 'product',
            'phrase' => 'promoting a product',
            'kinds' => ['instagram-post', 'facebook-post', 'poster', 'flyer', 'pinterest-pin', 'instagram-story', 'web-banner'],
            'fields' => ['headline', 'description', 'price', 'offer', 'phone', 'whatsapp', 'website', 'cta'],
            'formats' => ['instagram-post-portrait', 'instagram-post-square', 'facebook-post', 'pinterest-pin', 'a5-portrait', 'instagram-story'],
        ],
        'announce_event' => [
            'label' => 'Announce an event',
            'help' => 'Openings, workshops, markets, parties and launches.',
            'icon' => 'calendar',
            'purpose' => 'event',
            'phrase' => 'announcing an event',
            'kinds' => ['poster', 'invitation', 'flyer', 'instagram-post', 'facebook-post', 'instagram-story', 'whatsapp-status'],
            'fields' => ['headline', 'date', 'time', 'venue', 'address', 'price', 'phone', 'whatsapp', 'website', 'cta'],
            'formats' => ['a4-portrait', 'instagram-post-portrait', 'facebook-post', 'instagram-story', 'a5-portrait', 'whatsapp-status'],
        ],
        'social_media' => [
            'label' => 'Share on social media',
            'help' => 'Posts, stories, covers and thumbnails.',
            'icon' => 'share',
            'purpose' => 'social',
            'phrase' => 'sharing on social media',
            'kinds' => ['instagram-post', 'instagram-story', 'facebook-post', 'linkedin-post', 'whatsapp-status', 'tiktok-cover', 'youtube-thumbnail', 'x-post', 'pinterest-pin', 'facebook-cover', 'linkedin-banner'],
            'fields' => ['headline', 'subheadline', 'tagline', 'cta', 'social_handle', 'website'],
            'formats' => ['instagram-post-portrait', 'instagram-post-square', 'instagram-story', 'facebook-post', 'whatsapp-status', 'linkedin-post'],
        ],
        'menu_price_list' => [
            'label' => 'Make a menu or price list',
            'help' => 'For restaurants, salons, shops and services.',
            'icon' => 'list',
            'purpose' => 'menu',
            'phrase' => 'menus and price lists',
            'kinds' => ['menu', 'price-list', 'poster', 'flyer', 'whatsapp-status', 'instagram-post'],
            'fields' => ['headline', 'tagline', 'phone', 'whatsapp', 'address', 'website', 'social_handle'],
            'formats' => ['menu-a4', 'menu-a5', 'a4-portrait', 'whatsapp-status', 'instagram-post-portrait'],
        ],
        'stationery' => [
            'label' => 'Create business stationery',
            'help' => 'Business cards, letterheads, labels and email signatures.',
            'icon' => 'card',
            'purpose' => 'stationery',
            'phrase' => 'business stationery',
            'kinds' => ['business-card', 'letterhead', 'email-signature', 'label', 'voucher', 'certificate', 'email-header'],
            'fields' => ['tagline', 'phone', 'email', 'website', 'address', 'social_handle', 'whatsapp'],
            'formats' => ['business-card-za', 'letterhead-a4', 'email-signature', 'label-100x70', 'voucher-dl', 'business-card-85x55'],
        ],
        'document_report' => [
            'label' => 'Make a document or report',
            'help' => 'Proposals, company profiles, reports, newsletters and presentations.',
            'icon' => 'file',
            'purpose' => 'document',
            'phrase' => 'documents and reports',
            'kinds' => ['report', 'presentation', 'newsletter', 'ebook', 'presentation-cover', 'brochure'],
            'fields' => ['headline', 'subheadline', 'date', 'description', 'email', 'website'],
            'formats' => ['report-a4', 'presentation', 'newsletter-a4', 'ebook-6x9', 'presentation-cover'],
        ],
        'something_else' => [
            'label' => 'Something else',
            'help' => 'Start from a blank page, or tell us a little and we will suggest a size.',
            'icon' => 'sparkle',
            'purpose' => null,
            'phrase' => 'a general design',
            'kinds' => ['poster', 'flyer', 'instagram-post', 'invitation', 'certificate'],
            'fields' => ['headline', 'subheadline', 'description', 'date', 'phone', 'email', 'website'],
            'formats' => ['a4-portrait', 'instagram-post-square', 'a5-portrait', 'facebook-post', 'presentation'],
        ],
    ],

    // Key details. type drives validation (BriefValidator): text | multiline | phone | url | email | handle.
    'fields' => [
        'headline' => ['label' => 'Headline', 'help' => 'The big words people read first. Keep it short.', 'example' => 'Winter pie special', 'type' => 'text', 'max' => 80],
        'subheadline' => ['label' => 'Supporting line', 'help' => 'One short line under the headline.', 'example' => 'Warm pies for cold days', 'type' => 'text', 'max' => 120],
        'tagline' => ['label' => 'Tagline', 'help' => 'A short line that says what you stand for.', 'example' => 'Fresh bread every morning', 'type' => 'text', 'max' => 80],
        'offer' => ['label' => 'Offer', 'help' => 'What the customer gets.', 'example' => '20% off all pies', 'type' => 'text', 'max' => 80],
        'price' => ['label' => 'Price', 'help' => 'Write it exactly as it should print, with the currency.', 'example' => 'R89', 'type' => 'text', 'max' => 40],
        'date' => ['label' => 'Date', 'help' => 'The day or dates, written the way people should read it.', 'example' => 'Saturday, 14 June', 'type' => 'text', 'max' => 60],
        'time' => ['label' => 'Time', 'help' => 'When it starts and ends.', 'example' => '09:00 to 14:00', 'type' => 'text', 'max' => 40],
        'venue' => ['label' => 'Venue', 'help' => 'The place where it happens.', 'example' => 'Community Hall, Main Road', 'type' => 'text', 'max' => 100],
        'address' => ['label' => 'Address', 'help' => 'Your street address.', 'example' => '12 Long Street, Cape Town', 'type' => 'text', 'max' => 140],
        'phone' => ['label' => 'Phone number', 'help' => 'The number customers should call.', 'example' => '021 555 0123', 'type' => 'phone', 'max' => 24],
        'whatsapp' => ['label' => 'WhatsApp number', 'help' => 'The number customers can message.', 'example' => '082 555 0123', 'type' => 'phone', 'max' => 24],
        'email' => ['label' => 'Email address', 'help' => 'Where customers can email you.', 'example' => 'hello@yourbusiness.co.za', 'type' => 'email', 'max' => 120],
        'website' => ['label' => 'Website', 'help' => 'Your web address.', 'example' => 'www.yourbusiness.co.za', 'type' => 'url', 'max' => 140],
        'social_handle' => ['label' => 'Social media handle', 'help' => 'Your name on Instagram, Facebook or TikTok.', 'example' => '@yourbusiness', 'type' => 'handle', 'max' => 31],
        'cta' => ['label' => 'Call to action', 'help' => 'The next step you want people to take.', 'example' => 'Order today', 'type' => 'text', 'max' => 40],
        'description' => ['label' => 'Description', 'help' => 'A few sentences about it.', 'example' => 'Handmade, small batches, delivered to your door.', 'type' => 'multiline', 'max' => 400],
    ],

    // Free-text answers outside "details".
    'text_answers' => [
        'what' => ['label' => 'What are you promoting?', 'max' => 120, 'type' => 'text'],
        'business_name' => ['label' => 'Business name', 'max' => 60, 'type' => 'text'],
    ],

    'styles' => [
        'bold' => ['label' => 'Bold', 'help' => 'Big type, strong colour, hard to ignore.', 'example' => 'Sale posters, event promos'],
        'clean' => ['label' => 'Clean', 'help' => 'Plenty of space, simple and easy to read.', 'example' => 'Professional services, product shots'],
        'elegant' => ['label' => 'Elegant', 'help' => 'Refined type, calm colours, a premium feel.', 'example' => 'Beauty, weddings, fine dining'],
        'playful' => ['label' => 'Playful', 'help' => 'Bright, friendly and full of energy.', 'example' => 'Kids, cafes, parties'],
        'corporate' => ['label' => 'Corporate', 'help' => 'Trustworthy, structured and businesslike.', 'example' => 'Finance, consulting, reports'],
        'dark' => ['label' => 'Dark', 'help' => 'Dark backgrounds with bright accents.', 'example' => 'Nightlife, tech, fitness'],
        'earthy' => ['label' => 'Earthy', 'help' => 'Natural, warm colours and a handmade feel.', 'example' => 'Farms, bakeries, wellness'],
    ],

    // Keywords found in the words the person typed (business name, what, description, offer). Lower case.
    'industries' => [
        'food' => ['label' => 'food and drink', 'keywords' => ['restaurant', 'cafe', 'coffee', 'bakery', 'baker', 'pie', 'bread', 'pizza', 'burger', 'takeaway', 'catering', 'kitchen', 'braai', 'grill', 'bistro', 'diner', 'menu', 'food', 'chef', 'deli', 'bar', 'pub', 'brewery', 'wine', 'tea', 'ice cream', 'sushi']],
        'beauty' => ['label' => 'beauty and hair', 'keywords' => ['salon', 'hair', 'nails', 'nail', 'barber', 'beauty', 'makeup', 'lashes', 'spa', 'skincare', 'braids', 'cosmetic', 'massage']],
        'fitness' => ['label' => 'fitness and wellness', 'keywords' => ['gym', 'fitness', 'yoga', 'pilates', 'trainer', 'crossfit', 'sports', 'wellness', 'workout', 'boxing', 'running']],
        'retail' => ['label' => 'shops and retail', 'keywords' => ['shop', 'store', 'boutique', 'fashion', 'clothing', 'clothes', 'shoes', 'jewellery', 'jewelry', 'gifts', 'furniture', 'sale', 'retail', 'market', 'thrift']],
        'construction' => ['label' => 'building and trades', 'keywords' => ['builder', 'construction', 'plumber', 'plumbing', 'electrician', 'electrical', 'roofing', 'paint', 'painting', 'renovation', 'carpenter', 'tiling', 'maintenance', 'handyman', 'landscaping', 'garden', 'cleaning']],
        'education' => ['label' => 'education and training', 'keywords' => ['school', 'tutor', 'tutoring', 'lessons', 'training', 'course', 'workshop', 'academy', 'college', 'university', 'class', 'learn', 'daycare', 'creche']],
        'health' => ['label' => 'health and care', 'keywords' => ['clinic', 'doctor', 'dentist', 'dental', 'pharmacy', 'physio', 'therapy', 'therapist', 'health', 'medical', 'care', 'nursing', 'vet', 'optometrist']],
        'automotive' => ['label' => 'cars and transport', 'keywords' => ['car', 'cars', 'auto', 'tyre', 'tyres', 'mechanic', 'panel', 'wash', 'taxi', 'transport', 'courier', 'delivery', 'logistics', 'driving']],
        'property' => ['label' => 'property', 'keywords' => ['property', 'estate', 'rental', 'rentals', 'realtor', 'landlord', 'apartment', 'house', 'homes', 'bnb', 'guesthouse', 'lodge']],
        'professional' => ['label' => 'professional services', 'keywords' => ['accounting', 'accountant', 'bookkeeping', 'consulting', 'consultant', 'legal', 'attorney', 'law', 'insurance', 'finance', 'tax', 'advisor', 'agency', 'audit', 'recruitment']],
        'tech' => ['label' => 'technology', 'keywords' => ['software', 'app', 'tech', 'digital', 'web', 'website design', 'it support', 'computer', 'cyber', 'data', 'startup', 'ai']],
        'events' => ['label' => 'events and entertainment', 'keywords' => ['event', 'events', 'wedding', 'party', 'dj', 'band', 'concert', 'festival', 'photography', 'photographer', 'decor', 'venue', 'entertainment']],
        'agriculture' => ['label' => 'farming and nature', 'keywords' => ['farm', 'farming', 'organic', 'harvest', 'produce', 'nursery', 'livestock', 'dairy', 'honey', 'plants']],
        'community' => ['label' => 'community and non-profit', 'keywords' => ['church', 'ngo', 'charity', 'community', 'foundation', 'non-profit', 'nonprofit', 'fundraiser', 'volunteer', 'youth']],
    ],

    'limits' => [
        'recommendations' => 12,
        'max_photos' => 3,
    ],
];
