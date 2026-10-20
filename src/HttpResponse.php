<?php

namespace Ace;

use Exception;

/**
 * HttpResponse — Encapsulates an HTTP response returned by Ace\Http.
 */
class HttpResponse
{
    protected string $body;
    protected int $status;
    protected array $headers;
    protected ?array $json = null;

    public function __construct(string $body, int $status, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    /**
     * Get raw response body.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Get HTTP status code.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Get all response headers.
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Retrieve a specific header by name (case-insensitive).
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $normalized = strtolower(trim($name));
        foreach ($this->headers as $key => $value) {
            if (strtolower(trim($key)) === $normalized) {
                return is_array($value) ? implode(', ', $value) : (string)$value;
            }
        }
        return $default;
    }

    /**
     * Decode JSON response body with optional dot-notation key lookup.
     *
     * Example:
     *   $name = $response->json('data.user.name', 'Anonymous');
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->json === null) {
            $decoded = json_decode($this->body, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }

        if ($key === null) {
            return $this->json;
        }

        $segments = explode('.', $key);
        $current = $this->json;
        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return $default;
            }
        }

        return $current;
    }

    /**
     * Determine if response status is in 2xx range.
     */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Determine if response status is 200 OK.
     */
    public function ok(): bool
    {
        return $this->status === 200;
    }

    /**
     * Determine if response status is 201 Created.
     */
    public function created(): bool
    {
        return $this->status === 201;
    }

    /**
     * Determine if response status is in 4xx range.
     */
    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Determine if response status is in 5xx range.
     */
    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * Determine if response represents an error (4xx or 5xx).
     */
    public function failed(): bool
    {
        return $this->clientError() || $this->serverError();
    }

    /**
     * Throw an exception if the response failed.
     *
     * @throws Exception
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw new Exception("HTTP request failed with status {$this->status}: {$this->body}", $this->status);
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->body;
    }
}
