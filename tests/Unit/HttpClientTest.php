<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Http;
use Ace\HttpResponse;
use Exception;

class HttpClientTest extends TestCase
{
    public function test_http_response_status_and_helpers(): void
    {
        $response200 = new HttpResponse('{"status":"ok","data":{"id":123,"user":{"name":"Alice"}}}', 200, ['Content-Type' => 'application/json', 'X-Custom-Header' => 'TestValue']);

        $this->assertEquals(200, $response200->status());
        $this->assertTrue($response200->ok());
        $this->assertTrue($response200->successful());
        $this->assertFalse($response200->failed());
        $this->assertFalse($response200->clientError());
        $this->assertFalse($response200->serverError());
        $this->assertEquals('TestValue', $response200->header('X-Custom-Header'));
        $this->assertEquals('TestValue', $response200->header('x-custom-header')); // case-insensitive
        $this->assertNull($response200->header('non-existent'));

        // Test JSON dot-notation parsing
        $this->assertEquals('ok', $response200->json('status'));
        $this->assertEquals(123, $response200->json('data.id'));
        $this->assertEquals('Alice', $response200->json('data.user.name'));
        $this->assertEquals('fallback', $response200->json('data.non_existent', 'fallback'));
        $this->assertIsArray($response200->json());
    }

    public function test_http_response_error_statuses_and_throw(): void
    {
        $response404 = new HttpResponse('{"error":"Not Found"}', 404);
        $this->assertTrue($response404->clientError());
        $this->assertTrue($response404->failed());
        $this->assertFalse($response404->successful());

        $response500 = new HttpResponse('Internal Server Error', 500);
        $this->assertTrue($response500->serverError());
        $this->assertTrue($response500->failed());

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);
        $response404->throw();
    }

    public function test_http_response_created_status(): void
    {
        $response201 = new HttpResponse('{"created":true}', 201);
        $this->assertTrue($response201->created());
        $this->assertTrue($response201->successful());
    }

    public function test_http_builder_configuration_methods(): void
    {
        $client = Http::new()
            ->withHeaders(['X-Api-Key' => 'secret-123'])
            ->withHeader('X-Env', 'testing')
            ->withToken('jwt-token-xyz')
            ->timeout(15)
            ->connectTimeout(5)
            ->asJson()
            ->retry(3, 50);

        $ref = new \ReflectionClass($client);

        $headersProp = $ref->getProperty('headers');
        $headersProp->setAccessible(true);
        $headers = $headersProp->getValue($client);

        $this->assertEquals('secret-123', $headers['X-Api-Key']);
        $this->assertEquals('testing', $headers['X-Env']);
        $this->assertEquals('Bearer jwt-token-xyz', $headers['Authorization']);
        $this->assertEquals('application/json', $headers['Content-Type']);
        $this->assertEquals('application/json', $headers['Accept']);

        $timeoutProp = $ref->getProperty('timeout');
        $timeoutProp->setAccessible(true);
        $this->assertEquals(15, $timeoutProp->getValue($client));

        $retriesProp = $ref->getProperty('retries');
        $retriesProp->setAccessible(true);
        $this->assertEquals(3, $retriesProp->getValue($client));
    }

    public function test_http_builder_basic_auth_and_form(): void
    {
        $client = Http::new()
            ->withBasicAuth('myuser', 'mypassword')
            ->asForm();

        $ref = new \ReflectionClass($client);
        $headersProp = $ref->getProperty('headers');
        $headersProp->setAccessible(true);
        $headers = $headersProp->getValue($client);

        $this->assertEquals('Basic ' . base64_encode('myuser:mypassword'), $headers['Authorization']);
        $this->assertEquals('application/x-www-form-urlencoded', $headers['Content-Type']);
    }

    public function test_global_http_helper(): void
    {
        $this->assertTrue(function_exists('http'));
        $instance = http();
        $this->assertInstanceOf(Http::class, $instance);
    }
}
