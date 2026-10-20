<?php

namespace Ace;

class Event
{
    /**
     * @var array Registered event listeners
     */
    protected static array $listeners = [];

    /**
     * Register a listener for a specific event.
     *
     * @param string $event Event name (e.g. 'user.registered')
     * @param callable|string $listener Callback or fully qualified class name of a listener
     */
    public static function listen(string $event, callable|string $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event and run all registered listeners.
     *
     * @param string $event Event name
     * @param mixed ...$payload Arguments passed to the listener
     * @return array Array of responses returned by each listener
     */
    public static function dispatch(string $event, mixed ...$payload): array
    {
        $responses = [];

        if (!isset(self::$listeners[$event])) {
            return $responses;
        }

        foreach (self::$listeners[$event] as $listener) {
            if (is_callable($listener)) {
                $responses[] = $listener(...$payload);
            } elseif (is_string($listener)) {
                // Resolve from container
                if (class_exists($listener)) {
                    $instance = Application::$app->container->resolve($listener);
                    
                    if (method_exists($instance, 'handle')) {
                        $responses[] = $instance->handle(...$payload);
                    } else {
                        Logger::error("Listener class '{$listener}' must implement a 'handle()' method.");
                    }
                } else {
                    Logger::error("Listener class '{$listener}' does not exist.");
                }
            }
        }

        return $responses;
    }

    /**
     * Clear all registered listeners (primarily for testing purposes).
     */
    public static function clearListeners(): void
    {
        self::$listeners = [];
    }
}
