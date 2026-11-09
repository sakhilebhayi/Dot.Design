<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains are deliberately not included
    | (no includeSubDomains, no preload): the other Dot platforms sit on neighbouring subdomains and set their own.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),   // 180 days

    /*
    | The Content-Security-Policy is ENFORCED, with a fresh nonce per response (see App\Http\Middleware\SecurityHeaders). Set this to true
    | only to fall back to report-only while a violation is being investigated: the browser then reports what the policy would
    | have blocked (to /csp-report) and blocks nothing.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
];
