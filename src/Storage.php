<?php

namespace Ace;

use Ace\Storage\StorageManager;
use Ace\Storage\StorageDriverInterface;

/**
 * Storage — Static Facade for Ace Filesystem Storage Disks.
 *
 * Examples:
 *   Storage::put('avatars/1.jpg', $content);
 *   $content = Storage::get('avatars/1.jpg');
 *   $exists = Storage::exists('avatars/1.jpg');
 *   Storage::disk('public')->put('photos/hero.png', $photoData);
 *   $url = Storage::disk('public')->url('photos/hero.png');
 */
class Storage
{
    protected static ?StorageManager $manager = null;

    /**
     * Get or initialize the singleton StorageManager instance.
     */
    public static function getManager(): StorageManager
    {
        if (static::$manager === null) {
            static::$manager = new StorageManager();
        }
        return static::$manager;
    }

    /**
     * Set or reset the storage manager instance (useful for testing).
     */
    public static function setManager(?StorageManager $manager): void
    {
        static::$manager = $manager;
    }

    /**
     * Access a specific storage disk.
     */
    public static function disk(?string $name = null): StorageDriverInterface
    {
        return static::getManager()->disk($name);
    }

    /**
     * Dynamically proxy static calls to the default disk.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::disk()->$method(...$parameters);
    }
}
