<?php

namespace Ace;

use Throwable;

/**
 * IdempotencyManager — Manages Request Idempotency & Deduplication
 *
 * Implements standard RFC / Stripe-style Idempotency-Key support.
 * Ensures duplicate or retried mutation requests produce identical responses
 * without double-executing controllers, services, database mutations, or payments.
 */
class IdempotencyManager
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';

    /**
     * Default key time-to-live in seconds (24 hours).
     */
    protected int $ttl = 86400;

    /**
     * Lock timeout for in-flight requests in seconds (2 minutes).
     */
    protected int $lockTimeout = 120;

    public function __construct(int $ttl = 86400, int $lockTimeout = 120)
    {
        $this->ttl = $ttl;
        $this->lockTimeout = $lockTimeout;
    }

    /**
     * Generate deterministic SHA-256 hash of the request method, path, and payload.
     */
    public function computeRequestHash(string $method, string $path, array|string $body): string
    {
        $payloadString = is_array($body) ? json_encode($this->sortRecursive($body), JSON_UNESCAPED_SLASHES) : (string)$body;
        return hash('sha256', strtoupper($method) . ':' . $path . ':' . $payloadString);
    }

    /**
     * Attempt to reserve or find an existing idempotency record.
     *
     * Returns:
     * - `['status' => 'new']`: Successfully claimed; caller should proceed to execute action.
     * - `['status' => 'completed', 'record' => $record]`: Already executed; replay cached response.
     * - `['status' => 'in_flight']`: Another request with this key is currently executing.
     * - `['status' => 'mismatch']`: Same key used with a different request payload.
     *
     * @param string $key The client-provided idempotency key
     * @param string $scope Scope / Tenant identifier (e.g. user_id or api_key prefix)
     * @param string $requestHash The hash of the incoming request
     * @return array
     */
    public function check(string $key, string $scope, string $requestHash): array
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            // If no database available, pass-through as new
            return ['status' => 'new'];
        }

        $now = date('Y-m-d H:i:s');

        // Look up existing record
        $stmt = $db->prepare("
            SELECT * FROM `idempotency_keys`
            WHERE `scope` = :scope AND `idempotency_key` = :key
            LIMIT 1
        ");
        $stmt->execute([
            'scope' => $scope,
            'key'   => $key,
        ]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($record) {
            // Check if key has expired
            if (strtotime($record['expires_at']) < time()) {
                // Delete expired record so it can be re-used cleanly
                $del = $db->prepare("DELETE FROM `idempotency_keys` WHERE `id` = :id");
                $del->execute(['id' => $record['id']]);
                $record = null;
            }
        }

        if ($record) {
            // Verify payload integrity: same key with different payload is an error
            if ($record['request_hash'] !== $requestHash) {
                return ['status' => 'mismatch', 'record' => $record];
            }

            if ($record['status'] === self::STATUS_COMPLETED) {
                return ['status' => 'completed', 'record' => $record];
            }

            // In-flight: check if lock has timed out (deadlock recovery)
            $lockedTime = strtotime($record['locked_at']);
            if ((time() - $lockedTime) < $this->lockTimeout) {
                return ['status' => 'in_flight', 'record' => $record];
            }

            // Lock timed out: re-claim it
            $update = $db->prepare("
                UPDATE `idempotency_keys`
                SET `locked_at` = :now
                WHERE `id` = :id
            ");
            $update->execute(['now' => $now, 'id' => $record['id']]);
            return ['status' => 'new'];
        }

        // Insert new pending record
        $expiresAt = date('Y-m-d H:i:s', time() + $this->ttl);
        try {
            $insert = $db->prepare("
                INSERT INTO `idempotency_keys`
                (`idempotency_key`, `scope`, `request_hash`, `status`, `locked_at`, `expires_at`, `created_at`)
                VALUES (:key, :scope, :hash, :status, :now1, :expires, :now2)
            ");
            $insert->execute([
                'key'     => $key,
                'scope'   => $scope,
                'hash'    => $requestHash,
                'status'  => self::STATUS_PENDING,
                'now1'    => $now,
                'expires' => $expiresAt,
                'now2'    => $now,
            ]);

            return ['status' => 'new'];
        } catch (Throwable $e) {
            // Race condition: another thread inserted between check and insert
            return ['status' => 'in_flight'];
        }
    }

    /**
     * Complete an idempotency transaction and store the resulting HTTP response.
     */
    public function complete(
        string $key,
        string $scope,
        int $statusCode,
        string $responseBody,
        array $headers = []
    ): bool {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return false;
        }

        // Only store application-specific headers (JSON/content-type, custom headers)
        $cleanHeaders = array_filter($headers, function ($k) {
            $lower = strtolower($k);
            return !in_array($lower, ['set-cookie', 'date', 'server', 'transfer-encoding']);
        }, ARRAY_FILTER_USE_KEY);

        $stmt = $db->prepare("
            UPDATE `idempotency_keys`
            SET `status` = :status,
                `response_code` = :code,
                `response_body` = :body,
                `response_headers` = :headers
            WHERE `scope` = :scope AND `idempotency_key` = :key
        ");

        return $stmt->execute([
            'status'  => self::STATUS_COMPLETED,
            'code'    => $statusCode,
            'body'    => $responseBody,
            'headers' => json_encode($cleanHeaders, JSON_UNESCAPED_SLASHES),
            'scope'   => $scope,
            'key'     => $key,
        ]);
    }

    /**
     * Purge all expired idempotency keys from the database.
     */
    public function purgeExpired(): int
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return 0;
        }

        $stmt = $db->prepare("DELETE FROM `idempotency_keys` WHERE `expires_at` < :now");
        $stmt->execute(['now' => date('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    /**
     * Sort array keys recursively for consistent JSON hashing.
     */
    protected function sortRecursive(array $array): array
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = $this->sortRecursive($value);
            }
        }
        ksort($array);
        return $array;
    }
}
