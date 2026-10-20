<?php

namespace Ace\Payments;

use Ace\Application;
use Exception;

/**
 * PaymentManager — Gateway Factory, Facade, and Idempotent Payment Service
 *
 * Provides:
 * - Driver resolution (`Payment::driver('paystack')`, `Payment::driver('stripe')`, etc.)
 * - Unified payment initialization & verification
 * - Native idempotency enforcement for billing operations and webhook events
 */
class PaymentManager
{
    protected array $drivers = [];
    protected array $customCreators = [];
    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (config('payment') ?? []);
    }

    /**
     * Get a driver instance by name, or the default driver.
     *
     * @param string|null $driver 'paystack', 'flutterwave', 'stripe', 'crypto'
     * @return PaymentGatewayInterface
     */
    public function driver(?string $driver = null): PaymentGatewayInterface
    {
        $driver = $driver ?: ($this->config['default'] ?? 'paystack');

        if (!isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
        }

        return $this->drivers[$driver];
    }

    /**
     * Create a driver instance.
     */
    protected function createDriver(string $driver): PaymentGatewayInterface
    {
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($this->config);
        }

        return match ($driver) {
            'paystack'    => new PaystackGateway($this->config['gateways']['paystack'] ?? null),
            'flutterwave' => new FlutterwaveGateway($this->config['gateways']['flutterwave'] ?? null),
            'stripe'      => new StripeGateway($this->config['gateways']['stripe'] ?? null),
            'crypto'      => new CryptoGateway($this->config['gateways']['crypto'] ?? null),
            default       => throw new Exception("Unsupported payment driver [{$driver}].", 500),
        };
    }

    /**
     * Register a custom gateway driver closure.
     */
    public function extend(string $driver, \Closure $callback): self
    {
        $this->customCreators[$driver] = $callback;
        unset($this->drivers[$driver]);
        return $this;
    }

    /**
     * Initialize a payment using the specified or default gateway.
     */
    public function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = [], ?string $driver = null): array
    {
        return $this->driver($driver)->initialize($email, $amount, $callbackUrl, $reference, $options);
    }

    /**
     * Verify a transaction using the specified or default gateway.
     */
    public function verify(string $reference, ?string $driver = null): array
    {
        return $this->driver($driver)->verify($reference);
    }

    /**
     * Verify incoming webhook signature.
     */
    public function verifyWebhook(string $payload, string $signature, ?string $driver = null): bool
    {
        return $this->driver($driver)->verifyWebhookSignature($payload, $signature);
    }

    /**
     * Idempotently process a payment charge or initialization.
     *
     * Guarantees that if the client or network retries with the exact same
     * $idempotencyKey, the exact same checkout session/result is returned
     * without creating a secondary transaction or double charge.
     *
     * @param string $idempotencyKey Unique client or request token
     * @param string $email Customer email address
     * @param float $amount Charge amount
     * @param string $callbackUrl Callback URL
     * @param array $options Options passed to gateway
     * @param string|null $driver Gateway driver
     * @return array Gateway initialization result
     */
    public function idempotentInitialize(string $idempotencyKey, string $email, float $amount, string $callbackUrl, array $options = [], ?string $driver = null): array
    {
        $driverName = $driver ?: ($this->config['default'] ?? 'paystack');
        $scope = 'payment_init_' . $driverName;

        // If framework idempotency service is available, check cache
        if (function_exists('idempotency') && class_exists(\Ace\IdempotencyManager::class)) {
            $hash = idempotency()->computeRequestHash('POST', '/payment/initialize', [
                'email' => $email,
                'amount' => $amount,
                'driver' => $driverName,
            ]);

            $check = idempotency()->check($idempotencyKey, $scope, $hash);
            if ($check['status'] === 'completed' && !empty($check['record']['response_body'])) {
                $cached = json_decode($check['record']['response_body'], true);
                if (is_array($cached)) {
                    $cached['_idempotent_replay'] = true;
                    return $cached;
                }
            }
        }

        // Pass the idempotency key down into the driver options (Stripe/Paystack headers)
        $options['idempotency_key'] = $idempotencyKey;
        $result = $this->driver($driverName)->initialize($email, $amount, $callbackUrl, null, $options);

        // Store result in idempotency manager
        if (function_exists('idempotency') && class_exists(\Ace\IdempotencyManager::class)) {
            idempotency()->complete($idempotencyKey, $scope, 200, json_encode($result), ['Content-Type' => 'application/json']);
        }

        return $result;
    }

    /**
     * Idempotently process a payment webhook event.
     *
     * Ensures that payment gateways retrying webhook deliveries do NOT credit
     * balances, issue tickets, or fulfill orders more than once.
     *
     * @param string $eventIdentifier Unique webhook event id or transaction reference
     * @param callable $callback Work to execute only on first delivery: fn()
     * @return array ['processed' => bool, 'replayed' => bool, 'result' => mixed]
     */
    public function idempotentWebhook(string $eventIdentifier, callable $callback): array
    {
        $scope = 'payment_webhook';

        if (function_exists('idempotency') && class_exists(\Ace\IdempotencyManager::class)) {
            $hash = idempotency()->computeRequestHash('POST', '/payment/webhook', ['event_id' => $eventIdentifier]);
            $check = idempotency()->check($eventIdentifier, $scope, $hash);

            if ($check['status'] === 'completed' && !empty($check['record']['response_body'])) {
                return [
                    'processed' => false,
                    'replayed'  => true,
                    'result'    => json_decode($check['record']['response_body'], true) ?? $check['record']['response_body'],
                ];
            }
        }

        $result = $callback();

        if (function_exists('idempotency') && class_exists(\Ace\IdempotencyManager::class)) {
            idempotency()->complete($eventIdentifier, $scope, 200, json_encode($result), ['Content-Type' => 'application/json']);
        }

        return [
            'processed' => true,
            'replayed'  => false,
            'result'    => $result,
        ];
    }

    /**
     * Proxy dynamic calls to the default gateway driver.
     */
    public function __call(string $method, array $parameters)
    {
        return $this->driver()->$method(...$parameters);
    }
}
