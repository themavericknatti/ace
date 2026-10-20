<?php

namespace Ace;

class Request
{
    /**
     * The validated API key record (set by ApiKeyMiddleware)
     */
    protected ?array $apiKeyRecord = null;

    /**
     * Store the validated API key record.
     * Called by ApiKeyMiddleware after successful validation.
     */
    public function setApiKey(array $record): void
    {
        $this->apiKeyRecord = $record;
    }

    /**
     * Get the validated API key record.
     *
     * @return array|null The key record or null if not an API request
     */
    public function apiKey(): ?array
    {
        return $this->apiKeyRecord;
    }

    /**
     * Get the API key name (convenience method).
     */
    public function apiKeyName(): ?string
    {
        return $this->apiKeyRecord['name'] ?? null;
    }

    /**
     * Check if the authenticated API key has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->apiKeyRecord) {
            return false;
        }

        return \Ace\ApiKey::hasPermission($this->apiKeyRecord, $permission);
    }

    /**
     * Get the relative request path, stripping base subdirectories and query params
     */
    public function getPath(): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query parameters
        $position = strpos($path, '?');
        if ($position !== false) {
            $path = substr($path, 0, $position);
        }

        // Auto-detect and strip subdirectory path (e.g. /mvc/public/)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? ''; // e.g., /mvc/public/index.php
        $baseDir = dirname($scriptName);       // e.g., /mvc/public

        // Normalize backslashes to forward slashes just in case
        $baseDir = str_replace('\\', '/', $baseDir);

        if ($baseDir !== '/' && !empty($baseDir)) {
            if (strpos($path, $baseDir) === 0) {
                $path = substr($path, strlen($baseDir));
            } elseif (str_ends_with($baseDir, '/public')) {
                $parentBase = substr($baseDir, 0, -7);
                if ($parentBase !== '' && strpos($path, $parentBase) === 0) {
                    $path = substr($path, strlen($parentBase));
                }
            }
        }

        // Strip index.php if present at the start of the path
        if (str_starts_with($path, '/index.php')) {
            $path = (string) substr($path, 10);
        }

        // Ensure path starts with a slash
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // Return '/' for root, otherwise strip trailing slash to match routes consistently
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Alias for getPath()
     */
    public function path(): string
    {
        return $this->getPath();
    }

    /**
     * Get HTTP request method (e.g. 'get', 'post')
     * Supports form method spoofing via _method hidden field for PUT/PATCH/DELETE
     */
    public function method(): string
    {
        $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');

        // Support form method spoofing: <input type="hidden" name="_method" value="PUT">
        if ($method === 'post') {
            $spoofed = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
            if ($spoofed) {
                $spoofed = strtolower($spoofed);
                if (in_array($spoofed, ['put', 'patch', 'delete'], true)) {
                    return $spoofed;
                }
            }
        }

        return $method;
    }

    /**
     * Alias for method() to match PSR-7 / Symfony conventions
     */
    public function getMethod(): string
    {
        return $this->method();
    }

    public function isGet(): bool
    {
        return $this->method() === 'get';
    }

    public function isPost(): bool
    {
        return $this->method() === 'post';
    }

    public function isPut(): bool
    {
        return $this->method() === 'put';
    }

    public function isPatch(): bool
    {
        return $this->method() === 'patch';
    }

    public function isDelete(): bool
    {
        return $this->method() === 'delete';
    }

    /**
     * Determine if the request was made via AJAX or expects JSON response
     */
    public function isAjax(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }

