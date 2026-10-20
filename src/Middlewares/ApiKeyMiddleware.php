<?php

namespace Ace\Middlewares;

use Ace\ApiKey;
use Ace\Application;
use Ace\Middleware;

/**
 * ApiKeyMiddleware — Validates API Key Authentication
 *
 * Checks for a valid API key in:
 *   1. Authorization: Bearer <key> header
 *   2. X-Api-Key: <key> header
 *   3. ?api_key=<key> query parameter (not recommended for production)
 *
 * On success, stores the validated key record in the request for downstream use.
 * On failure, returns 401 Unauthorized JSON response.
 */
class ApiKeyMiddleware extends Middleware
{
    protected function run(): void
    {
        $request = Application::$app->request;

        // 1. Try Authorization: Bearer <key>
        $apiKey = $request->bearerToken();

        // 2. Try X-Api-Key header
        if (!$apiKey) {
            $apiKey = $request->header('X-Api-Key');
        }

        // 3. Try query parameter (fallback, not recommended)
        if (!$apiKey) {
            $apiKey = $_GET['api_key'] ?? null;
        }

        if (!$apiKey) {
            Application::$app->response->json([
                'status'  => 'error',
                'message' => 'API key is required. Provide it via Authorization: Bearer <key> or X-Api-Key header.',
                'code'    => 401,
            ], 401);
        }

        $keyRecord = ApiKey::validate($apiKey);

        if ($keyRecord === false) {
            Application::$app->response->json([
                'status'  => 'error',
                'message' => 'Invalid or expired API key.',
                'code'    => 401,
            ], 401);
        }

        // Store the validated key record for downstream access
        // Controllers can access via: $request->apiKey()
        $request->setApiKey($keyRecord);
    }
}
