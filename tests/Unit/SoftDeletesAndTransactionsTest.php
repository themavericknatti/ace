<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Model;
use Ace\QueryBuilder;
use Ace\Database;
use PDO;

class SoftDeletesAndTransactionsTest extends TestCase
{
    public function test_model_soft_delete_attributes_and_helpers(): void
    {
        $dummy = new class extends Model {
            protected bool $softDelete = true;
            public function primaryKey(): string { return 'id'; }
            public function rules(): array { return []; }
            public static function tableName(): string { return 'dummy_items'; }
        };

        $this->assertTrue($dummy->isSoftDeleteEnabled());
        $this->assertFalse($dummy->isTrashed());
        $this->assertFalse($dummy->trashed());

        // Simulate soft deleted timestamp
        $dummy->deleted_at = '2026-10-21 12:00:00';
        $this->assertTrue($dummy->isTrashed());
        $this->assertTrue($dummy->trashed());
    }

    public function test_query_builder_soft_delete_sql_scoping(): void
    {
        $modelClass = get_class(new class extends Model {
            protected bool $softDelete = true;
            public function primaryKey(): string { return 'id'; }
            public function rules(): array { return []; }
            public static function tableName(): string { return 'articles'; }
        });

        // 1. Default query excludes soft-deleted records
        $qb = new QueryBuilder($modelClass);
        $sql = $qb->toSql();
        $this->assertStringContainsString("`articles`.`deleted_at` IS NULL", $sql);

        // 2. withTrashed() includes soft-deleted records
        $qbWith = (new QueryBuilder($modelClass))->withTrashed();
        $sqlWith = $qbWith->toSql();
        $this->assertStringNotContainsString("`articles`.`deleted_at` IS NULL", $sqlWith);
        $this->assertStringNotContainsString("`articles`.`deleted_at` IS NOT NULL", $sqlWith);

        // 3. onlyTrashed() queries ONLY soft-deleted records
        $qbOnly = (new QueryBuilder($modelClass))->onlyTrashed();
        $sqlOnly = $qbOnly->toSql();
        $this->assertStringContainsString("`articles`.`deleted_at` IS NOT NULL", $sqlOnly);
    }

    public function test_database_nested_transactions_with_savepoints(): void
    {
        // Use an in-memory SQLite connection wrapped into a mock Database object
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create a dummy table
        $pdo->exec("CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER)");

        $db = new class extends Database {
            public function __construct() {}
        };
        $db->pdo = $pdo;

        // Level 0 initially
        $this->assertEquals(0, $db->getTransactionLevel());

        // Outer transaction
        $db->beginTransaction();
        $this->assertEquals(1, $db->getTransactionLevel());
        $pdo->exec("INSERT INTO accounts (id, balance) VALUES (1, 100)");

        // Inner nested transaction (creates SAVEPOINT trans_2)
        $db->beginTransaction();
        $this->assertEquals(2, $db->getTransactionLevel());
        $pdo->exec("INSERT INTO accounts (id, balance) VALUES (2, 200)");

        // Roll back inner transaction (ROLLBACK TO SAVEPOINT trans_2)
        $db->rollback();
        $this->assertEquals(1, $db->getTransactionLevel());

        // Commit outer transaction
        $db->commit();
        $this->assertEquals(0, $db->getTransactionLevel());

        // Verify account 1 was saved, account 2 was rolled back by savepoint
        $stmt = $pdo->query("SELECT COUNT(*) FROM accounts WHERE id = 1");
        $this->assertEquals(1, (int)$stmt->fetchColumn());

        $stmt = $pdo->query("SELECT COUNT(*) FROM accounts WHERE id = 2");
        $this->assertEquals(0, (int)$stmt->fetchColumn());
    }

    public function test_global_db_transaction_helper_exists(): void
    {
        $this->assertTrue(function_exists('db_transaction'));
    }
}
