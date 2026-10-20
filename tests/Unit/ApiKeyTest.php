<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\ApiKey;
use Ace\Request;

class ApiKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET = [];
    }

    public function test_wildcard_permission_grants_access(): void
    {
        $keyRecord = [
            'name' => 'Full Access App',
            'permissions' => ['*'],
        ];

        $this->assertTrue(ApiKey::hasPermission($keyRecord, 'users:read'));
        $this->assertTrue(ApiKey::hasPermission($keyRecord, 'orders:delete'));
        $this->assertTrue(ApiKey::hasPermission($keyRecord, 'anything'));
    }

    public function test_specific_permission_checking(): void
    {
        $keyRecord = [
            'name' => 'Frontend Read Only',
            'permissions' => ['users:read', 'products:read'],
        ];

        $this->assertTrue(ApiKey::hasPermission($keyRecord, 'users:read'));
        $this->assertTrue(ApiKey::hasPermission($keyRecord, 'products:read'));
        $this->assertFalse(ApiKey::hasPermission($keyRecord, 'users:delete'));
        $this->assertFalse(ApiKey::hasPermission($keyRecord, 'orders:create'));
    }

    public function test_request_api_key_methods(): void
    {
        $request = new Request();
        $this->assertNull($request->apiKey());
        $this->assertNull($request->apiKeyName());
        $this->assertFalse($request->hasPermission('users:read'));

        $keyRecord = [
            'id' => 1,
            'name' => 'Next.js App',
            'prefix' => 'a1b2c3d4',
            'permissions' => ['users:read', 'events:manage'],
        ];

        $request->setApiKey($keyRecord);

        $this->assertEquals($keyRecord, $request->apiKey());
        $this->assertEquals('Next.js App', $request->apiKeyName());
        $this->assertTrue($request->hasPermission('users:read'));
        $this->assertTrue($request->hasPermission('events:manage'));
        $this->assertFalse($request->hasPermission('users:delete'));
    }

    public function test_request_extracts_bearer_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 9f834abcde1234567890';
        $request = new Request();
        $this->assertEquals('9f834abcde1234567890', $request->bearerToken());
    }

    public function test_request_extracts_x_api_key_header(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = 'ace_key_super_secret_123';
        $request = new Request();
        $this->assertEquals('ace_key_super_secret_123', $request->header('X-Api-Key'));
    }
}
