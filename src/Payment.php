<?php

namespace Ace;

/**
 * Backward compatibility alias for Ace\Payments\PaymentManager
 */
class Payment
{
    public static function manager(): Payments\PaymentManager
    {
        return payment();
    }

    public static function driver(?string $name = null): Payments\PaymentGatewayInterface
    {
        return payment($name);
    }

    public static function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = [], ?string $driver = null): array
    {
        return payment()->initialize($email, $amount, $callbackUrl, $reference, $options, $driver);
    }

    public static function verify(string $reference, ?string $driver = null): array
    {
        return payment()->verify($reference, $driver);
    }

    public static function idempotentInitialize(string $idempotencyKey, string $email, float $amount, string $callbackUrl, array $options = [], ?string $driver = null): array
    {
        return payment()->idempotentInitialize($idempotencyKey, $email, $amount, $callbackUrl, $options, $driver);
    }

    public static function idempotentWebhook(string $eventIdentifier, callable $callback): array
    {
        return payment()->idempotentWebhook($eventIdentifier, $callback);
    }

    public static function verifyWebhook(string $payload, string $signature, ?string $driver = null): bool
    {
        return payment()->verifyWebhook($payload, $signature, $driver);
    }
}
