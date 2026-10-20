<?php

namespace Ace\Payments;

/**
 * PaymentGatewayInterface — Unified Interface for All Payment Adapters
 *
 * All payment drivers (Paystack, Flutterwave, Stripe, Crypto) implement
 * this contract, ensuring consistent initialization, verification, and webhooks
 * across the entire application.
 */
interface PaymentGatewayInterface
{
    /**
     * Get the identifier name of this payment gateway (e.g. 'paystack', 'stripe', 'crypto').
     */
    public function getName(): string;

    /**
     * Check if the gateway has the required API credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Initialize a checkout session or payment charge.
     *
     * @param string $email Customer email address
     * @param float $amount Amount to charge
     * @param string $callbackUrl URL to redirect to upon payment completion
     * @param string|null $reference Unique internal transaction reference
     * @param array $options Additional gateway-specific options (currency, metadata, etc.)
     * @return array Standardized result: ['checkout_url' => string, 'reference' => string, 'raw' => array]
     */
    public function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = []): array;

    /**
     * Verify a completed transaction by its reference or transaction ID.
     *
     * @param string $reference Transaction reference or gateway ID
     * @return array Standardized result: ['status' => 'success'|'failed'|'pending', 'amount' => float, 'currency' => string, 'reference' => string, 'raw' => array]
     */
    public function verify(string $reference): array;

    /**
     * Verify an incoming webhook signature.
     *
     * @param string $payload Raw HTTP request payload (php://input)
     * @param string $signature Signature from request headers
     * @return bool
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
