<?php

namespace Ace;

/**
 * ApiKey — API Key Generation, Hashing & Validation
 *
 * Provides secure API key management for API-first projects.
 * Keys are generated with a readable prefix for identification
 * and stored as SHA-256 hashes in the database.
 *
 * Usage:
 *   $key = ApiKey::generate('My App');           // Returns plain key (show once)
 *   $valid = ApiKey::validate($submittedKey);    // Returns key record or false
 *   $keys = ApiKey::all();                       // List all active keys
 */
class ApiKey
{
    /**
     * The API keys table name
     */
    public static function tableName(): string
    {
        return 'api_keys';
    }

    /**
     * Generate a new API key.
     *
     * @param string $name Human-readable name for the key (e.g. 'React Frontend', 'Mobile App')
     * @param array $permissions Optional JSON permissions array
     * @param string|null $expiresAt Optional expiry date (Y-m-d H:i:s format)
     * @return array ['plain_key' => '...', 'prefix' => '...', 'name' => '...'] - plain_key is shown ONCE
     */
    public static function generate(string $name, array $permissions = ['*'], ?string $expiresAt = null): array
    {
        $db = Application::$app->db;
        if (!$db) {
            throw new \RuntimeException('Database connection required for API key generation.');
        }

        // Generate a 64-character hex key (32 bytes of randomness)
        $plainKey = bin2hex(random_bytes(32));

        // First 8 characters serve as the prefix for key identification
        $prefix = substr($plainKey, 0, 8);

        // Store SHA-256 hash of the key (never store plain keys)
        $keyHash = hash('sha256', $plainKey);

        $stmt = $db->prepare("
            INSERT INTO api_keys (name, key_hash, prefix, permissions, expires_at, created_at, is_active)
            VALUES (:name, :key_hash, :prefix, :permissions, :expires_at, :created_at, 1)
        ");

        $stmt->execute([
            'name'        => $name,
            'key_hash'    => $keyHash,
            'prefix'      => $prefix,
            'permissions'  => json_encode($permissions),
            'expires_at'  => $expiresAt,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return [
            'plain_key'   => $plainKey,
            'prefix'      => $prefix,
            'name'        => $name,
            'permissions' => $permissions,
            'expires_at'  => $expiresAt,
        ];
    }

    /**
     * Validate a submitted API key.
     *
     * Checks:
     *   1. Key exists in database (by hash)
     *   2. Key is active (is_active = 1)
     *   3. Key has not expired
     *
     * Updates last_used_at timestamp on successful validation.
     *
     * @param string $plainKey The raw API key submitted by the client
     * @return array|false The key record on success, false on failure
     */
    public static function validate(string $plainKey): array|false
    {
        $db = Application::$app->db;
        if (!$db) {
            return false;
        }

        $keyHash = hash('sha256', $plainKey);

        $stmt = $db->prepare("
            SELECT * FROM api_keys
            WHERE key_hash = :key_hash AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['key_hash' => $keyHash]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$record) {
            return false;
        }

        // Check expiry
        if (!empty($record['expires_at']) && strtotime($record['expires_at']) < time()) {
            return false;
        }

        // Update last_used_at
        $updateStmt = $db->prepare("
            UPDATE api_keys SET last_used_at = :now WHERE id = :id
        ");
        $updateStmt->execute([
            'now' => date('Y-m-d H:i:s'),
            'id'  => $record['id'],
        ]);

        // Decode permissions
        $record['permissions'] = json_decode($record['permissions'] ?? '["*"]', true);

        return $record;
    }

    /**
     * Revoke (deactivate) an API key by its prefix.
     *
     * @param string $prefix The 8-character prefix of the key
     * @return bool True if a key was revoked, false if not found
     */
    public static function revoke(string $prefix): bool
    {
        $db = Application::$app->db;
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("
            UPDATE api_keys SET is_active = 0 WHERE prefix = :prefix
        ");
        $stmt->execute(['prefix' => $prefix]);

        return $stmt->rowCount() > 0;
    }

    /**
     * List all API keys (without hashes, for display purposes).
     *
     * @return array List of key records
     */
    public static function all(): array
    {
        $db = Application::$app->db;
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT id, name, prefix, permissions, last_used_at, expires_at, created_at, is_active
            FROM api_keys
            ORDER BY created_at DESC
        ");
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Delete expired or revoked API keys from the database.
     *
     * @return int Number of keys cleaned up
     */
    public static function cleanup(): int
    {
        $db = Application::$app->db;
        if (!$db) {
            return 0;
        }

        $stmt = $db->prepare("
            DELETE FROM api_keys
            WHERE is_active = 0 OR (expires_at IS NOT NULL AND expires_at < :now)
        ");
        $stmt->execute(['now' => date('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    /**
     * Check if a validated API key has a specific permission.
     *
     * @param array $keyRecord The validated key record from validate()
     * @param string $permission The permission to check (e.g. 'users:read')
     * @return bool
     */
    public static function hasPermission(array $keyRecord, string $permission): bool
    {
        $permissions = $keyRecord['permissions'] ?? ['*'];

        // Wildcard grants all permissions
        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }
}
