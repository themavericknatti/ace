<?php

namespace Ace\Storage;

use Ace\Application;
use Exception;
use Closure;

/**
 * StorageManager — Manages storage disk drivers and custom driver extensions.
 */
class StorageManager
{
    protected array $disks = [];
    protected array $customDrivers = [];
    protected string $defaultDisk = 'local';

    public function __construct()
    {
        $this->defaultDisk = $_ENV['FILESYSTEM_DISK'] ?? 'local';
    }

    /**
     * Resolve a storage disk instance by name.
     */
    public function disk(?string $name = null): StorageDriverInterface
    {
        $name = $name ?: $this->defaultDisk;

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $this->disks[$name] = $this->resolve($name);
        return $this->disks[$name];
    }

    /**
     * Set default disk name.
     */
    public function setDefaultDisk(string $name): self
    {
        $this->defaultDisk = $name;
        return $this;
    }

    /**
     * Get default disk name.
     */
    public function getDefaultDisk(): string
    {
        return $this->defaultDisk;
    }

    /**
     * Register a custom storage driver creator closure.
     */
    public function extend(string $driver, Closure $callback): self
    {
        $this->customDrivers[$driver] = $callback;
        return $this;
    }

    /**
     * Resolve disk driver based on configuration or conventions.
     */
    protected function resolve(string $name): StorageDriverInterface
    {
        $config = $this->getConfig($name);
        $driver = $config['driver'] ?? $name;

        // Custom driver registered via extend()
        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($config);
        }

        $rootDir = Application::$ROOT_DIR ?? (dirname(__DIR__, 2));

        if ($driver === 'local' || $name === 'local') {
            $root = $config['root'] ?? ($rootDir . '/storage/app');
            $url = $config['url'] ?? '';
            return new LocalStorageDriver($root, $url);
        }

        if ($driver === 'public' || $name === 'public') {
            $root = $config['root'] ?? ($rootDir . '/storage/app/public');
            $url = $config['url'] ?? '/storage';
            return new LocalStorageDriver($root, $url);
        }

        throw new Exception("Unsupported filesystem driver [{$driver}] for disk [{$name}].");
    }

    /**
     * Get disk configuration from application config if available.
     */
    protected function getConfig(string $disk): array
    {
        if (isset(Application::$app) && isset(Application::$app->config['storage']['disks'][$disk])) {
            return Application::$app->config['storage']['disks'][$disk];
        }

        return [];
    }

    /**
     * Dynamic method proxy to default disk.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->disk()->$method(...$parameters);
    }
}
