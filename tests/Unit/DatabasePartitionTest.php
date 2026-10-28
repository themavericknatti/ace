<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Database;
use Ace\Model;
use Ace\Relations\HasMany;
use Ace\Relations\BelongsTo;
use PDO;

class DatabasePartitionTest extends TestCase
{
    protected function setUp(): void
    {
        // 1. Setup SQLite in-memory Database partition 'tenant_alpha'
        $pdoA = new PDO('sqlite::memory:');
        $pdoA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdoA->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
        $pdoA->exec("CREATE TABLE orders_2026_q1 (id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL)");
        $pdoA->exec("CREATE TABLE orders_2026_q2 (id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL)");
        $pdoA->exec("CREATE TABLE legacy_members (member_id INTEGER PRIMARY KEY, full_name TEXT)");

        $dbA = new class extends Database {
            public function __construct() {}
        };
        $dbA->pdo = $pdoA;
        Database::addConnection('tenant_alpha', $dbA);

        // 2. Setup SQLite in-memory Database partition 'tenant_beta'
        $pdoB = new PDO('sqlite::memory:');
        $pdoB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdoB->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");

        $dbB = new class extends Database {
            public function __construct() {}
        };
        $dbB->pdo = $pdoB;
        Database::addConnection('tenant_beta', $dbB);
    }

    protected function tearDown(): void
    {
        Database::disconnect();
    }

    public function test_multi_database_connection_partitioning(): void
    {
        // Seed Alpha
        $dbAlpha = Database::connection('tenant_alpha');
        $dbAlpha->pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Alpha Alice', 'alice@alpha.com')");

        // Seed Beta
        $dbBeta = Database::connection('tenant_beta');
        $dbBeta->pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Beta Bob', 'bob@beta.com')");

        $userModel = new class extends Model {
            public static function tableName(): string { return 'users'; }
            public function primaryKey(): string { return 'id'; }
            public function rules(): array { return []; }
        };
        $class = get_class($userModel);

        // Query Alpha partition
        $alphaUsers = $class::onConnection('tenant_alpha')->get();
        $this->assertCount(1, $alphaUsers);
        $this->assertEquals('Alpha Alice', $alphaUsers[0]->name);
        $this->assertEquals('tenant_alpha', $alphaUsers[0]->getConnection());

        // Query Beta partition
        $betaUsers = $class::onConnection('tenant_beta')->get();
        $this->assertCount(1, $betaUsers);
        $this->assertEquals('Beta Bob', $betaUsers[0]->name);
        $this->assertEquals('tenant_beta', $betaUsers[0]->getConnection());
    }

    public function test_dynamic_table_partitioning(): void
    {
        $db = Database::connection('tenant_alpha');
        $db->pdo->exec("INSERT INTO orders_2026_q1 (id, user_id, amount) VALUES (1, 10, 150.00)");
        $db->pdo->exec("INSERT INTO orders_2026_q2 (id, user_id, amount) VALUES (2, 10, 300.00)");

        $orderModel = new class extends Model {
            public static function tableName(): string { return 'orders'; }
            public function primaryKey(): string { return 'id'; }
            public function rules(): array { return []; }
        };
        $class = get_class($orderModel);

        // Query Q1 partition
        $q1Orders = $class::onConnection('tenant_alpha')->partition('2026_q1')->get();
        $this->assertCount(1, $q1Orders);
        $this->assertEquals(150.00, (float)$q1Orders[0]->amount);
        $this->assertEquals('orders_2026_q1', $q1Orders[0]->getTable());

        // Query Q2 partition
        $q2Orders = $class::onConnection('tenant_alpha')->partition('2026_q2')->get();
        $this->assertCount(1, $q2Orders);
        $this->assertEquals(300.00, (float)$q2Orders[0]->amount);
        $this->assertEquals('orders_2026_q2', $q2Orders[0]->getTable());
    }

    public function test_legacy_table_and_primary_key_adaptation(): void
    {
        $db = Database::connection('tenant_alpha');
        $db->pdo->exec("INSERT INTO legacy_members (member_id, full_name) VALUES (99, 'Old Member')");

        $memberModel = new class extends Model {
            public static function tableName(): string { return 'legacy_members'; }
            public function primaryKey(): string { return 'member_id'; }
            public function rules(): array { return []; }
            public bool $timestamps = false; // Legacy table without timestamps
        };
        $class = get_class($memberModel);

        $member = $class::onConnection('tenant_alpha')->where('member_id', 99)->first();
        $this->assertNotNull($member);
        $this->assertEquals('Old Member', $member->full_name);
        $this->assertEquals(99, $member->member_id);
    }

    public function test_relationship_inherits_partition_connection(): void
    {
        $db = Database::connection('tenant_alpha');
        $db->pdo->exec("INSERT INTO orders_2026_q1 (id, user_id, amount) VALUES (101, 10, 150.00)");

        $userModel = new class extends Model {
            public static function tableName(): string { return 'users'; }
            public function primaryKey(): string { return 'id'; }
            public function rules(): array { return []; }

            public function orders(): HasMany {
                $orderClass = get_class(new class extends Model {
                    public static function tableName(): string { return 'orders_2026_q1'; }
                    public function primaryKey(): string { return 'id'; }
                    public function rules(): array { return []; }
                });
                return $this->hasMany($orderClass, 'user_id', 'id');
            }
        };

        // User on tenant_alpha
        $user = new $userModel();
        $user->id = 10;
        $user->setConnection('tenant_alpha');

        $relation = $user->orders();
        $this->assertEquals('tenant_alpha', $relation->getQuery()->getConnectionName());

        $orders = $relation->getResults();
        $this->assertCount(1, $orders);
        $this->assertEquals(150.00, (float)$orders[0]->amount);
    }
}
