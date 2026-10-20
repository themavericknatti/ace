<?php

namespace Ace;

use Exception;

/**
 * Http — Fluent cURL HTTP Client for Ace Framework.
 *
 * Lightweight, zero external dependencies, provides expressive syntax for REST API consumption.
 *
 * Examples:
 *   $res = Http::get('https://api.example.com/users');
 *   $res = Http::withToken('xyz')->post('https://api.example.com/posts', ['title' => 'Hello']);
 *   $res = Http::asForm()->timeout(5)->post('https://auth.example.com/oauth', ['client_id' => 'abc']);
 */
class Http
{
    protected array $headers = [];
    protected array $options = [];
    protected string $bodyFormat = 'json'; // 'json', 'form', 'raw'
    protected mixed $rawBody = null;
    protected int $timeout = 30;
    protected int $connectTimeout = 10;
    protected int $retries = 1;
    protected int $retryDelayMs = 100;

    public function __construct()
    {
        $version = defined('Ace\Application::VERSION') ? Application::VERSION : '1.0';
        $this->headers['User-Agent'] = "Ace-Http-Client/{$version}";
    }

    /**
     * Create a new fluent request instance.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Add multiple headers to the request.
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Add a single header to the request.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Attach a Bearer or custom authorization token.
     */
    public function withToken(string $token, string $type = 'Bearer'): self
    {
        $this->headers['Authorization'] = trim("{$type} {$token}");
        return $this;
    }

    /**
     * Attach Basic HTTP authentication credentials.
     */
    public function withBasicAuth(string $username, string $password): self
    {
        $this->headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
        return $this;
    }

    /**
     * Set maximum total execution timeout in seconds.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);
        return $this;
    }

    /**
     * Set maximum connection timeout in seconds.
     */
    public function connectTimeout(int $seconds): self
    {
        $this->connectTimeout = max(1, $seconds);
        return $this;
    }

    /**
     * Set retry attempts on connection or network failure.
     */
    public function retry(int $times, int $sleepMs = 100): self
    {
        $this->retries = max(1, $times);
        $this->retryDelayMs = max(0, $sleepMs);
        return $this;
    }

    /**
     * Specify JSON request and accept headers.
     */
    public function asJson(): self
    {
        $this->bodyFormat = 'json';
        $this->headers['Content-Type'] = 'application/json';
        $this->headers['Accept'] = 'application/json';
        return $this;
    }

    /**
     * Indicate that the client expects a JSON response.
     */
    public function acceptJson(): self
    {
        $this->headers['Accept'] = 'application/json';
        return $this;
    }

    /**
     * Specify URL-encoded form data (application/x-www-form-urlencoded).
     */
    public function asForm(): self
    {
        $this->bodyFormat = 'form';
        $this->headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return $this;
    }

    /**
     * Set raw request body contents.
     */
    public function withBody(string $body, string $contentType = 'text/plain'): self
    {
        $this->bodyFormat = 'raw';
        $this->rawBody = $body;
        $this->headers['Content-Type'] = $contentType;
        return $this;
    }

    /**
     * Pass custom cURL options (e.g. CURLOPT_SSL_VERIFYPEER => false).
     */
    public function withOptions(array $options): self
    {
        $this->options = array_replace($this->options, $options);
        return $this;
    }

    /**
     * Execute a GET request.
     */
    public function get(string $url, array $query = []): HttpResponse
    {
        return $this->send('GET', $url, ['query' => $query]);
    }

    /**
     * Execute a POST request.
     */
    public function post(string $url, array|string $data = []): HttpResponse
    {
        return $this->send('POST', $url, ['data' => $data]);
    }

    /**
     * Execute a PUT request.
     */
    public function put(string $url, array|string $data = []): HttpResponse
    {
        return $this->send('PUT', $url, ['data' => $data]);
    }

    /**
     * Execute a PATCH request.
     */
    public function patch(string $url, array|string $data = []): HttpResponse
    {
        return $this->send('PATCH', $url, ['data' => $data]);
    }

    /**
     * Execute a DELETE request.
     */
    public function delete(string $url, array|string $data = []): HttpResponse
    {
        return $this->send('DELETE', $url, ['data' => $data]);
    }

    /**
     * Execute a HEAD request.
     */
    public function head(string $url, array $query = []): HttpResponse
    {
        return $this->send('HEAD', $url, ['query' => $query]);
    }

    /**
     * Static shortcut method forwarding.
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        return (new self())->$method(...$args);
    }

    /**
     * Send HTTP request with retry logic.
     */
    public function send(string $method, string $url, array $options = []): HttpResponse
    {
        $method = strtoupper(trim($method));

        if (!empty($options['query'])) {
            $queryString = http_build_query($options['query']);
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }

        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->retries) {
            $attempts++;
            try {
                return $this->executeCurl($method, $url, $options);
            } catch (Exception $e) {
                $lastException = $e;
                if ($attempts < $this->retries && $this->retryDelayMs > 0) {
                    usleep($this->retryDelayMs * 1000);
                }
            }
        }

        throw $lastException ?? new Exception("HTTP request failed after {$this->retries} attempts.");
    }

    /**
     * Low-level cURL execution wrapper.
     */
    protected function executeCurl(string $method, string $url, array $options): HttpResponse
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);

        // Body payload
        $data = $options['data'] ?? null;
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            if ($this->bodyFormat === 'json') {
                $payload = is_string($data) ? $data : json_encode($data);
                if (!isset($this->headers['Content-Type'])) {
                    $this->headers['Content-Type'] = 'application/json';
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } elseif ($this->bodyFormat === 'form') {
                $payload = is_array($data) ? http_build_query($data) : (string)$data;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            } elseif ($this->bodyFormat === 'raw') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->rawBody ?? (string)$data);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : (string)$data);
            }
        }

        // Headers
        $formattedHeaders = [];
        foreach ($this->headers as $k => $v) {
            $formattedHeaders[] = "{$k}: {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);

        // Custom options
        foreach ($this->options as $opt => $val) {
            curl_setopt($ch, $opt, $val);
        }

        $rawResponse = curl_exec($ch);

        if ($rawResponse === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new Exception("cURL error ({$errno}): {$error}", $errno);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $body = substr($rawResponse, $headerSize);

        $parsedHeaders = $this->parseHeaders($rawHeaders);

        return new HttpResponse($body, $statusCode, $parsedHeaders);
    }

    /**
     * Parse raw HTTP header string into associative array.
     */
    protected function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = explode("\r\n", trim($rawHeaders));
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (isset($headers[$key])) {
                    if (!is_array($headers[$key])) {
                        $headers[$key] = [$headers[$key]];
                    }
                    $headers[$key][] = $value;
                } else {
                    $headers[$key] = $value;
                }
            }
        }
        return $headers;
    }
}
