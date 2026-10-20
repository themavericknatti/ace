<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\IdempotencyManager;
use Ace\Middlewares\IdempotencyMiddleware;
use Ace\Application;
use Ace\Database;
use PDO;

class IdempotencyTest extends TestCase
{
    protected ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Use in-memory SQLite database for deterministic tests
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create idempotency_keys table schema in SQLite
        $this->pdo->exec("
            CREATE TABLE idempotency_keys (
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

        // Boot Application if not already available
        if (!isset(Application::$app)) {
            $rootDir = dirname(__DIR__, 2);
            new Application($rootDir, ['db' => ['database' => 'test']]);
        }

        // Mock Database component on Application
        $db = $this->createMock(Database::class);
        $db->pdo = $this->pdo;
        $db->method('prepare')->willReturnCallback(function ($sql) {
            $cleanSql = str_replace(
                ['`', 'TIMESTAMP', 'LONGTEXT', 'ENUM(\'pending\', \'completed\')'],
                ['', 'DATETIME', 'TEXT', 'TEXT'],
                $sql
            );
            return $this->pdo->prepare($cleanSql);
        });

        Application::$app->db = $db;
    }

    // =========================================================================
    // 1. Request Hash Computation
    // =========================================================================

    public function test_compute_request_hash_is_deterministic(): void
    {
        $manager = new IdempotencyManager();

        $hash1 = $manager->computeRequestHash('POST', '/api/orders', ['amount' => 100, 'item' => 'Book']);
        // Same payload with keys in different order
        $hash2 = $manager->computeRequestHash('POST', '/api/orders', ['item' => 'Book', 'amount' => 100]);

        // Key-sorting makes them identical
        $this->assertEquals($hash1, $hash2);

        // Different method or endpoint produces different hash
        $hash3 = $manager->computeRequestHash('PUT', '/api/orders', ['item' => 'Book', 'amount' => 100]);
        $this->assertNotEquals($hash1, $hash3);
    }

    // =========================================================================
    // 2. Lifecycle: New -> In-Flight -> Completed -> Replayed
    // =========================================================================

    public function test_first_request_claims_lock_as_new(): void
    {
        $manager = new IdempotencyManager();
        $hash = $manager->computeRequestHash('POST', '/api/pay', ['token' => 'tok_123']);

        $result = $manager->check('key_abc_1', 'user_1', $hash);
        $this->assertEquals('new', $result['status']);
    }

    public function test_concurrent_request_returns_in_flight(): void
    {
        $manager = new IdempotencyManager();
        $hash = $manager->computeRequestHash('POST', '/api/pay', ['token' => 'tok_123']);

        // First thread claims
        $manager->check('key_abc_2', 'user_1', $hash);

        // Second concurrent thread attempts same key while first is still pending
        $second = $manager->check('key_abc_2', 'user_1', $hash);
        $this->assertEquals('in_flight', $second['status']);
    }

    public function test_completed_request_replays_cached_response(): void
    {
        $manager = new IdempotencyManager();
        $hash = $manager->computeRequestHash('POST', '/api/pay', ['token' => 'tok_123']);

        // Claim
        $manager->check('key_abc_3', 'user_1', $hash);

        // Complete with status 201 and JSON payload
        $responsePayload = json_encode(['order_id' => 'ord_999', 'status' => 'paid']);
        $completed = $manager->complete('key_abc_3', 'user_1', 201, $responsePayload, ['Content-Type' => 'application/json']);
        $this->assertTrue($completed);

        // Subsequent retry of the exact same request
        $retry = $manager->check('key_abc_3', 'user_1', $hash);
        $this->assertEquals('completed', $retry['status']);
        $this->assertEquals(201, (int)$retry['record']['response_code']);
        $this->assertEquals($responsePayload, $retry['record']['response_body']);
    }

    public function test_same_key_with_different_payload_is_rejected_as_mismatch(): void
    {
        $manager = new IdempotencyManager();
        $hash1 = $manager->computeRequestHash('POST', '/api/pay', ['amount' => 50]);
        $hash2 = $manager->computeRequestHash('POST', '/api/pay', ['amount' => 5000]); // Altered amount!

        // First request completed
        $manager->check('key_tamper_1', 'user_1', $hash1);
        $manager->complete('key_tamper_1', 'user_1', 200, '{"ok":true}');

        // Attacker attempts to reuse same key for different amount
        $tampered = $manager->check('key_tamper_1', 'user_1', $hash2);
        $this->assertEquals('mismatch', $tampered['status']);
    }

    // =========================================================================
    // 3. Scope Isolation
    // =========================================================================

    public function test_different_users_can_use_same_key_without_collision(): void
    {
        $manager = new IdempotencyManager();
        $hash = $manager->computeRequestHash('POST', '/api/cart', ['item' => 'Shoes']);

        // User A claims key_1
        $userA = $manager->check('key_1', 'user_A', $hash);
        $this->assertEquals('new', $userA['status']);

        // User B claims same key_1 under different scope
        $userB = $manager->check('key_1', 'user_B', $hash);
        $this->assertEquals('new', $userB['status']);
    }

    // =========================================================================
    // 4. Purge Expired Keys
    // =========================================================================

    public function test_purge_removes_only_expired_keys(): void
    {
        $manager = new IdempotencyManager();

        // Insert an expired record directly into sqlite
        $this->pdo->exec("
            INSERT INTO idempotency_keys
            (idempotency_key, scope, request_hash, status, locked_at, expires_at, created_at)
            VALUES ('old_key', 'global', 'hash1', 'completed', '2025-01-01 00:00:00', '2025-01-02 00:00:00', '2025-01-01 00:00:00')
        ");

        // Insert a valid fresh record
        $hash = $manager->computeRequestHash('POST', '/api/test', []);
        $manager->check('fresh_key', 'global', $hash);

        $purged = $manager->purgeExpired();
        $this->assertEquals(1, $purged);

        // fresh_key still exists
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM idempotency_keys WHERE idempotency_key = 'fresh_key'");
        $this->assertEquals(1, (int)$stmt->fetchColumn());
    }

    // =========================================================================
    // 5. Global helper function
    // =========================================================================

    public function test_idempotency_global_helper_returns_manager_instance(): void
    {
        $this->assertInstanceOf(IdempotencyManager::class, idempotency());
    }

    // =========================================================================
    // 6. Full IdempotencyMiddleware Execution Tests
    // =========================================================================

    public function test_middleware_bypasses_get_requests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/products';

        $middleware = new IdempotencyMiddleware();
        // Should execute cleanly without error or DB interaction
        $middleware->execute('index');
        $this->assertTrue(true);
    }

    public function test_middleware_executes_on_post_and_registers_lock(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/checkout';
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'test_key_req_100';
        $_POST = ['amount' => 500];

        $startLevel = ob_get_level();
        $middleware = new IdempotencyMiddleware();
        $middleware->execute('checkout');

        // Verify key was recorded as pending in DB
        $stmt = $this->pdo->query("SELECT * FROM idempotency_keys WHERE idempotency_key = 'test_key_req_100'");
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($record);
        $this->assertEquals('pending', $record['status']);

        // Clean up only buffers opened during test
        while (ob_get_level() > $startLevel) {
            ob_end_clean();
        }
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY'], $_POST['amount']);
    }

    public function test_request_methods_aliases_match(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/users';

        $request = Application::$app->request;
        $this->assertEquals('post', $request->method());
        $this->assertEquals('post', $request->getMethod());
        $this->assertEquals('/api/v1/users', $request->path());
        $this->assertEquals('/api/v1/users', $request->getPath());
    }
}
