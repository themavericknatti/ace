<?php

namespace Ace;

/**
 * Job — Abstract Base Class for Background Queue Jobs
 *
 * All background jobs should extend this class and implement the handle() method.
 *
 * Example:
 *   class SendWelcomeEmailJob extends Job {
 *       public function handle(): void {
 *           $userId = $this->data['user_id'];
 *           // send email...
 *       }
 *   }
 */
abstract class Job
{
    /**
     * Payload data passed to the job when dispatched.
     */
    public array $data = [];

    /**
     * Target queue name (default: 'default').
     */
    public string $queue = 'default';

    /**
     * Number of times this job has been attempted.
     */
    public int $attempts = 0;

    /**
     * Maximum attempts before marking as failed.
     */
    public int $maxAttempts = 3;

    /**
     * Unique job ID in the database or queue store.
     */
    public int|string|null $id = null;

    /**
     * Delay in seconds before this job should be processed.
     */
    public int $delay = 0;

    /**
     * Instantiate the job with payload data.
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Execute the job's business logic.
     */
    abstract public function handle(): void;

    /**
     * Static dispatch helper: Queue::push(static::class, $data, $delay).
     *
     * Example:
     *   SendWelcomeEmailJob::dispatch(['user_id' => 42]);
     *   SendWelcomeEmailJob::dispatchLater(60, ['user_id' => 42]);
     */
    public static function dispatch(array $data = []): mixed
    {
        return Queue::push(static::class, $data);
    }

    /**
     * Dispatch the job with a delay in seconds.
     */
    public static function dispatchLater(int $delaySeconds, array $data = []): mixed
    {
        return Queue::later($delaySeconds, static::class, $data);
    }

    /**
     * Hook called if the job fails after exhausting all attempts.
     */
    public function failed(?\Throwable $exception = null): void
    {
        // Override in child job classes for cleanup or notifications
    }
}
