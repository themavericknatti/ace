<?php

namespace Ace\Middlewares;

use Ace\Middleware;

class SecurityHeadersMiddleware extends Middleware
{
    /**
     * Inject standard security response headers to secure application pages
     */
    protected function run(): void
    {
        $path = \Ace\Application::$app->request->getPath();
        $isApi = str_starts_with($path, '/api/') || str_starts_with($path, 'api/');
        $isEmbed = str_starts_with($path, '/embed/') || str_starts_with($path, 'embed/');

        // API endpoints: Enable Cross-Origin Resource Sharing (CORS) for external domains
        if ($isApi) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Evently-Key');
            header('Access-Control-Max-Age: 86400');
            header('X-Content-Type-Options: nosniff');
            return;
        }

        // 1. Prevent Clickjacking (allow embedding only on /embed/ routes)
        if (!$isEmbed) {
            header('X-Frame-Options: SAMEORIGIN');
        }

        // 2. Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // 3. Prevent XSS attacks in legacy browsers
        header('X-XSS-Protection: 1; mode=block');

        // 4. Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // 5. Enable Strict Transport Security (HSTS) if HTTPS is enabled
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // 6. Content Security Policy (CSP)
        // Restricts where scripts, styles, fonts, and images can be loaded from.
        // We whitelist popular CDNs, payment gateways, and image hosts to avoid blocking legitimate resources.
        $frameAncestors = $isEmbed ? "frame-ancestors *;" : "frame-ancestors 'none';";
        $cspHeader = "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com https://code.jquery.com https://js.paystack.co https://checkout.flutterwave.com https://js.stripe.com; " .
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://maxcdn.bootstrapcdn.com https://unpkg.com https://fonts.googleapis.com; " .
            "font-src 'self' data: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
            "img-src 'self' data: https: blob:; " .
            "connect-src 'self' https://api.paystack.co https://api.flutterwave.com https://api.stripe.com; " .
            "frame-src 'self' https://checkout.paystack.com https://checkout.flutterwave.com https://js.stripe.com; " .
            $frameAncestors;

        header($cspHeader);
    }
}
