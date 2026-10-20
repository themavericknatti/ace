<?php

namespace Ace;

use Exception;
use Throwable;

/**
 * Queue — Database-Backed Asynchronous Job Queue
 *
 * Provides enterprise-grade background task processing without Redis.
 * Jobs are stored in the `jobs` table and processed asynchronously
 * by `php ace queue:work`.
 *
 * Usage:
 *   Queue::push(SendEmailJob::class, ['email' => 'user@example.com']);
 *   Queue::later(60, SendInvoiceJob::class, ['order_id' => 101]);
 *   $size = Queue::size('default');
 */
class Queue
{
    /**
     * Push a new job onto the queue.
     *
     * @param string $jobClass The fully qualified class name of the job
     * @param array $data Associative array of job payload data
     * @param string $queue Queue name (default 'default')
     * @param int $delay Delay in seconds before the job becomes available
     * @return int|bool The inserted job ID or true if sync
     */
    public static function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): int|bool
    {
        $db = Application::$app->db ?? null;

        // If no database or sync mode is set in environment, execute immediately
        if (!$db || ($_ENV['QUEUE_DRIVER'] ?? 'database') === 'sync') {
            return static::executeSync($jobClass, $data);
        }

        $now = time();
        $availableAt = date('Y-m-d H:i:s', $now + $delay);
        $createdAt = date('Y-m-d H:i:s', $now);

        $payload = json_encode([
            'job'  => $jobClass,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);

        $stmt = $db->prepare("
            INSERT INTO `jobs` (`queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`)
            VALUES (:queue, :payload, 0, NULL, :available_at, :created_at)
        ");

        $stmt->execute([
            'queue'        => $queue,
            'payload'      => $payload,
            'available_at' => $availableAt,
            'created_at'   => $createdAt,
        ]);

        return (int)$db->pdo->lastInsertId();
    }

    /**
     * Push a new job onto the queue after a delay.
     */
    public static function later(int $delaySeconds, string $jobClass, array $data = [], string $queue = 'default'): int|bool
    {
        return static::push($jobClass, $data, $queue, $delaySeconds);
    }

    /**
     * Pop the next available job off the queue.
     * Atomically marks the job as reserved to prevent concurrent workers from picking it up.
     *
     * @param string $queue
     * @return Job|null
     */
    public static function pop(string $queue = 'default'): ?Job
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return null;
        }

        $now = date('Y-m-d H:i:s');

        // Begin transaction to safely reserve a job
        $db->pdo->beginTransaction();

        try {
            // Find next job that is available and not currently reserved
            $stmt = $db->prepare("
                SELECT * FROM `jobs`
                WHERE `queue` = :queue
                  AND `available_at` <= :now
                  AND (`reserved_at` IS NULL OR `reserved_at` < DATE_SUB(:now2, INTERVAL 300 SECOND))
                ORDER BY `id` ASC
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                'queue' => $queue,
                'now'   => $now,
                'now2'  => $now,
            ]);

            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$record) {
                $db->pdo->commit();
                return null;
            }

            // Mark job as reserved and increment attempts
            $update = $db->prepare("
                UPDATE `jobs`
                SET `reserved_at` = :now,
                    `attempts` = `attempts` + 1
                WHERE `id` = :id
            ");

            $update->execute([
                'now' => $now,
                'id'  => $record['id'],
            ]);

            $db->pdo->commit();

            // Decode payload and instantiate Job class
            $payload = json_decode($record['payload'], true);
            $jobClass = $payload['job'] ?? null;
            $jobData = $payload['data'] ?? [];

            if (!class_exists($jobClass)) {
                throw new Exception("Job class '{$jobClass}' not found for job ID {$record['id']}");
            }

            /** @var Job $job */
            $job = new $jobClass($jobData);
            $job->id = (int)$record['id'];
            $job->queue = $record['queue'];
            $job->attempts = (int)$record['attempts'] + 1;

            return $job;
        } catch (Throwable $e) {
            if ($db->pdo->inTransaction()) {
                $db->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete a completed job from the queue.
     */
    public static function delete(int|string $jobId): bool
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("DELETE FROM `jobs` WHERE `id` = :id");
        $stmt->execute(['id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Release a reserved job back into the queue for a retry.
     */
    public static function release(int|string $jobId, int $delay = 0): bool
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return false;
        }

        $now = time();
        $availableAt = date('Y-m-d H:i:s', $now + $delay);

        $stmt = $db->prepare("
            UPDATE `jobs`
            SET `reserved_at` = NULL,
                `available_at` = :available_at
            WHERE `id` = :id
        ");

        $stmt->execute([
            'available_at' => $availableAt,
            'id'           => $jobId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a job as permanently failed and move it to `failed_jobs`.
     */
    public static function fail(int|string $jobId, string $queue, string $payload, Throwable $exception): void
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return;
        }

        // Insert into failed_jobs table
        $stmt = $db->prepare("
            INSERT INTO `failed_jobs` (`queue`, `payload`, `exception`, `failed_at`)
            VALUES (:queue, :payload, :exception, :failed_at)
        ");

        $stmt->execute([
            'queue'     => $queue,
            'payload'   => $payload,
            'exception' => (string)$exception,
            'failed_at' => date('Y-m-d H:i:s'),
        ]);

        // Remove from active jobs
        static::delete($jobId);
    }

    /**
     * Get the count of pending jobs in a queue.
     */
    public static function size(string $queue = 'default'): int
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return 0;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM `jobs` WHERE `queue` = :queue");
        $stmt->execute(['queue' => $queue]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Clear all pending jobs in a queue.
     */
    public static function clear(string $queue = 'default'): int
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return 0;
        }

        $stmt = $db->prepare("DELETE FROM `jobs` WHERE `queue` = :queue");
        $stmt->execute(['queue' => $queue]);
        return $stmt->rowCount();
    }

    /**
     * Get all failed jobs.
     */
    public static function failed(): array
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("SELECT * FROM `failed_jobs` ORDER BY `failed_at` DESC");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Retry a failed job by ID.
     */
    public static function retry(int|string $failedId): bool
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("SELECT * FROM `failed_jobs` WHERE `id` = :id LIMIT 1");
        $stmt->execute(['id' => $failedId]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$record) {
            return false;
        }

        // Re-insert into active jobs
        $reinsert = $db->prepare("
            INSERT INTO `jobs` (`queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`)
            VALUES (:queue, :payload, 0, NULL, :now, :now2)
        ");

        $now = date('Y-m-d H:i:s');
        $reinsert->execute([
            'queue'   => $record['queue'],
            'payload' => $record['payload'],
            'now'     => $now,
            'now2'    => $now,
        ]);

        // Remove from failed_jobs
        $delete = $db->prepare("DELETE FROM `failed_jobs` WHERE `id` = :id");
        $delete->execute(['id' => $failedId]);

        return true;
    }

    /**
     * Retry all failed jobs.
     */
    public static function retryAll(): int
    {
        $failed = static::failed();
        $count = 0;
        foreach ($failed as $job) {
            if (static::retry($job['id'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Execute a job synchronously (immediate execution).
     */
    private static function executeSync(string $jobClass, array $data): bool
    {
        if (!class_exists($jobClass)) {
            throw new Exception("Job class '{$jobClass}' not found.");
        }

        /** @var Job $job */
        $job = new $jobClass($data);
        $job->handle();
        return true;
    }
}
