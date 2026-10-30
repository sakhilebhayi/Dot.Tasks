<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone.
 *
 * Enforced now: nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy, a Permissions-Policy that denies the powerful
 * features this platform never uses, and HSTS on HTTPS requests only (no includeSubDomains / preload on purpose: the
 * sibling Dot platforms live on neighbouring subdomains and set their own).
 *
 * The Content-Security-Policy is REPORT-ONLY. The pages use inline scripts and styles, Alpine/Livewire and the Tailwind
 * CDN, so the policy allows 'unsafe-inline' and 'unsafe-eval' for now. Violations are reported to /csp-report, which
 * logs only the directive and the blocked host, so the origin list below can be checked against what the pages
 * really load before anything is enforced. Do not add an origin that no view loads.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        $policy = implode('; ', [
            "default-src 'self'",
            // cdn.tailwindcss.com: the Tailwind Play CDN script in layouts/app.blade.php.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com",
            // fonts.googleapis.com: the Google Fonts stylesheets (all layouts, welcome page, error pages).
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            // fonts.gstatic.com: the font files those stylesheets point at.
            "font-src 'self' https://fonts.gstatic.com",
            // images.unsplash.com: the welcome page photographs (CSS background-image).
            "img-src 'self' data: blob: https://images.unsplash.com",
            // Reverb (Laravel Echo) websockets for the live task board; local development talks plaintext ws:.
            "connect-src 'self' wss: https:".(app()->isLocal() ? ' ws:' : ''),
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri /csp-report',
        ]);

        $headers[config('security.csp_report_only', true) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $policy;

        if ($request->isSecure() && config('security.hsts_seconds', 0) > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_seconds');
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
