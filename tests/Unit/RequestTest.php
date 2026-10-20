<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Request;

class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    public function test_method_detection_and_spoofing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = 'PUT';

        $request = new Request();
        $this->assertEquals('put', $request->method());
        $this->assertTrue($request->isPut());
        $this->assertFalse($request->isPost());

        $_POST['_method'] = 'DELETE';
        $this->assertEquals('delete', $request->method());
        $this->assertTrue($request->isDelete());
    }

    public function test_only_returns_subset_of_inputs(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'name' => 'John',
            'email' => 'john@example.com',
            'role' => 'admin',
        ];

        $request = new Request();
        $data = $request->only('name', 'email');

        $this->assertEquals([
            'name' => 'John',
            'email' => 'john@example.com'
        ], $data);
        $this->assertArrayNotHasKey('role', $data);
    }

    public function test_except_filters_out_keys(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'title' => 'My Post',
            'content' => 'Lorem ipsum',
            '_token' => 'csrf-secret-token',
            '_method' => 'PATCH',
        ];

        $request = new Request();
        $filtered = $request->except('_token', '_method');

        $this->assertEquals([
            'title' => 'My Post',
            'content' => 'Lorem ipsum'
        ], $filtered);
    }

    public function test_bearer_token_parsing(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';

        $request = new Request();
        $this->assertEquals('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $request->bearerToken());
    }

    public function test_header_reading(): void
    {
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom-value';

        $request = new Request();
        $this->assertEquals('custom-value', $request->header('X-Custom-Header'));
        $this->assertEquals('default', $request->header('Non-Existent', 'default'));
    }

    public function test_ip_address_detection(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.195';

        $request = new Request();
        $this->assertEquals('203.0.113.195', $request->ip());
    }

    public function test_xss_auto_sanitization(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'title' => 'Normal title',
            'bio' => '<script>alert(1)</script>Safe bio',
        ];

        $request = new Request();
        $body = $request->getBody();

        $this->assertEquals('Safe bio', $body['bio']);
        $this->assertEquals('<script>alert(1)</script>Safe bio', $request->getRaw('bio'));
    }
}
