<?php

namespace Ace;

class Response
{
    /**
     * Set HTTP response status code
     */
    public function setStatusCode(int $code): void
    {
        http_response_code($code);
    }

    /**
     * Fluent alias for setStatusCode
     */
    public function status(int $code): self
    {
        $this->setStatusCode($code);
        return $this;
    }

    /**
     * Set HTTP response header
     */
    public function setHeader(string $name, string $value): void
    {
        if (!headers_sent()) {
            header("$name: $value");
        }
    }

    /**
     * Fluent alias for setHeader
     */
    public function header(string $name, string $value): self
    {
        $this->setHeader($name, $value);
        return $this;
    }

    /**
     * Redirect to another URL, auto-detecting base subdirectories for local environment
     */
    public function redirect(string $url, int $statusCode = 302, array $flashData = []): void
    {
        $this->setStatusCode($statusCode);

        if (!empty($flashData)) {
            $session = Application::$app->session;
            if ($session) {
                foreach ($flashData as $key => $value) {
                    $session->setFlash($key, $value);
                }
            }
        }

        // If it's an absolute URL, redirect directly
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            if (!headers_sent()) {
                header('Location: ' . $url);
            }
            if (php_sapi_name() !== 'cli') {
                exit;
            }
            return;
        }

        // Prepend base folder for local subdirectory installations (e.g. /mvc/public)
        if (str_starts_with($url, '/')) {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $baseDir = dirname($scriptName);
            $baseDir = str_replace('\\', '/', $baseDir);
            
            if ($baseDir !== '/' && !empty($baseDir)) {
                if (str_ends_with($baseDir, '/public') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/public')) {
                    $baseDir = substr($baseDir, 0, -7);
                }
                $url = rtrim($baseDir, '/') . $url;
            }
        }

        if (!headers_sent()) {
            header('Location: ' . $url);
        }
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }

    /**
     * Return JSON response
     */
    public function json(array $data, int $statusCode = 200): void
    {
        $this->setStatusCode($statusCode);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }

    /**
     * Redirect back to the previous page using HTTP_REFERER.
     */
    public function back(int $statusCode = 302, array $flashData = [], string $fallback = '/'): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        $target = $referer ?: $fallback;
        $this->redirect($target, $statusCode, $flashData);
    }

    /**
     * Return a 204 No Content response.
     */
    public function noContent(): void
    {
        $this->setStatusCode(204);
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }

    /**
     * Return a file download response.
     */
    public function download(string $filePath, ?string $filename = null, array $headers = []): void
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found for download: {$filePath}", 404);
        }

        $filename = $filename ?? basename($filePath);
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        $size = filesize($filePath);

        $defaultHeaders = [
            'Content-Description' => 'File Transfer',
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
            'Content-Length' => (string)$size,
            'Expires' => '0',
            'Cache-Control' => 'must-revalidate',
            'Pragma' => 'public',
        ];

        foreach (array_merge($defaultHeaders, $headers) as $name => $value) {
            $this->setHeader($name, $value);
        }

        // Flush any output buffers before streaming file
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($filePath);
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }

    /**
     * Return an inline file display response (e.g. for viewing PDFs/images in browser).
     */
    public function file(string $filePath, array $headers = []): void
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}", 404);
        }

        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        $size = filesize($filePath);

        $defaultHeaders = [
            'Content-Type' => $mime,
            'Content-Length' => (string)$size,
            'Content-Disposition' => 'inline; filename="' . addslashes(basename($filePath)) . '"',
        ];

        foreach (array_merge($defaultHeaders, $headers) as $name => $value) {
            $this->setHeader($name, $value);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($filePath);
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }
}

