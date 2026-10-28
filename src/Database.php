<?php

namespace Ace;

use PDO;
use Exception;

class Database
{
    public PDO $pdo;
    protected int $transactionLevel = 0;
    protected static array $connections = [];

    public function __construct(array $config)
    {
        $driver = strtolower($config['driver'] ?? 'mysql');

        // SQLite support
        if ($driver === 'sqlite') {
            $dbPath = $config['database'] ?? ':memory:';
            $dsn = "sqlite:{$dbPath}";
            $this->pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return;
        }

        // PostgreSQL support
        if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? '5432';
            $dbName = $config['database'] ?? '';
            $username = $config['username'] ?? 'postgres';
            $password = $config['password'] ?? '';
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return;
        }

        // MySQL / MariaDB support
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? '3306';
        $dbName = $config['database'] ?? '';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        $dsn = "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Exception $e) {
            // Check for code 1049 (Unknown database) to auto-create
            if (str_contains($e->getMessage(), 'Unknown database') || $e->getCode() == 1049) {
                try {
                    $tempDsn = "mysql:host=$host;port=$port;charset=utf8mb4";
                    $tempPdo = new PDO($tempDsn, $username, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    
                    // Re-attempt connecting with dbName
                    $this->pdo = new PDO($dsn, $username, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                } catch (Exception $subEx) {
                    throw new Exception("Database Connection Failed (could not auto-create database): " . $subEx->getMessage(), 500);
                }
            } else {
                throw new Exception("Database Connection Failed: " . $e->getMessage(), 500);
            }
        }
    }

    /**
     * Helper to prepare PDO statement
     */
    public function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    /**
     * Execute a callback within a database transaction.
     * Automatically commits on success, rolls back on exception.
     *
     *   $db->transaction(function($db) {
     *       $user = User::create(['name' => 'John']);
     *       Role::create(['user_id' => $user->id, 'name' => 'admin']);
     *   });
     *
     * @throws \Throwable Re-throws the exception after rollback
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Start a database transaction
     */
    public function beginTransaction(): bool
    {
        $this->transactionLevel++;
        if ($this->transactionLevel === 1) {
            return $this->pdo->beginTransaction();
        }
        $this->pdo->exec("SAVEPOINT trans_{$this->transactionLevel}");
        return true;
    }

    /**
     * Commit the active transaction
     */
    public function commit(): bool
    {
        if ($this->transactionLevel <= 0) {
            return false;
        }
        $level = $this->transactionLevel;
        $this->transactionLevel--;
        if ($level === 1) {
            return $this->pdo->commit();
        }
        $this->pdo->exec("RELEASE SAVEPOINT trans_{$level}");
        return true;
    }

    /**
     * Roll back the active transaction
     */
    public function rollback(): bool
    {
        if ($this->transactionLevel <= 0) {
            return false;
        }
        $level = $this->transactionLevel;
        $this->transactionLevel--;
        if ($level === 1) {
            return $this->pdo->rollBack();
        }
        $this->pdo->exec("ROLLBACK TO SAVEPOINT trans_{$level}");
        return true;
    }

    /**
     * Get the active transaction nesting level.
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Execute a callback in a transaction statically using current application db connection.
     */
    /**
     * Register or attach a named database connection partition.
     */
    public static function addConnection(string $name, array|Database $configOrInstance): void
    {
        if ($configOrInstance instanceof Database) {
            static::$connections[$name] = $configOrInstance;
        } else {
            static::$connections[$name] = new Database($configOrInstance);
        }
    }

    /**
     * Retrieve a named database connection partition, or default if null.
     */
    public static function connection(?string $name = null): ?Database
    {
        if ($name === null || $name === 'default') {
            return Application::$app->db ?? (static::$connections['default'] ?? null);
        }

        if (isset(static::$connections[$name])) {
            return static::$connections[$name];
        }

        if (isset(Application::$app) && isset(Application::$app->config['db']['connections'][$name])) {
            static::$connections[$name] = new Database(Application::$app->config['db']['connections'][$name]);
            return static::$connections[$name];
        }

        return null;
    }

    /**
     * Check if a named database connection is registered.
     */
    public static function hasConnection(string $name): bool
    {
        return isset(static::$connections[$name]) ||
            (isset(Application::$app) && isset(Application::$app->config['db']['connections'][$name]));
    }

    /**
     * Disconnect and clear one or all cached database connections.
     */
    public static function disconnect(?string $name = null): void
    {
        if ($name === null) {
            static::$connections = [];
        } else {
            unset(static::$connections[$name]);
        }
    }

    /**
     * Execute a callback in a transaction statically using current application db connection or named connection partition.
     */
    public static function run(callable $callback, ?string $connection = null): mixed
    {
        $db = static::connection($connection);
        if (!$db) {
            throw new Exception("Database connection '{$connection}' not established.");
        }
        return $db->transaction($callback);
    }

    /**
     * Inspect MySQL table to retrieve column names dynamically
     */
    public function getTableColumns(string $table): array
    {
        try {
            $statement = $this->pdo->prepare("DESCRIBE `$table`");
            $statement->execute();
            return $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // Table might not exist yet
            return [];
        }
    }

    /**
     * Check if a table exists
     */
    public function tableExists(string $table): bool
    {
        try {
            $result = $this->pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            return $result !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Run migration scripts to initialize standard tables
     */
    public function runMigrations(): void
    {
        $manager = new MigrationManager($this->pdo, Application::$ROOT_DIR . '/migrations');
        $manager->applyMigrations();
    }
}

