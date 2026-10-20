<?php

namespace Ace;

abstract class Service
{
    /**
     * Execute a callback inside an atomic database transaction.
     * Automatically rolls back on any Exception/Throwable.
     */
    protected function transaction(callable $callback): mixed
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return $callback();
        }
        return $db->transaction($callback);
    }

    /**
     * Dispatch an application event.
     */
    protected function dispatch(string $event, mixed ...$args): void
    {
        Event::dispatch($event, ...$args);
    }

    /**
     * Log an action or diagnostic information.
     */
    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        $logger = Application::$app->logger ?? null;
        if ($logger) {
            $logger->log($level, $message, $context);
        }
    }
}
