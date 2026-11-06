<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone.
 *
 * X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS requests only)
 * Strict-Transport-Security. HSTS covers this host alone: includeSubDomains and preload are left out on purpose
 * because the sibling Dot platforms live on neighbouring subdomains and each sets its own.
 *
 * The Content-Security-Policy is ENFORCED and nonce-based, the shape InfoDot, Dot.HR and Dot.Projects run live: a
 * per-request nonce is registered with Vite before the views render, so @vite tags and Livewire's assets carry it,
 * and the one hand-written inline script (the theme-before-paint snippet) declares it. script-src-attr is 'none':
 * no view uses on*= handlers. 'unsafe-eval' REMAINS as a stack requirement, because Livewire 3's bundled Alpine
 * evaluates its expressions with the Function constructor. style-src keeps 'unsafe-inline' because the views use
 * style attributes (timeline bars, chart widths). Every origin below is one the views or the framework load.
 *
 * Violations report to /csp-report, which logs only the directive and the blocked host.
 * SECURITY_CSP_REPORT_ONLY=true in .env is the kill-switch back to report-only without a deploy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Registered before the views render, so every tag that needs it carries the value the header announces.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            (config('security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($nonce),
        ];

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

    private function policy(string $nonce): string
    {
        // Local development loads assets from the Vite server (http: and its ws: hot reload).
        $dev = app()->isLocal() ? ' http://localhost:* http://127.0.0.1:*' : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'{$dev}",
            "script-src-attr 'none'",
            // fonts.googleapis.com: the Google Fonts stylesheets (all layouts, welcome page, error pages).
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$dev}",
            // fonts.gstatic.com: the font files those stylesheets point at.
            "font-src 'self' data: https://fonts.gstatic.com",
            // ui-avatars.com: Jetstream's default avatar on the team and profile pages.
            "img-src 'self' data: blob: https://ui-avatars.com",
            // Reverb (Laravel Echo) websockets for the live task board; local development talks plaintext ws:.
            "connect-src 'self' wss: https:".(app()->isLocal() ? ' ws: http:' : ''),
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            // Sign-in hands over to InfoDot over https; every other form posts here.
            "form-action 'self' https:",
            'report-uri /csp-report',
        ]);
    }
}
