<?php

namespace Ace\Middlewares;

use Ace\Application;
use Ace\Middleware;

class RateLimitMiddleware extends Middleware
{
    public function __construct(
        private int $limit = 60,
        private int $window = 60,
        private ?string $prefix = null
    ) {
    }

    public static function per(int $limit, int $window = 60, ?string $prefix = null): self
    {
        return new self($limit, $window, $prefix);
    }

    protected function run(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = ($this->prefix ? $this->prefix . ':' : '') . $ip;
        $cacheDir = Application::$ROOT_DIR . '/storage/cache';
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        
        $cacheFile = $cacheDir . '/rate_limits.json';
        $data = [];
        
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true) ?? [];
        }

        $now = time();
        
        // Cleanup old entries
        foreach ($data as $cacheIp => $info) {
            if ($now - $info['time'] > $this->window) {
                unset($data[$cacheIp]);
            }
        }

        if (isset($data[$key])) {
            $data[$key]['count']++;
            if ($data[$key]['count'] > $this->limit) {
                // Save state and block request
                file_put_contents($cacheFile, json_encode($data));
                throw new \Exception("Too Many Requests", 429);
            }
        } else {
            $data[$key] = [
                'count' => 1,
                'time' => $now
            ];
        }

        file_put_contents($cacheFile, json_encode($data));
    }
}
