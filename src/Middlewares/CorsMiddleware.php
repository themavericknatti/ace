<?php

namespace Ace\Middlewares;

use Ace\Application;
use Ace\Middleware;

/**
 * CorsMiddleware — Cross-Origin Resource Sharing
 *
 * Handles CORS headers for API routes, enabling frontend frameworks
 * (React, Next.js, Nuxt.js, Vue) to communicate with the Ace backend.
 *
 * Configuration via config/cors.php or .env:
 *   CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:5173
 *   CORS_ALLOWED_METHODS=GET,POST,PUT,PATCH,DELETE,OPTIONS
 *   CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Api-Key,X-Requested-With
 *   CORS_MAX_AGE=86400
 *   CORS_ALLOW_CREDENTIALS=false
 */
class CorsMiddleware extends Middleware
{
    protected function run(): void
    {
        // Load CORS configuration
        $config = $this->getConfig();

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Determine allowed origin
        $allowedOrigin = $this->resolveAllowedOrigin($origin, $config['allowed_origins']);

        if ($allowedOrigin && !headers_sent()) {
            header("Access-Control-Allow-Origin: {$allowedOrigin}");

            if ($config['allow_credentials']) {
                header('Access-Control-Allow-Credentials: true');
            }

            // Vary header ensures caches treat different origins separately
            header('Vary: Origin');
        }

        // Handle preflight OPTIONS request
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') {
            if (!headers_sent()) {
                header('Access-Control-Allow-Methods: ' . implode(', ', $config['allowed_methods']));
                header('Access-Control-Allow-Headers: ' . implode(', ', $config['allowed_headers']));
                header('Access-Control-Max-Age: ' . $config['max_age']);
            }
            http_response_code(204);
            exit;
        }

        // Expose headers for non-preflight requests
        if (!empty($config['exposed_headers']) && !headers_sent()) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $config['exposed_headers']));
        }
    }

    /**
     * Load CORS configuration from config/cors.php, falling back to .env or defaults.
     */
    protected function getConfig(): array
    {
        // Try loading config/cors.php
        $configFile = Application::$ROOT_DIR . '/config/cors.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }

        // Fallback to .env variables or sensible defaults
        $allowedOrigins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*';
        $allowedMethods = $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS';
        $allowedHeaders = $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type,Authorization,X-Api-Key,X-Requested-With,Accept';
        $maxAge = (int)($_ENV['CORS_MAX_AGE'] ?? 86400);
        $credentials = in_array(strtolower($_ENV['CORS_ALLOW_CREDENTIALS'] ?? 'false'), ['true', '1', 'yes'], true);

        return [
            'allowed_origins'  => $allowedOrigins === '*' ? ['*'] : array_map('trim', explode(',', $allowedOrigins)),
            'allowed_methods'  => array_map('trim', explode(',', $allowedMethods)),
            'allowed_headers'  => array_map('trim', explode(',', $allowedHeaders)),
            'exposed_headers'  => [],
            'max_age'          => $maxAge,
            'allow_credentials' => $credentials,
        ];
    }

    /**
     * Determine the Access-Control-Allow-Origin value.
     */
    protected function resolveAllowedOrigin(string $origin, array $allowedOrigins): string
    {
        // Wildcard allows all origins
        if (in_array('*', $allowedOrigins, true)) {
            return '*';
        }

        // Check if the request origin is in the allowed list
        if ($origin && in_array($origin, $allowedOrigins, true)) {
            return $origin;
        }

        // Check for wildcard subdomain patterns (e.g. *.example.com)
        foreach ($allowedOrigins as $allowed) {
            if (str_starts_with($allowed, '*.')) {
                $domain = substr($allowed, 2);
                if (str_ends_with($origin, $domain)) {
                    return $origin;
                }
            }
        }

        return '';
    }
}
