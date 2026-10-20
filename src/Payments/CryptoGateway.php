<?php

namespace Ace\Payments;

use Exception;

/**
 * CryptoGateway — Unified Cryptocurrency Gateway (NOWPayments / Coinbase Commerce / Web3 Invoices)
 *
 * Implements PaymentGatewayInterface for crypto payments, generating hosted
 * payment invoices, QR codes, wallet addresses, and verifying IPN (Instant Payment Notification)
 * HMAC-SHA512 signatures with idempotency protections.
 */
class CryptoGateway implements PaymentGatewayInterface
{
    protected string $provider;
    protected string $apiKey;
    protected string $ipnSecret;
    protected string $defaultCoin;
    protected string $fiatCurrency;
    protected string $baseUrl;
    protected bool $sandbox;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (config('payment.gateways.crypto') ?? []);

        $this->provider     = strtolower($cfg['provider'] ?? (setting('crypto_provider') ?: ($_ENV['CRYPTO_PROVIDER'] ?? 'nowpayments')));
        $this->apiKey       = $cfg['api_key'] ?? (setting('crypto_api_key') ?: ($_ENV['CRYPTO_API_KEY'] ?? ''));
        $this->ipnSecret    = $cfg['ipn_secret'] ?? (setting('crypto_ipn_secret') ?: ($_ENV['CRYPTO_IPN_SECRET'] ?? ''));
        $this->defaultCoin  = strtoupper($cfg['currency'] ?? 'USDTTRC20');
        $this->fiatCurrency = strtoupper($cfg['fiat_currency'] ?? 'USD');
        $this->sandbox      = (bool)($cfg['sandbox'] ?? false);

