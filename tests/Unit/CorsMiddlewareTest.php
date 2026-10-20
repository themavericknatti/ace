<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Middlewares\CorsMiddleware;

class TestableCorsMiddleware extends CorsMiddleware
{
    public function testResolveOrigin(string $origin, array $allowedOrigins): string
    {
        return $this->resolveAllowedOrigin($origin, $allowedOrigins);
    }
}

class CorsMiddlewareTest extends TestCase
{
    private TestableCorsMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new TestableCorsMiddleware();
    }

    public function test_wildcard_origin_allows_any_origin(): void
    {
        $result = $this->middleware->testResolveOrigin('https://example.com', ['*']);
        $this->assertEquals('*', $result);

        $result = $this->middleware->testResolveOrigin('http://localhost:3000', ['*']);
        $this->assertEquals('*', $result);
    }

    public function test_exact_match_origin_allowed(): void
    {
        $allowed = ['http://localhost:3000', 'https://myapp.com'];

        $result = $this->middleware->testResolveOrigin('http://localhost:3000', $allowed);
        $this->assertEquals('http://localhost:3000', $result);

        $result = $this->middleware->testResolveOrigin('https://myapp.com', $allowed);
        $this->assertEquals('https://myapp.com', $result);
    }

    public function test_unauthorized_origin_rejected(): void
    {
        $allowed = ['http://localhost:3000', 'https://myapp.com'];

        $result = $this->middleware->testResolveOrigin('http://malicious-site.com', $allowed);
        $this->assertEquals('', $result);
    }

    public function test_wildcard_subdomain_allowed(): void
    {
        $allowed = ['*.example.com'];

        $result = $this->middleware->testResolveOrigin('https://api.example.com', $allowed);
        $this->assertEquals('https://api.example.com', $result);

        $result = $this->middleware->testResolveOrigin('https://app.example.com', $allowed);
        $this->assertEquals('https://app.example.com', $result);

        $result = $this->middleware->testResolveOrigin('https://otherdomain.com', $allowed);
        $this->assertEquals('', $result);
    }
}