    /**
     * Retrieve and sanitize request input parameters
     */
    public function getBody(): array
    {
        $body = [];
        $actualMethod = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');

        if ($actualMethod === 'get') {
            foreach ($_GET as $key => $value) {
                $body[$key] = $this->sanitize($value);
            }
        }

        if ($actualMethod === 'post') {
            // POST carries body for POST, PUT, PATCH, DELETE (via method spoofing)
            $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $rawJson = file_get_contents('php://input');
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $value) {
                        $body[$key] = $this->sanitize($value);
                    }
                }
            } else {
                foreach ($_POST as $key => $value) {
                    if ($key === '_method') continue; // Don't include spoofing field
                    $body[$key] = $this->sanitize($value);
                }
            }
        }

        // Native PUT/PATCH/DELETE (non-spoofed, e.g. from API clients)
        if (in_array($actualMethod, ['put', 'patch', 'delete'], true)) {
            $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $rawJson = file_get_contents('php://input');
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $value) {
                        $body[$key] = $this->sanitize($value);
                    }
                }
            } else {
                // Parse form-encoded body from php://input for PUT/PATCH/DELETE
                parse_str(file_get_contents('php://input'), $parsed);
                foreach ($parsed as $key => $value) {
                    if ($key === '_method') continue;
                    $body[$key] = $this->sanitize($value);
                }
            }
        }

        return $body;
    }

    /**
     * Retrieve raw, unsanitized request input parameters
     */
    public function getRawBody(): array
    {
        $body = [];
        $actualMethod = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');

        if ($actualMethod === 'get') {
            foreach ($_GET as $key => $value) {
                $body[$key] = $value;
            }
        }

        if ($actualMethod === 'post') {
            $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $rawJson = file_get_contents('php://input');
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $value) {
                        $body[$key] = $value;
                    }
                }
            } else {
                foreach ($_POST as $key => $value) {
                    if ($key === '_method') continue;
                    $body[$key] = $value;
                }
            }
        }

        if (in_array($actualMethod, ['put', 'patch', 'delete'], true)) {
            $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $rawJson = file_get_contents('php://input');
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $value) {
                        $body[$key] = $value;
                    }
                }
            } else {
                parse_str(file_get_contents('php://input'), $parsed);
                foreach ($parsed as $key => $value) {
                    if ($key === '_method') continue;
                    $body[$key] = $value;
                }
            }
        }

        return $body;
    }

    /**
     * Get a specific raw, unsanitized input parameter by key
     * Useful for rich text / WYSIWYG editor content that legitimately contains HTML
     */
    public function getRaw(string $key, mixed $default = null): mixed
    {
        $raw = $this->getRawBody();
        return $raw[$key] ?? $default;
    }

    /**
     * Get a specific input parameter by key (sanitized)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $body = $this->getBody();
        return $body[$key] ?? $default;
    }

    /**
     * Check if a parameter exists in the request body
     */
    public function has(string $key): bool
    {
        $body = $this->getBody();
        return isset($body[$key]) && $body[$key] !== '';
    }

    /**
     * Get a parameter as a trimmed string
     */
    public function getString(string $key, string $default = ''): string
    {
        $val = $this->get($key, $default);
        if (is_array($val)) {
            return $default;
        }
        return trim((string)$val);
    }

    /**
     * Get a parameter as an integer
     */
    public function getInt(string $key, int $default = 0): int
    {
        $val = $this->get($key, $default);
        if (!is_numeric($val)) {
            return $default;
        }
        return (int)$val;
    }

    /**
     * Get a parameter as a boolean
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $val = $this->get($key, $default);
        return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Get a parameter as an array
     */
    public function getArray(string $key, array $default = []): array
    {
        $val = $this->get($key, $default);
        return is_array($val) ? $val : $default;
    }

    /**
     * Check if the current request path matches a pattern (supports wildcards e.g. 'admin/*')
     */
    // public function is(string $pattern): bool
    // {
    //     $path = trim($this->getPath(), '/');
    //     $pattern = trim($pattern, '/');

    //     // Convert '*' to regex wildcard match
    //     $regex = str_replace('*', '.*', $pattern);
    //     $regex = "@^" . $regex . "$@i";

    //     return (bool)preg_match($regex, $path);
    // }

    public function is(string|array ...$patterns): bool
    {
        $path = trim($this->getPath(), '/');

        $patterns = array_merge(
            ...array_map(
                fn($pattern) => is_array($pattern) ? $pattern : [$pattern],
                $patterns
            )
        );

        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }
            
            $pattern = trim($pattern, '/');

            $regex = preg_quote($pattern, '@');
            $regex = str_replace('\*', '.*', $regex);

            if (preg_match("@^{$regex}$@i", $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the full absolute request URL
     */
    public function getFullUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $scheme . '://' . $host . $uri;
    }

    /**
     * Get all input parameters (alias for getBody())
     */
    public function all(): array
    {
        return $this->getBody();
    }

    /**
     * Retrieve a subset of request input parameters.
     *
     *   $data = $request->only(['name', 'email']);
     *   $data = $request->only('name', 'email');
     */
    public function only(string|array ...$keys): array
    {
        $keys = array_merge(
            ...array_map(fn($k) => is_array($k) ? $k : [$k], $keys)
        );

        $body = $this->getBody();
        $results = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                $results[$key] = $body[$key];
            }
        }

        return $results;
    }

    /**
     * Retrieve all input parameters except specified keys.
     *
     *   $data = $request->except(['_token', '_method']);
     *   $data = $request->except('_token', '_method');
     */
    public function except(string|array ...$keys): array
    {
        $keys = array_merge(
            ...array_map(fn($k) => is_array($k) ? $k : [$k], $keys)
        );

        $body = $this->getBody();
        foreach ($keys as $key) {
            unset($body[$key]);
        }

        return $body;
    }

    /**
     * Get a request header value.
     */
    public function header(string $key, ?string $default = null): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        
        if (isset($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }

        if (in_array(strtolower($key), ['content-type', 'content_type'], true) && isset($_SERVER['CONTENT_TYPE'])) {
            return $_SERVER['CONTENT_TYPE'];
        }
        if (in_array(strtolower($key), ['content-length', 'content_length'], true) && isset($_SERVER['CONTENT_LENGTH'])) {
            return $_SERVER['CONTENT_LENGTH'];
        }

        return $default;
    }

    /**
     * Retrieve a Bearer token from the Authorization header.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization') ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Get client IP address
     */
    public function ip(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';

        // In case of multiple forwarded IPs, take the first one
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return $ip;
    }

    /**
     * Recursively sanitize values to prevent XSS
     */
    private function sanitize(mixed $value): mixed
    {
        return XssSanitizer::clean($value);
    }
}

