<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Payments\PaymentGatewayInterface;
use Ace\Payments\PaymentManager;
use Ace\Payments\PaystackGateway;
use Ace\Payments\FlutterwaveGateway;
use Ace\Payments\StripeGateway;
use Ace\Payments\CryptoGateway;
use Ace\Payment;

class PaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure test environment
        $_ENV['PAYSTACK_SECRET_KEY'] = 'sk_test_paystack_12345';
        $_ENV['FLW_SECRET_KEY'] = 'flw_sec_test_12345';
        $_ENV['FLW_SECRET_HASH'] = 'flw_hash_secret_test';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_stripe_12345';
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test_stripe_secret';
        $_ENV['CRYPTO_API_KEY'] = 'crypto_test_api_key';
        $_ENV['CRYPTO_IPN_SECRET'] = 'crypto_ipn_secret_test';

        // Setup in-memory SQLite database for Idempotency testing
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS idempotency_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                idempotency_key TEXT NOT NULL,
                scope TEXT NOT NULL DEFAULT 'global',
                request_hash TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                response_code INTEGER NULL,
                response_body TEXT NULL,
                response_headers TEXT NULL,
                locked_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE (scope, idempotency_key)
            );
        ");

        if (class_exists(\Ace\Application::class)) {
            if (!isset(\Ace\Application::$app)) {
                $rootDir = dirname(__DIR__, 2);
                new \Ace\Application($rootDir, ['db' => ['database' => 'test']]);
            }

            $db = $this->createMock(\Ace\Database::class);
            $db->pdo = $pdo;
            $db->method('prepare')->willReturnCallback(function ($sql) use ($pdo) {
                $cleanSql = str_replace(
                    ['`', 'TIMESTAMP', 'LONGTEXT', 'ENUM(\'pending\', \'completed\')'],
                    ['', 'DATETIME', 'TEXT', 'TEXT'],
                    $sql
                );
                return $pdo->prepare($cleanSql);
            });

            \Ace\Application::$app->db = $db;
        }
    }

    public function testGatewayInstantiationAndContract(): void
    {
        $paystack = new PaystackGateway(['secret_key' => 'sk_test_paystack_12345']);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $paystack);
        $this->assertEquals('paystack', $paystack->getName());
        $this->assertTrue($paystack->isConfigured());

        $flw = new FlutterwaveGateway(['secret_key' => 'flw_sec_test_12345']);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $flw);
        $this->assertEquals('flutterwave', $flw->getName());
        $this->assertTrue($flw->isConfigured());

        $stripe = new StripeGateway(['secret_key' => 'sk_test_stripe_12345']);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $stripe);
        $this->assertEquals('stripe', $stripe->getName());
        $this->assertTrue($stripe->isConfigured());

        $crypto = new CryptoGateway(['api_key' => 'crypto_test_api_key']);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $crypto);
        $this->assertEquals('crypto', $crypto->getName());
        $this->assertTrue($crypto->isConfigured());

        // Also verify unconfigured gateways report false
        $empty = new PaystackGateway(['secret_key' => '']);
        $this->assertFalse($empty->isConfigured());
    }

    public function testPaymentManagerDriverResolution(): void
    {
        $manager = new PaymentManager([
            'default' => 'paystack',
            'gateways' => [
                'paystack'    => ['secret_key' => 'sk_custom_test'],
                'flutterwave' => ['secret_key' => 'flw_custom_test'],
                'stripe'      => ['secret_key' => 'stripe_custom_test'],
                'crypto'      => ['api_key' => 'crypto_custom_test'],
            ]
        ]);

        $this->assertInstanceOf(PaystackGateway::class, $manager->driver());
        $this->assertInstanceOf(PaystackGateway::class, $manager->driver('paystack'));
        $this->assertInstanceOf(FlutterwaveGateway::class, $manager->driver('flutterwave'));
        $this->assertInstanceOf(StripeGateway::class, $manager->driver('stripe'));
        $this->assertInstanceOf(CryptoGateway::class, $manager->driver('crypto'));
    }

    public function testPaymentHelperFunction(): void
    {
        $this->assertInstanceOf(PaymentManager::class, payment());
        $this->assertInstanceOf(PaystackGateway::class, payment('paystack'));
        $this->assertInstanceOf(StripeGateway::class, payment('stripe'));
    }

    public function testPaymentFacade(): void
    {
        $this->assertInstanceOf(PaymentGatewayInterface::class, Payment::driver('stripe'));
        $this->assertInstanceOf(PaymentGatewayInterface::class, Payment::driver('crypto'));
    }

    public function testWebhookSignatureVerifications(): void
    {
        // Paystack Webhook (HMAC-SHA512)
        $paystack = new PaystackGateway(['secret_key' => 'secret_123']);
        $payload = json_encode(['event' => 'charge.success', 'data' => ['id' => 99]]);
        $validSig = hash_hmac('sha512', $payload, 'secret_123');
        $this->assertTrue($paystack->verifyWebhookSignature($payload, $validSig));
        $this->assertFalse($paystack->verifyWebhookSignature($payload, 'invalid-signature'));

        // Flutterwave Webhook (Secret Hash)
        $flw = new FlutterwaveGateway();
        $this->assertTrue($flw->verifyWebhookSignature($payload, 'flw_hash_secret_test'));
        $this->assertFalse($flw->verifyWebhookSignature($payload, 'wrong-hash'));

        // Stripe Webhook (t=timestamp,v1=signature)
        $stripe = new StripeGateway(['webhook_secret' => 'whsec_test']);
        $ts = time();
        $stripeSig = hash_hmac('sha256', "{$ts}.{$payload}", 'whsec_test');
        $header = "t={$ts},v1={$stripeSig}";
        $this->assertTrue($stripe->verifyWebhookSignature($payload, $header));
        $this->assertFalse($stripe->verifyWebhookSignature($payload, "t={$ts},v1=fake_signature"));

        // Crypto Webhook (HMAC-SHA512)
        $crypto = new CryptoGateway(['ipn_secret' => 'ipn_secret_999']);
        $cryptoPayload = json_encode(['payment_id' => '123', 'payment_status' => 'finished']);
        $cSig = hash_hmac('sha512', $cryptoPayload, 'ipn_secret_999');
        $this->assertTrue($crypto->verifyWebhookSignature($cryptoPayload, $cSig));
    }

    public function testIdempotentWebhookDeduplication(): void
    {
        $manager = new PaymentManager();

        $executionCount = 0;
        $work = function() use (&$executionCount) {
            $executionCount++;
            return ['order_credited' => true, 'count' => $executionCount];
        };

        $eventId = 'evt_test_charge_' . uniqid();

        // First delivery: executed
        $first = $manager->idempotentWebhook($eventId, $work);
        $this->assertTrue($first['processed']);
        $this->assertFalse($first['replayed']);
        $this->assertEquals(1, $executionCount);
        $this->assertEquals(['order_credited' => true, 'count' => 1], $first['result']);

        // Second delivery (e.g. gateway network retry): deduplicated, work NOT re-executed!
        $second = $manager->idempotentWebhook($eventId, $work);
        $this->assertFalse($second['processed']);
        $this->assertTrue($second['replayed']);
        $this->assertEquals(1, $executionCount, "Work must NOT execute twice on webhook retry");
        $this->assertEquals(['order_credited' => true, 'count' => 1], $second['result']);
    }

    public function testCustomGatewayExtension(): void
    {
        $manager = new PaymentManager();
        $manager->extend('custom_wallet', function() {
            return new class implements PaymentGatewayInterface {
                public function getName(): string { return 'custom_wallet'; }
                public function isConfigured(): bool { return true; }
                public function initialize(string $email, float $amount, string $callbackUrl, ?string $reference = null, array $options = []): array {
                    return ['checkout_url' => 'https://wallet.local/pay', 'reference' => 'WAL_123', 'raw' => []];
                }
                public function verify(string $reference): array {
                    return ['status' => 'success', 'amount' => 10.0, 'currency' => 'USD', 'reference' => $reference, 'raw' => []];
                }
                public function verifyWebhookSignature(string $payload, string $signature): bool {
                    return true;
                }
            };
        });

        $gateway = $manager->driver('custom_wallet');
        $this->assertEquals('custom_wallet', $gateway->getName());
        $res = $gateway->initialize('user@test.com', 50.0, 'https://callback');
        $this->assertEquals('https://wallet.local/pay', $res['checkout_url']);
    }
}
