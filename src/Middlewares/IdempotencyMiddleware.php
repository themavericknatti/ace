<?php

namespace Ace\Middlewares;

use Ace\Application;
use Ace\Middleware;
use Ace\IdempotencyManager;

/**
 * IdempotencyMiddleware — Enforces Idempotent API Mutations
 *
 * Reads client-supplied `Idempotency-Key` or `X-Idempotency-Key` header on mutation requests
 * (POST, PUT, PATCH, DELETE).
 *
 * - If key has already been completed: replays exact cached response code & body with `Idempotent-Replayed: true`.
 * - If key is currently in-flight: returns 409 Conflict with a retry recommendation.
 * - If key has mismatching payload: returns 422 Unprocessable Entity with error message.
 * - If key is new: records lock, allows execution, captures response, and stores it for 24h.
 */
class IdempotencyMiddleware extends Middleware
{
    /**
     * Header names to check for idempotency key.
     */
    protected const HEADER_KEYS = [
        'Idempotency-Key',
        'X-Idempotency-Key',
    ];

    /**
     * Whether to require an Idempotency-Key on all mutation requests.
     * When false (default), requests without the header proceed normally without caching.
     */
    protected bool $required = false;

    public function __construct(array $actions = [], bool $required = false)
    {
        parent::__construct($actions);
        $this->required = $required;
    }

    protected function run(): void
    {
        $request = Application::$app->request;
        $response = Application::$app->response;

        // Idempotency applies primarily to mutation methods (POST, PUT, PATCH, DELETE)
        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // 1. Extract Idempotency-Key from headers
        $key = null;
        foreach (self::HEADER_KEYS as $headerName) {
            $val = $request->header($headerName);
            if ($val !== null && trim($val) !== '') {
                $key = trim($val);
                break;
            }
        }

        if ($key === null) {
            if ($this->required) {
                $response->json([
                    'status'  => 'error',
                    'message' => 'Idempotency-Key header is required for this request.',
                    'code'    => 400,
                ], 400);
            }
            return;
        }

        // 2. Determine Scope (Tenant/User/ApiKey isolation)
        $scope = 'guest';
        if ($apiKey = $request->apiKey()) {
            $scope = 'key_' . ($apiKey['prefix'] ?? $apiKey['id']);
        } elseif ($user = Application::$app->user) {
            $scope = 'user_' . ($user->id ?? 'auth');
        } elseif ($userId = Application::$app->session->get('user_id')) {
            $scope = 'user_' . $userId;
        }

        // 3. Compute deterministic request hash
        $manager = new IdempotencyManager();
        $path = $request->getPath();
        $body = $request->getRawBody();
        $requestHash = $manager->computeRequestHash($method, $path, $body);

        // 4. Check existing state
        $check = $manager->check($key, $scope, $requestHash);

        if ($check['status'] === 'mismatch') {
            $response->json([
                'status'  => 'error',
                'message' => 'Idempotency key was previously used with a different request payload or endpoint.',
                'code'    => 422,
            ], 422);
        }

        if ($check['status'] === 'in_flight') {
            $response->setHeader('Retry-After', '2');
            $response->json([
                'status'  => 'error',
                'message' => 'A request with this Idempotency-Key is currently in progress. Please retry in a few seconds.',
                'code'    => 409,
            ], 409);
        }

        if ($check['status'] === 'completed') {
            $record = $check['record'];
            $code = (int)($record['response_code'] ?? 200);
            $bodyContent = $record['response_body'] ?? '';

            // Mark response as replayed from idempotency cache
            $response->setHeader('Idempotent-Replayed', 'true');
            $response->setHeader('Content-Type', 'application/json');

            // Send stored response
            $response->setStatusCode($code);
            echo $bodyContent;
            if (php_sapi_name() !== 'cli') {
                exit;
            }
            return;
        }

        // 5. Status is 'new': register shutdown hook or output buffer to capture response and mark completed
        // Store metadata in request for capture on completion
        $this->captureOnTermination($manager, $key, $scope);
    }

    /**
     * Intercept response output on script completion and store it in database.
     */
    protected function captureOnTermination(IdempotencyManager $manager, string $key, string $scope): void
    {
        ob_start(function ($buffer) use ($manager, $key, $scope) {
            $statusCode = http_response_code() ?: 200;
            // Only cache successful or client error responses (2xx, 4xx). Do not cache 5xx server crashes.
            if ($statusCode < 500) {
                $manager->complete($key, $scope, $statusCode, $buffer, headers_list() ? [] : []);
            }
            return $buffer;
        });
    }
}
