<?php

namespace Ace\Storage;

/**
 * StorageDriverInterface — Contract for all Ace Framework filesystem storage drivers.
 */
interface StorageDriverInterface
{
    /**
     * Retrieve the contents of a file.
     */
    public function get(string $path): ?string;

    /**
     * Write contents to a file (creates parent directories if needed).
     */
    public function put(string $path, mixed $contents): bool;

    /**
     * Check if a file exists.
     */
    public function exists(string $path): bool;

    /**
     * Check if a file does not exist.
     */
    public function missing(string $path): bool;

    /**
     * Get the size of a file in bytes.
     */
    public function size(string $path): int;

    /**
     * Get the last modified UNIX timestamp of a file.
     */
    public function lastModified(string $path): int;

    /**
     * Copy a file from one path to another.
     */
    public function copy(string $from, string $to): bool;

    /**
     * Move a file from one path to another.
     */
    public function move(string $from, string $to): bool;

    /**
     * Delete one or multiple files.
     */
    public function delete(string|array $paths): bool;

    /**
     * Append data to the end of a file.
     */
    public function append(string $path, string $data): bool;

    /**
     * Prepend data to the beginning of a file.
     */
    public function prepend(string $path, string $data): bool;

    /**
     * List all direct files within a directory.
     */
    public function files(string $directory = ''): array;

    /**
     * Recursively list all files within a directory.
     */
    public function allFiles(string $directory = ''): array;

    /**
     * List direct subdirectories.
     */
    public function directories(string $directory = ''): array;

    /**
     * Create a directory.
     */
    public function makeDirectory(string $path): bool;

    /**
     * Recursively delete a directory and all its contents.
     */
    public function deleteDirectory(string $directory): bool;

    /**
     * Get the absolute filesystem path for a relative disk path.
     */
    public function path(string $path): string;

    /**
     * Get the public URL for an asset path.
     */
    public function url(string $path): string;
}
