<?php

namespace Ace\Payments;

use Exception;

/**
 * StripeGateway — Full Stripe Integration with Direct Native Idempotency Keys
 *
 * Implements PaymentGatewayInterface using Stripe Checkout Sessions and
 * Stripe native Idempotency-Key HTTP headers for 100% duplicate charge prevention.
 */
class StripeGateway implements PaymentGatewayInterface
{
    protected string $secretKey;
    protected string $publicKey;
    protected string $webhookSecret;
    protected string $currency;
    protected string $baseUrl;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (config('payment.gateways.stripe') ?? []);

        $this->secretKey     = $cfg['secret_key'] ?? (setting('stripe_secret_key') ?: ($_ENV['STRIPE_SECRET_KEY'] ?? ''));
        $this->publicKey     = $cfg['public_key'] ?? (setting('stripe_public_key') ?: ($_ENV['STRIPE_PUBLIC_KEY'] ?? ''));
        $this->webhookSecret = $cfg['webhook_secret'] ?? (setting('stripe_webhook_secret') ?: ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? ''));
        $this->currency      = strtolower($cfg['currency'] ?? 'usd');
        $this->baseUrl       = rtrim($cfg['base_url'] ?? 'https://api.stripe.com/v1', '/');
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    /**
     * Initialize a Stripe Checkout Session.
     *
     * @param string $email Customer email address
     * @param float $amount Amount in major currency units (e.g. 10.50 USD)
     * @param string $callbackUrl Success redirect URL
     * @param string|null $reference Local unique transaction reference
     * @param array $options Additional options (cancel_url, item_name, idempotency_key, metadata, etc.)
     * @return array Standardized result ['checkout_url' => string, 'reference' => string, 'session_id' => string, 'raw' => array]
     */
    public function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Stripe is not configured. Missing secret key.", 500);
        }

        $url = $this->baseUrl . "/checkout/sessions";
        $reference = $reference ?: 'STRIPE_' . uniqid() . '_' . time();
        $amountInCents = (int)round($amount * 100);

        $cancelUrl = $options['cancel_url'] ?? $callbackUrl;
        $itemName  = $options['item_name'] ?? 'Account Payment (Ref: ' . $reference . ')';
        $currency  = strtolower($options['currency'] ?? $this->currency);

        // Parameters in URL-encoded form data format required by Stripe API
        $fields = [
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency'     => $currency,
                        'product_data' => [
                            'name' => $itemName,
                        ],
                        'unit_amount'  => $amountInCents,
                    ],
                    'quantity' => 1,
                ]
            ],
            'mode'                => 'payment',
            'success_url'         => $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}&reference=' . urlencode($reference),
            'cancel_url'          => $cancelUrl . (str_contains($cancelUrl, '?') ? '&' : '?') . 'reference=' . urlencode($reference) . '&cancelled=1',
            'customer_email'      => $email,
            'client_reference_id' => $reference,
        ];

        if (!empty($options['metadata'])) {
            $fields['metadata'] = $options['metadata'];
        }

        $headers = [];
        // Stripe natively respects the Idempotency-Key header on mutation requests
        if (!empty($options['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: ' . $options['idempotency_key'];
        }

        $postQuery = http_build_query($fields);
        $response = $this->makeRequest('POST', $url, $postQuery, $headers);

        if (isset($response['id']) && !empty($response['url'])) {
            return [
                'checkout_url' => $response['url'],
                'reference'    => $reference,
                'session_id'   => $response['id'],
                'raw'          => $response,
            ];
        }

        throw new Exception("Stripe Checkout Initialization Failed: " . ($response['error']['message'] ?? 'Unknown Error'), 400);
    }

    /**
     * Verify a Stripe Checkout Session or Payment Intent status.
     */
    public function verify(string $reference): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Stripe is not configured.", 500);
        }

        // Check if reference is a checkout session (cs_...) or payment intent (pi_...)
        if (str_starts_with($reference, 'cs_')) {
            $url = $this->baseUrl . "/checkout/sessions/" . rawurlencode($reference);
        } elseif (str_starts_with($reference, 'pi_')) {
            $url = $this->baseUrl . "/payment_intents/" . rawurlencode($reference);
        } else {
            // Find session by client_reference_id or search if available, fallback to direct session id
            $url = $this->baseUrl . "/checkout/sessions/" . rawurlencode($reference);
        }

        $response = $this->makeRequest('GET', $url);

        if (isset($response['id'])) {
            $paymentStatus = $response['payment_status'] ?? ($response['status'] ?? '');
            $status = match (strtolower($paymentStatus)) {
                'paid', 'succeeded' => 'success',
                'failed', 'canceled' => 'failed',
                default              => 'pending',
            };

            $amountTotal = ($response['amount_total'] ?? ($response['amount'] ?? 0)) / 100;
            $currency    = $response['currency'] ?? $this->currency;

            return [
                'status'    => $status,
                'amount'    => (float)$amountTotal,
                'currency'  => strtoupper($currency),
                'reference' => $response['client_reference_id'] ?? $response['id'],
                'raw'       => $response,
            ];
        }

        return [
            'status'    => 'failed',
            'amount'    => 0.0,
            'currency'  => strtoupper($this->currency),
            'reference' => $reference,
            'raw'       => $response,
        ];
    }

    /**
     * Verify Stripe Webhook Signature (Stripe-Signature header).
     *
     * Format: t=timestamp,v1=signature
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret) || empty($signature)) {
            return false;
        }

        // Parse signature header items
        $items = explode(',', $signature);
        $timestamp = null;
        $signatures = [];

        foreach ($items as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) === 2) {
                if ($parts[0] === 't') {
                    $timestamp = $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
        }

        if (!$timestamp || empty($signatures)) {
            return false;
        }

        // Prevent replay attacks (tolerance of 5 minutes / 300s)
        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper to perform HTTP request via cURL with Basic Auth
     */
    protected function makeRequest(string $method, string $url, ?string $postFields = null, array $extraHeaders = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        $verifySsl = (($_ENV['APP_ENV'] ?? 'development') === 'production');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

        // Basic Auth: Stripe Secret Key as username with blank password
        curl_setopt($ch, CURLOPT_USERPWD, trim($this->secretKey) . ":");

        $headers = array_merge([
            "Accept: application/json",
        ], $extraHeaders);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields ?? '');
            $headers[] = "Content-Type: application/x-www-form-urlencoded";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL Error: " . $err, 500);
        }

        curl_close($ch);
        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : ['error' => ['message' => 'Invalid Stripe gateway response']];
    }
}
