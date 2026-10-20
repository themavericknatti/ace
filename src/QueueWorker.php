<?php

namespace Ace;

use Throwable;

/**
 * QueueWorker — Background Job Worker Daemon / Processor
 *
 * Runs a continuous loop (or single-pass run) pulling jobs from the database queue,
 * executing them, handling failures, exponential/custom backoff, and moving
 * dead jobs to `failed_jobs`.
 *
 * Usage:
 *   $worker = new QueueWorker();
 *   $worker->work('default', 3, 3, false);
 */
class QueueWorker
{
    /**
     * Whether the worker should stop running.
     */
    protected bool $shouldQuit = false;

    /**
     * Start working jobs from the given queue.
     *
     * @param string $queue Queue name to poll
     * @param int $sleep Seconds to sleep when no jobs are available
     * @param int $maxTries Maximum attempts per job before failing
     * @param bool $stopWhenEmpty If true, exits when no jobs are pending
     * @param callable|null $output Callback for console output messages
     * @return int Total number of jobs processed
     */
    public function work(
        string $queue = 'default',
        int $sleep = 3,
        int $maxTries = 3,
        bool $stopWhenEmpty = false,
        ?callable $output = null
    ): int {
        $log = $output ?? function (string $msg) {
            echo $msg;
        };

        $log("⚙️  Queue Worker started for queue [{$queue}]...\n");
        $log("   Max tries: {$maxTries} | Sleep interval: {$sleep}s\n");
        $log("   Press Ctrl+C to stop.\n\n");

        $processedCount = 0;

        while (!$this->shouldQuit) {
            try {
                $job = Queue::pop($queue);

                if ($job === null) {
                    if ($stopWhenEmpty) {
                        $log("🏁 No more jobs in [{$queue}]. Worker exiting.\n");
                        break;
                    }

                    sleep($sleep);
                    continue;
                }

                $jobClass = get_class($job);
                $log("Processing: [ID: {$job->id}] {$jobClass} (Attempt {$job->attempts}/{$maxTries})...\n");
                $startTime = microtime(true);

                try {
                    // Set max attempts limit from worker if not explicitly defined on job
                    $limit = $job->maxAttempts ?? $maxTries;

                    $job->handle();

                    // Successfully completed: remove job from queue
                    Queue::delete($job->id);

                    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                    $log("   ✅ Processed: [ID: {$job->id}] {$jobClass} ({$elapsed}ms)\n");
                    $processedCount++;
                } catch (Throwable $e) {
                    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                    $log("   ❌ Error: " . $e->getMessage() . " ({$elapsed}ms)\n");

                    $limit = $job->maxAttempts ?? $maxTries;

                    if ($job->attempts >= $limit) {
                        // Max retries reached: call failed hook and move to failed_jobs
                        try {
                            $job->failed($e);
                        } catch (Throwable $failHookEx) {
                            $log("   ⚠️  Job failed() hook threw: " . $failHookEx->getMessage() . "\n");
                        }

                        $payload = json_encode([
                            'job'  => $jobClass,
                            'data' => $job->data,
                        ], JSON_UNESCAPED_SLASHES);

                        Queue::fail($job->id, $queue, $payload, $e);
                        $log("   🛑 Job [ID: {$job->id}] failed permanently and moved to failed_jobs table.\n");
                    } else {
                        // Retry with exponential backoff: 5s, 15s, 30s...
                        $backoff = (int)pow(2, $job->attempts) * 3;
                        Queue::release($job->id, $backoff);
                        $log("   🔄 Job [ID: {$job->id}] released for retry in {$backoff}s.\n");
                    }
                }
            } catch (Throwable $loopException) {
                $log("❌ Worker Loop Error: " . $loopException->getMessage() . "\n");
                if ($stopWhenEmpty) {
                    break;
                }
                sleep($sleep);
            }
        }

        return $processedCount;
    }

    /**
     * Stop the worker loop gracefully.
     */
    public function stop(): void
    {
        $this->shouldQuit = true;
    }
}
