<?php

namespace Ace\Payments;

use Exception;

/**
 * FlutterwaveGateway — Full Flutterwave v3 Integration with Idempotency Support
 *
 * Implements PaymentGatewayInterface for standard charges, verification,
 * secret hash webhook verification, and bank transfers.
 */
class FlutterwaveGateway implements PaymentGatewayInterface
{
    protected string $secretKey;
    protected string $publicKey;
    protected string $encKey;
    protected string $currency;
    protected string $baseUrl;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (config('payment.gateways.flutterwave') ?? []);

        $this->secretKey = $cfg['secret_key'] ?? (setting('flw_secret_key') ?: ($_ENV['FLW_SECRET_KEY'] ?? ''));
        $this->publicKey = $cfg['public_key'] ?? (setting('flw_public_key') ?: ($_ENV['FLW_PUBLIC_KEY'] ?? ''));
        $this->encKey    = $cfg['enc_key'] ?? (setting('flw_encryption_key') ?: ($_ENV['FLW_ENCRYPTION_KEY'] ?? ''));
        $this->currency  = $cfg['currency'] ?? 'NGN';
        $this->baseUrl   = rtrim($cfg['base_url'] ?? 'https://api.flutterwave.com/v3', '/');
    }

    public function getName(): string
    {
        return 'flutterwave';
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    /**
     * Initialize a Flutterwave Standard Payment.
     *
     * @param string $email Customer email address
     * @param float $amount Amount to charge
     * @param string $callbackUrl Redirect URL on payment completion
     * @param string|null $reference Unique transaction reference (tx_ref)
     * @param array $options Additional options (customer name/phone, title, currency, etc.)
     * @return array Standardized result ['checkout_url' => string, 'reference' => string, 'raw' => array]
     */
    public function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Flutterwave is not configured. Missing secret key.", 500);
        }

        $url = $this->baseUrl . '/payments';
        $reference = $reference ?: 'FLW_' . uniqid() . '_' . time();

        $fields = [
            'tx_ref'          => $reference,
            'amount'          => $amount,
            'currency'        => $options['currency'] ?? $this->currency,
            'redirect_url'    => $callbackUrl,
            'customer'        => [
                'email'        => $email,
                'name'         => $options['name'] ?? 'Customer',
                'phonenumber'  => $options['phone'] ?? '',
            ],
            'customizations'  => [
                'title'       => $options['title'] ?? ($_ENV['APP_NAME'] ?? 'Ace Payment'),
                'description' => $options['description'] ?? 'Payment for services',
                'logo'        => $options['logo'] ?? '',
            ],
        ];

        if (!empty($options['meta'])) {
            $fields['meta'] = $options['meta'];
        }

        $headers = [];
        if (!empty($options['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: ' . $options['idempotency_key'];
        }

        $response = $this->makeRequest('POST', $url, $fields, $headers);

        if (isset($response['status']) && $response['status'] === 'success' && !empty($response['data']['link'])) {
            return [
                'checkout_url' => $response['data']['link'],
                'reference'    => $reference,
                'raw'          => $response['data'],
            ];
        }

        throw new Exception("Flutterwave Initialization Failed: " . ($response['message'] ?? 'Unknown Error'), 400);
    }

    /**
     * Verify a transaction with Flutterwave by transaction ID or tx_ref.
     */
    public function verify(string $reference): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Flutterwave is not configured.", 500);
        }

        // Flutterwave verify endpoint accepts transaction ID; if a string reference was given, verify by tx_ref
        if (is_numeric($reference)) {
            $url = $this->baseUrl . "/transactions/" . rawurlencode($reference) . "/verify";
        } else {
            $url = $this->baseUrl . "/transactions/verify_by_reference?tx_ref=" . rawurlencode($reference);
        }

        $response = $this->makeRequest('GET', $url);

        if (isset($response['status']) && $response['status'] === 'success' && isset($response['data'])) {
            $data = $response['data'];
            $gatewayStatus = strtolower($data['status'] ?? 'failed');

            $status = match ($gatewayStatus) {
                'successful' => 'success',
                'failed'     => 'failed',
                default      => 'pending',
            };

            return [
                'status'    => $status,
                'amount'    => (float)($data['amount'] ?? 0),
                'currency'  => $data['currency'] ?? $this->currency,
                'reference' => $data['tx_ref'] ?? $reference,
                'raw'       => $data,
            ];
        }

        return [
            'status'    => 'failed',
            'amount'    => 0.0,
            'currency'  => $this->currency,
            'reference' => $reference,
            'raw'       => $response,
        ];
    }

    /**
     * Verify Flutterwave Webhook Secret Hash (verif-hash header).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expectedHash = setting('flw_secret_hash') ?: ($_ENV['FLW_SECRET_HASH'] ?? $this->secretKey);
        if (empty($expectedHash) || empty($signature)) {
            return false;
        }

        return hash_equals($expectedHash, $signature);
    }

    /**
     * Initiate a Direct Bank Transfer / Payout via Flutterwave.
     */
    public function initiateTransfer(float $amount, string $accountNumber, string $bankCode, string $narration = 'Payout', ?string $reference = null, string $currency = 'NGN'): array
    {
        $url = $this->baseUrl . "/transfers";
        $reference = $reference ?: 'FLW_TRF_' . uniqid() . '_' . time();

        $fields = [
            'account_bank'   => $bankCode,
            'account_number' => $accountNumber,
            'amount'         => $amount,
            'narration'      => $narration,
            'currency'       => $currency,
            'reference'      => $reference,
            'debit_currency' => $currency,
        ];

        $response = $this->makeRequest('POST', $url, $fields);
        if (isset($response['status']) && $response['status'] === 'success') {
            return $response['data'];
        }

        throw new Exception("Flutterwave Transfer Failed: " . ($response['message'] ?? 'Unknown Error'), 400);
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
            "Authorization: Bearer " . trim($this->secretKey),
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
        return is_array($decoded) ? $decoded : ['status' => 'error', 'message' => 'Invalid gateway response format'];
    }
}