        if ($this->sandbox) {
            $this->baseUrl = 'https://api-sandbox.nowpayments.io/v1';
        } else {
            $this->baseUrl = rtrim($cfg['base_url'] ?? 'https://api.nowpayments.io/v1', '/');
        }
    }

    public function getName(): string
    {
        return 'crypto';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Initialize a Crypto Invoice / Payment Link.
     *
     * @param string $email Customer email address
     * @param float $amount Amount in fiat (e.g. USD) or crypto
     * @param string $callbackUrl Redirect URL after payment completion
     * @param string|null $reference Local unique transaction reference
     * @param array $options Additional options (pay_currency, order_description, ipn_callback_url, etc.)
     * @return array Standardized result ['checkout_url' => string, 'reference' => string, 'invoice_id' => string, 'raw' => array]
     */
    public function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Crypto Gateway is not configured. Missing API key.", 500);
        }

        $reference = $reference ?: 'CRYPTO_' . uniqid() . '_' . time();
        $payCurrency = strtoupper($options['pay_currency'] ?? $this->defaultCoin);
        $priceCurrency = strtoupper($options['price_currency'] ?? $this->fiatCurrency);

        if ($this->provider === 'coinbase') {
            return $this->initializeCoinbase($email, $amount, $callbackUrl, $reference, $options);
        }

        // Default: NOWPayments Invoice API
        $url = $this->baseUrl . '/invoice';

        $fields = [
            'price_amount'      => $amount,
            'price_currency'    => strtolower($priceCurrency),
            'pay_currency'      => strtolower($payCurrency),
            'ipn_callback_url'  => $options['ipn_callback_url'] ?? ($options['webhook_url'] ?? ''),
            'order_id'          => $reference,
            'order_description' => $options['description'] ?? 'Crypto Order Ref: ' . $reference,
            'success_url'       => $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'reference=' . urlencode($reference),
            'cancel_url'        => ($options['cancel_url'] ?? $callbackUrl) . (str_contains($callbackUrl, '?') ? '&' : '?') . 'cancelled=1',
        ];

        $headers = [
            'x-api-key: ' . $this->apiKey,
        ];

        if (!empty($options['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: ' . $options['idempotency_key'];
        }

        $response = $this->makeRequest('POST', $url, $fields, $headers);

        if (!empty($response['invoice_url'])) {
            return [
                'checkout_url' => $response['invoice_url'],
                'reference'    => $reference,
                'invoice_id'   => (string)($response['id'] ?? ''),
                'raw'          => $response,
            ];
        }

        throw new Exception("Crypto Gateway Initialization Failed: " . ($response['message'] ?? 'Unknown crypto API error'), 400);
    }

    /**
     * Initialize Coinbase Commerce Charge
     */
    protected function initializeCoinbase(string $email, float $amount, string $callbackUrl, string $reference, array $options): array
    {
        $url = 'https://api.commerce.coinbase.com/charges';

        $fields = [
            'name'         => $options['description'] ?? ('Payment ' . $reference),
            'description'  => $options['description'] ?? ('Order ' . $reference),
            'pricing_type' => 'fixed_price',
            'local_price'  => [
                'amount'   => number_format($amount, 2, '.', ''),
                'currency' => strtoupper($options['price_currency'] ?? $this->fiatCurrency),
            ],
            'metadata'     => [
                'customer_email' => $email,
                'reference'      => $reference,
            ],
            'redirect_url' => $callbackUrl,
            'cancel_url'   => $options['cancel_url'] ?? $callbackUrl,
        ];

        $headers = [
            'X-CC-Api-Key: ' . $this->apiKey,
            'X-CC-Version: 2018-03-22',
        ];

        $response = $this->makeRequest('POST', $url, $fields, $headers);

        if (!empty($response['data']['hosted_url'])) {
            return [
                'checkout_url' => $response['data']['hosted_url'],
                'reference'    => $reference,
                'invoice_id'   => $response['data']['id'] ?? '',
                'raw'          => $response['data'],
            ];
        }

        throw new Exception("Coinbase Commerce Charge Failed: " . ($response['error']['message'] ?? 'Unknown error'), 400);
    }

    /**
     * Verify a crypto payment by payment/invoice ID or order reference.
     */
    public function verify(string $reference): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Crypto Gateway is not configured.", 500);
        }

        if ($this->provider === 'coinbase') {
            $url = 'https://api.commerce.coinbase.com/charges/' . rawurlencode($reference);
            $headers = ['X-CC-Api-Key: ' . $this->apiKey, 'X-CC-Version: 2018-03-22'];
            $response = $this->makeRequest('GET', $url, [], $headers);

            if (!empty($response['data'])) {
                $data = $response['data'];
                $timeline = $data['timeline'] ?? [];
                $latestStatus = end($timeline)['status'] ?? 'NEW';

                $status = match (strtoupper($latestStatus)) {
                    'COMPLETED', 'RESOLVED' => 'success',
                    'EXPIRED', 'CANCELED'   => 'failed',
                    default                 => 'pending',
                };

                return [
                    'status'    => $status,
                    'amount'    => (float)($data['pricing']['local']['amount'] ?? 0),
                    'currency'  => $data['pricing']['local']['currency'] ?? $this->fiatCurrency,
                    'reference' => $data['metadata']['reference'] ?? $reference,
                    'raw'       => $data,
                ];
            }
        } else {
            // NOWPayments verify payment or payment status
            $url = $this->baseUrl . '/payment/' . rawurlencode($reference);
            $headers = ['x-api-key: ' . $this->apiKey];
            $response = $this->makeRequest('GET', $url, [], $headers);

            if (isset($response['payment_status'])) {
                $pStatus = strtolower($response['payment_status']);
                $status = match ($pStatus) {
                    'finished', 'confirmed' => 'success',
                    'failed', 'expired'    => 'failed',
                    default                => 'pending',
                };

                return [
                    'status'    => $status,
                    'amount'    => (float)($response['price_amount'] ?? ($response['pay_amount'] ?? 0)),
                    'currency'  => strtoupper($response['price_currency'] ?? ($response['pay_currency'] ?? $this->defaultCoin)),
                    'reference' => $response['order_id'] ?? $reference,
                    'raw'       => $response,
                ];
            }
        }

        return [
            'status'    => 'failed',
            'amount'    => 0.0,
            'currency'  => $this->defaultCoin,
            'reference' => $reference,
            'raw'       => $response ?? [],
        ];
    }

    /**
     * Verify Crypto Webhook / IPN Signature.
     *
     * NOWPayments IPN sends an 'x-nowpayments-sig' header which is an HMAC-SHA512
     * of the sorted JSON payload using the IPN secret key.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->ipnSecret) || empty($signature)) {
            return false;
        }

        if ($this->provider === 'coinbase') {
            // Coinbase uses HMAC-SHA256 of raw body
            $expected = hash_hmac('sha256', $payload, $this->ipnSecret);
            return hash_equals($expected, $signature);
        }

        // NOWPayments: payload fields must be sorted alphabetically before hashing
        $data = json_decode($payload, true);
        if (is_array($data)) {
            ksort($data);
            $sortedPayload = json_encode($data, JSON_UNESCAPED_SLASHES);
            $expected = hash_hmac('sha512', $sortedPayload, $this->ipnSecret);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        // Direct raw payload fallback
        $rawExpected = hash_hmac('sha512', $payload, $this->ipnSecret);
        return hash_equals($rawExpected, $signature);
    }

    /**
     * Helper to perform HTTP request via cURL
     */
    protected function makeRequest(string $method, string $url, array $fields = [], array $extraHeaders = []): array
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

        $headers = array_merge([
            "Content-Type: application/json",
            "Accept: application/json",
        ], $extraHeaders);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
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
        return is_array($decoded) ? $decoded : ['error' => 'Invalid crypto gateway response'];
    }
}
