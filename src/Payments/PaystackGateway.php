<?php

namespace Ace\Payments;

use Exception;

/**
 * PaystackGateway — Full Paystack Integration with Idempotency Support
 *
 * Implements PaymentGatewayInterface for transaction initialization,
 * verification, webhook HMAC-SHA512 verification, recipient creation,
 * and bank payouts.
 */
class PaystackGateway implements PaymentGatewayInterface
{
    protected string $secretKey;
    protected string $publicKey;
    protected string $currency;
    protected string $baseUrl;

    public function __construct(?array $config = null)
    {
        $cfg = $config ?? (config('payment.gateways.paystack') ?? []);
        
        $this->secretKey = $cfg['secret_key'] ?? (setting('paystack_secret_key') ?: ($_ENV['PAYSTACK_SECRET_KEY'] ?? ''));
        $this->publicKey = $cfg['public_key'] ?? (setting('paystack_public_key') ?: ($_ENV['PAYSTACK_PUBLIC_KEY'] ?? ''));
        $this->currency  = $cfg['currency'] ?? 'NGN';
        $this->baseUrl   = rtrim($cfg['base_url'] ?? 'https://api.paystack.co', '/');
    }

    public function getName(): string
    {
        return 'paystack';
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    /**
     * Initialize a Paystack payment transaction.
     *
     * @param string $email Customer email address
     * @param float $amount Amount to charge in Main Currency (e.g., Naira)
     * @param string $callbackUrl URL to redirect after payment
     * @param string|null $reference Unique internal transaction reference
     * @param array $options Additional options (metadata, channels, subaccount, idempotency_key)
     * @return array Standardized result ['checkout_url' => string, 'reference' => string, 'access_code' => string, 'raw' => array]
     */
    public function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Paystack is not configured. Missing secret key.", 500);
        }

        $url = $this->baseUrl . '/transaction/initialize';
        $reference = $reference ?: 'PSTK_' . uniqid() . '_' . time();

        // Paystack amounts are in kobo / minor units for NGN
        $amountInMinor = (int)round($amount * 100);

        $fields = [
            'email'        => $email,
            'amount'       => $amountInMinor,
            'callback_url' => $callbackUrl,
            'reference'    => $reference,
            'currency'     => $options['currency'] ?? $this->currency,
        ];

        if (!empty($options['metadata'])) {
            $fields['metadata'] = $options['metadata'];
        }
        if (!empty($options['channels'])) {
            $fields['channels'] = $options['channels'];
        }
        if (!empty($options['subaccount'])) {
            $fields['subaccount'] = $options['subaccount'];
        }

        $headers = [];
        if (!empty($options['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: ' . $options['idempotency_key'];
        }

        $response = $this->makeRequest('POST', $url, $fields, $headers);

        if (isset($response['status']) && $response['status'] === true && !empty($response['data']['authorization_url'])) {
            return [
                'checkout_url' => $response['data']['authorization_url'],
                'reference'    => $reference,
                'access_code'  => $response['data']['access_code'] ?? null,
                'raw'          => $response['data'],
            ];
        }

        throw new Exception("Paystack Initialization Failed: " . ($response['message'] ?? 'Unknown gateway error'), 400);
    }

    /**
     * Verify a transaction with Paystack.
     *
     * @param string $reference Transaction reference
     * @return array Standardized result ['status' => 'success'|'failed'|'pending', 'amount' => float, 'currency' => string, 'reference' => string, 'raw' => array]
     */
    public function verify(string $reference): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("Paystack is not configured.", 500);
        }

        $url = $this->baseUrl . '/transaction/verify/' . rawurlencode($reference);
        $response = $this->makeRequest('GET', $url);

        if (isset($response['status']) && $response['status'] === true && isset($response['data'])) {
            $data = $response['data'];
            $gatewayStatus = strtolower($data['status'] ?? 'abandoned');

            $status = match ($gatewayStatus) {
                'success' => 'success',
                'failed'  => 'failed',
                default   => 'pending',
            };

            return [
                'status'    => $status,
                'amount'    => ($data['amount'] ?? 0) / 100, // minor to major unit
                'currency'  => $data['currency'] ?? $this->currency,
                'reference' => $data['reference'] ?? $reference,
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
     * Verify Paystack Webhook Signature (HMAC-SHA512).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->secretKey) || empty($signature)) {
            return false;
        }

        $computed = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($computed, $signature);
    }

    /**
     * Create a transfer recipient for payouts.
     */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode, string $currency = 'NGN'): array
    {
        $url = $this->baseUrl . "/transferrecipient";
        $fields = [
            'type'           => 'nuban',
            'name'           => $name,
            'account_number' => $accountNumber,
            'bank_code'      => $bankCode,
            'currency'       => $currency,
        ];

        $response = $this->makeRequest('POST', $url, $fields);
        if (isset($response['status']) && $response['status'] === true) {
            return $response['data'];
        }

        throw new Exception("Paystack Transfer Recipient Creation Failed: " . ($response['message'] ?? 'Unknown Error'), 400);
    }

    /**
     * Initiate a direct bank transfer payout.
     */
    public function initiateTransfer(float $amount, string $recipientCode, string $reason = 'Payout', ?string $reference = null, array $options = []): array
    {
        $url = $this->baseUrl . "/transfer";
        $amountInKobo = (int)round($amount * 100);
        $reference = $reference ?: 'TRF_' . uniqid() . '_' . time();

        $fields = [
            'source'    => 'balance',
            'amount'    => $amountInKobo,
            'recipient' => $recipientCode,
            'reason'    => $reason,
            'reference' => $reference,
        ];

        $headers = [];
        if (!empty($options['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: ' . $options['idempotency_key'];
        }

        $response = $this->makeRequest('POST', $url, $fields, $headers);
        if (isset($response['status']) && $response['status'] === true) {
            return $response['data'];
        }

        throw new Exception("Paystack Transfer Failed: " . ($response['message'] ?? 'Unknown Error'), 400);
    }

    /**
     * Execute cURL request.
     */
    protected function makeRequest(string $method, string $url, array $fields = [], array $extraHeaders = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        // Windows/XAMPP SSL compatibility
        $verifySsl = (($_ENV['APP_ENV'] ?? 'development') === 'production');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

        $headers = array_merge([
            "Authorization: Bearer " . trim($this->secretKey),
            "Cache-Control: no-cache",
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
        return is_array($decoded) ? $decoded : ['status' => false, 'message' => 'Invalid gateway response format'];
    }
}
