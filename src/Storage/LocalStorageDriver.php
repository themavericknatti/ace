<?php

namespace Ace\Storage;

use Exception;

/**
 * LocalStorageDriver — Filesystem storage driver operating on local disk directories.
 */
class LocalStorageDriver implements StorageDriverInterface
{
    protected string $root;
    protected string $baseUrl;

    public function __construct(string $root, string $baseUrl = '')
    {
        $this->root = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        $this->baseUrl = rtrim($baseUrl, '/');

        if (!is_dir($this->root)) {
            @mkdir($this->root, 0755, true);
        }
    }

    /**
     * Resolve and normalize absolute filesystem path inside the root boundary.
     */
    public function path(string $path = ''): string
    {
        $clean = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        return $clean === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $clean;
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->path($path);
        if (!is_file($fullPath)) {
            return null;
        }
        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    public function put(string $path, mixed $contents): bool
    {
        $fullPath = $this->path($path);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (is_resource($contents)) {
            $dest = fopen($fullPath, 'w');
            if (!$dest) {
                return false;
            }
            stream_copy_to_stream($contents, $dest);
            fclose($dest);
            return true;
        }

        return file_put_contents($fullPath, (string)$contents) !== false;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->path($path));
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    public function size(string $path): int
    {
        $fullPath = $this->path($path);
        return is_file($fullPath) ? (int)filesize($fullPath) : 0;
    }

    public function lastModified(string $path): int
    {
        $fullPath = $this->path($path);
        return is_file($fullPath) ? (int)filemtime($fullPath) : 0;
    }

    public function copy(string $from, string $to): bool
    {
        $src = $this->path($from);
        $dest = $this->path($to);

        if (!is_file($src)) {
            return false;
        }

        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return copy($src, $dest);
    }

    public function move(string $from, string $to): bool
    {
        $src = $this->path($from);
        $dest = $this->path($to);

        if (!file_exists($src)) {
            return false;
        }

        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return rename($src, $dest);
    }

    public function delete(string|array $paths): bool
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $allSuccess = true;

        foreach ($paths as $p) {
            $fullPath = $this->path($p);
            if (is_file($fullPath)) {
                if (!@unlink($fullPath)) {
                    $allSuccess = false;
                }
            }
        }

        return $allSuccess;
    }

    public function append(string $path, string $data): bool
    {
        $fullPath = $this->path($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($fullPath, $data, FILE_APPEND) !== false;
    }

    public function prepend(string $path, string $data): bool
    {
        $existing = $this->get($path) ?? '';
        return $this->put($path, $data . $existing);
    }

    public function files(string $directory = ''): array
    {
        $dir = $this->path($directory);
        if (!is_dir($dir)) {
            return [];
        }

        $results = [];
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_file($full)) {
                $relative = $directory === '' ? $item : trim($directory, '/\\') . '/' . $item;
                $results[] = str_replace('\\', '/', $relative);
            }
        }
        return $results;
    }

    public function allFiles(string $directory = ''): array
    {
        $dir = $this->path($directory);
        if (!is_dir($dir)) {
            return [];
        }

        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($this->root));
                $results[] = ltrim(str_replace('\\', '/', $relative), '/');
            }
        }

        return $results;
    }

    public function directories(string $directory = ''): array
    {
        $dir = $this->path($directory);
        if (!is_dir($dir)) {
            return [];
        }

        $results = [];
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $relative = $directory === '' ? $item : trim($directory, '/\\') . '/' . $item;
                $results[] = str_replace('\\', '/', $relative);
            }
        }
        return $results;
    }

    public function makeDirectory(string $path): bool
    {
        $dir = $this->path($path);
        if (is_dir($dir)) {
            return true;
        }
        return mkdir($dir, 0755, true);
    }

    public function deleteDirectory(string $directory): bool
    {
        $dir = $this->path($directory);
        if (!is_dir($dir)) {
            return true;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->deleteDirectory($directory . '/' . $item);
            } else {
                @unlink($full);
            }
        }

        return @rmdir($dir);
    }

    public function url(string $path): string
    {
        $clean = ltrim(str_replace('\\', '/', $path), '/');
        if (empty($this->baseUrl)) {
            return '/' . $clean;
        }
        return $this->baseUrl . '/' . $clean;
    }
}
