<?php

/*
|--------------------------------------------------------------------------
| Dot.Design <-> other Dot platforms (R-45)
|--------------------------------------------------------------------------
| Every integration here talks to a contract that was read in the other platform's own code
| (see docs/R45_INTEGRATIONS.md for the file references). Each one is OFF by default: it needs
| its flag AND its url/secret, and the app behaves exactly the same without it. Secrets come
| from the environment only and are never logged.
|
| Platforms that expose no machine contract are listed under `no_contract` so the settings page
| can say so honestly. Nothing is simulated for them.
*/

return [

    // Calls that fail transiently this many times in a row pause that integration for `cooldown` seconds.
    'breaker' => [
        'threshold' => (int) env('DESIGN_INTEGRATION_BREAKER_THRESHOLD', 5),
        'cooldown' => (int) env('DESIGN_INTEGRATION_BREAKER_COOLDOWN', 120),
    ],

    /*
    | Dot.Notify: POST {url} (full URL ending /api/ingest/events), HMAC-SHA256 over "{timestamp}.{raw body}" with this
    | platform's NOTIFY_INGEST_SECRET_DESIGN (same value on both sides). Notify only emails when the company has an
    | active rule for the event name, so a company must switch each design.* event on in Notify.
    */
    'notify' => [
        'enabled' => (bool) env('DESIGN_INTEGRATION_NOTIFY', false),
        'url' => env('NOTIFY_INGEST_URL'),
        'secret' => env('NOTIFY_INGEST_SECRET'),
        'platform' => env('NOTIFY_PLATFORM_KEY', 'design'),
        'timeout' => (int) env('NOTIFY_INGEST_TIMEOUT', 5),
    ],

    /*
    | Dot.Files platform file API: POST {url}/api/platform/files/{store,update,...}, headers X-Dot-Platform,
    | X-Dot-Timestamp, X-Dot-Signature = sha256=HMAC("{timestamp}.{raw body}", secret). The secret equals
    | DOT_SERVICE_SECRET_DESIGN in Dot.Files' environment, and Dot.Files must list `design` in its platform_secrets.
    */
    'files' => [
        'enabled' => (bool) env('DESIGN_INTEGRATION_FILES', false),
        'url' => env('FILES_API_URL', 'https://files.infodot.co.za'),
        'secret' => env('FILES_PLATFORM_SECRET'),
        'platform' => env('FILES_PLATFORM_KEY', 'design'),
        'folder' => env('FILES_FOLDER', 'Dot.Design'),
        'timeout' => (int) env('FILES_TIMEOUT', 20),
        // What an exported design can be filed as. Dot.Files accepts pdf, png, jpg, jpeg, gif, webp, svg, zip, mp4, ... but no json.
        'formats' => ['png', 'jpg', 'pdf', 'svg', 'gif'],
        'max_bytes' => 25 * 1024 * 1024,
    ],

    /*
    | InfoDot membership webhook (InfoDot -> Design): POST /api/ecosystem/membership, signed with INFODOT_SERVICE_SECRET
    | (the same secret as sign-in). InfoDot only sends it to platforms listed in its ECOSYSTEM_MEMBERSHIP_PLATFORMS, so
    | the owner must add `design` there as well.
    */
    'membership' => [
        'enabled' => (bool) env('DESIGN_INTEGRATION_MEMBERSHIP', false),
    ],

    // Platforms inspected that expose no contract Dot.Design can use. Reasons are shown to people, so keep them plain.
    'no_contract' => [
        'tasks' => [
            'name' => 'Dot.Tasks',
            'reason' => 'Dot.Tasks has no way for another app to create or update a task. Its only API route returns the signed-in user.',
        ],
        'projects' => [
            'name' => 'Dot.Projects',
            'reason' => 'Dot.Projects has no way for another app to attach a design to a project. Its only API route returns the signed-in user.',
        ],
        'agents' => [
            'name' => 'Dot.Agents',
            'reason' => 'Dot.Agents runs only with a per-business API key a person creates by hand. It has no app-to-app sign-in that Dot.Design could use.',
        ],
        'brain' => [
            'name' => 'Dot.Brain',
            'reason' => 'Dot.Brain is a repository of documents and tools, not a running service with an API.',
        ],
        'memory' => [
            'name' => 'Dot.Memory',
            'reason' => 'Dot.Memory only accepts calls from the platforms registered as its consumers. Dot.Design is not one of them.',
        ],
        'emall' => [
            'name' => 'Dot.Emall',
            'reason' => 'Dot.Emall has no API for product images or banners. Its only API route returns the signed-in user.',
        ],
        'pulse' => [
            'name' => 'Dot.Pulse',
            'reason' => 'Dot.Pulse has no API for posts or images. Its only API route returns the signed-in user.',
        ],
        'hr' => [
            'name' => 'Dot.HR',
            'reason' => 'Dot.HR has no API for brand or job-ad assets. Its only API route returns the signed-in user.',
        ],
        'press' => [
            'name' => 'Dot.Press',
            'reason' => 'Dot.Press has an asset API, but it needs a personal access token for each person, which Dot.Design cannot hold on their behalf.',
        ],
        'engage' => [
            'name' => 'Dot.Engage',
            'reason' => 'Dot.Engage has no API another app can use. Its only API route returns the signed-in user.',
        ],
        'forms' => [
            'name' => 'Dot.Forms',
            'reason' => 'Dot.Forms has no API another app can use. Its only API route returns the signed-in user.',
        ],
    ],

];
