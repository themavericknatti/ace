<?php

namespace Ace;

class Hash
{
    /**
     * Default algorithm to use for hashing (PASSWORD_DEFAULT or PASSWORD_BCRYPT).
     */
    protected static string|int $algo = PASSWORD_BCRYPT;

    /**
     * Default cost factor for Bcrypt.
     */
    protected static int $cost = 12;

    /**
     * Hash the given value.
     *
     *   $hash = Hash::make('secret-password');
     *   $hash = Hash::make('secret-password', ['cost' => 14]);
     */
    public static function make(string $value, array $options = []): string
    {
        $cost = $options['cost'] ?? self::$cost;
        $hash = password_hash($value, self::$algo, ['cost' => $cost]);

        if ($hash === false) {
            throw new \RuntimeException('Bcrypt hashing not supported.');
        }

        return $hash;
    }

    /**
     * Check the given plain value against a hash.
     *
     *   if (Hash::verify('secret-password', $user->password)) { ... }
     */
    public static function verify(string $value, string $hashedValue): bool
    {
        if (strlen($hashedValue) === 0) {
            return false;
        }

        return password_verify($value, $hashedValue);
    }

    /**
     * Alias for verify() to match Laravel convention.
     */
    public static function check(string $value, string $hashedValue): bool
    {
        return self::verify($value, $hashedValue);
    }

    /**
     * Check if the given hash has been hashed using the given options.
     */
    public static function needsRehash(string $hashedValue, array $options = []): bool
    {
        $cost = $options['cost'] ?? self::$cost;
        return password_needs_rehash($hashedValue, self::$algo, ['cost' => $cost]);
    }

    /**
     * Generate a cryptographically secure random token string.
     */
    public static function random(int $length = 32): string
    {
        return bin2hex(random_bytes((int)ceil($length / 2)));
    }
}
