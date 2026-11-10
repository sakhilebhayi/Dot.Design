<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone.
 *
 * Content-Security-Policy is ENFORCED (R-45 security S-5) and built per response around a fresh nonce:
 *  - scripts run only from this origin or carrying the nonce (Vite tags and Livewire's own tags pick it up through
 *    Vite::useCspNonce); there is no inline script in any view, no external script host, no 'unsafe-inline' for scripts;
 *  - 'unsafe-eval' is added ONLY to a page that ships Livewire's bundled Alpine (it evaluates x-data expressions with
 *    new Function). The editor, the share viewer, the start flow's own scripts and every page without Livewire never get it.
 *    The test suite renders the pages and fails if a page that has no Livewire carries it;
 *  - no external host for anything: fonts, images, styles and connections are same-origin (plus data:/blob: for the
 *    exports the editor builds in the browser);
 *  - frames, objects, plugins, <base> and form targets are closed down; the app is never framed.
 *
 * Styles allow inline style attributes (the design system sets custom properties that way) but not <style> elements,
 * except on Livewire pages, whose runtime injects them. Set SECURITY_CSP_REPORT_ONLY=true to fall back to report-only
 * while a violation is investigated; reports go to /csp-report either way.
 *
 * HSTS is sent on HTTPS requests only (no includeSubDomains / preload on purpose: the sibling Dot platforms live on
 * neighbouring subdomains and set their own).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // The nonce must exist before the view renders (inside $next): Vite and Livewire read it while writing their tags.
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        app(Vite::class)->useCspNonce($nonce);

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => implode(', ', [
                'camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()', 'bluetooth=()', 'serial=()', 'hid=()', 'midi=()',
                'display-capture=()', 'accelerometer=()', 'gyroscope=()', 'magnetometer=()', 'interest-cohort=()',
            ]),
            // A window opened from, or opening, another origin gets no handle on this one.
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($this->isHtml($response)) {
            $policy = $this->policy($nonce, $request->isSecure(), $this->shipsAlpine($response));
            $headers[config('security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $policy;
        }

        if ($request->isSecure() && config('security.hsts_seconds', 0) > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_seconds');
        }

        // Signed-in pages hold a business's work: search engines have no business with them.
        if ($request->user() !== null) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By'); // PHP's own banner, added outside the response object

        return $response;
    }

    /** @return non-empty-string */
    private function policy(string $nonce, bool $secure, bool $alpine): string
    {
        $scripts = "'self' 'nonce-{$nonce}'".($alpine ? " 'unsafe-eval'" : '');

        $directives = [
            "default-src 'self'",
            "script-src {$scripts}",
            // Livewire's runtime adds <style> elements (wire:loading, wire:cloak) that cannot carry a nonce.
            $alpine ? "style-src 'self' 'unsafe-inline'" : "style-src 'self'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            'report-uri /csp-report',
        ];

        if ($secure) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private function isHtml(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type', '');

        // A response with no declared type is treated as a page too (Laravel adds text/html when it sends it).
        return $type === '' || str_contains($type, 'text/html');
    }

    /** True when the page carries Livewire's script bundle, which includes Alpine and its expression evaluator. */
    private function shipsAlpine(Response $response): bool
    {
        $content = $response->getContent();

        // The signed-in layout has no @livewireScripts: Livewire injects its bundle AFTER this middleware runs, so a page whose
        // components are already rendered into the HTML (wire:snapshot / wire:id) is a Livewire page too.
        return is_string($content) && (
            str_contains($content, 'livewireScriptConfig')
            || str_contains($content, '/livewire/livewire')
            || str_contains($content, 'wire:snapshot')
            || str_contains($content, 'wire:id=')
        );
    }
}
