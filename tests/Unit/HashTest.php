<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Hash;

class HashTest extends TestCase
{
    public function test_make_and_verify_password(): void
    {
        $password = 'secret-password-123';
        $hash = Hash::make($password);

        $this->assertNotEmpty($hash);
        $this->assertNotEquals($password, $hash);
        $this->assertTrue(Hash::verify($password, $hash));
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertFalse(Hash::verify('wrong-password', $hash));
    }

    public function test_empty_string_fails_verification(): void
    {
        $this->assertFalse(Hash::verify('password', ''));
    }

    public function test_random_token_generation(): void
    {
        $token1 = Hash::random(32);
        $token2 = Hash::random(32);

        $this->assertEquals(32, strlen($token1));
        $this->assertNotEquals($token1, $token2);
    }

    public function test_needs_rehash(): void
    {
        $hash = Hash::make('password', ['cost' => 10]);
        $this->assertTrue(Hash::needsRehash($hash, ['cost' => 14]));
        $this->assertFalse(Hash::needsRehash($hash, ['cost' => 10]));
    }
}
