<?php

use Ace\Application;

if (!function_exists('app')) {
    /**
     * Get the application instance.
     */
    function app(): Application
    {
        return Application::$app;
    }
}

if (!function_exists('env')) {
    /**
     * Get environment variables with type casting and fallbacks.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null) {
            return $default;
        }

        // Handle common boolean & null string conversions
        switch (strtolower((string)$val)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        return $val;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration variables using dot notation.
     */
    function config(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $config = Application::$app->config;
        foreach ($keys as $k) {
            if (is_array($config) && array_key_exists($k, $config)) {
                $config = $config[$k];
            } else {
                return $default;
            }
        }
        return $config;
    }
}

if (!function_exists('get_setting')) {
    /**
     * Alias for setting() - Get a setting from config/settings.json
     */
    function get_setting(string $key, mixed $default = null): mixed
    {
        return setting($key, $default);
    }
}

if (!function_exists('get_currency_symbol')) {
    /**
     * Get currency symbol based on active currency setting
     */
    function get_currency_symbol(?string $currencyCode = null): string
    {
        $currency = strtoupper($currencyCode ?: setting('currency', 'NGN'));
        return match ($currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'NGN', 'BOTH' => '₦',
            default => setting("currency_symbols.{$currency}", $currency . ' '),
        };
    }
}

if (!function_exists('view')) {
    /**
     * Render a view template.
     *
     * @param string $view View name (e.g. 'shop.products.index')
     * @param array $params Data passed to the view
     * @param string|null $template Optional explicit template override
     */
    function view(string $view, array $params = [], ?string $template = null): string
    {
        if ($template !== null && isset(Application::$app->templates)) {
            Application::$app->templates->setOverride($template);
        }
        return Application::$app->view->render($view, $params);
    }
}

if (!function_exists('template')) {
    /**
     * Get the TemplateManager instance or the active template name.
     */
    function template(?string $name = null): mixed
    {
        $manager = Application::$app->templates ?? null;
        if (!$manager) {
            return $name ?: 'minimal';
        }
        if ($name !== null) {
            $manager->set($name);
            return $manager;
        }
        return $manager;
    }
}

if (!function_exists('active_template')) {
    /**
     * Get the active visual template identifier.
     */
    function active_template(): string
    {
        $manager = Application::$app->templates ?? null;
        return $manager ? $manager->resolve() : 'minimal';
    }
}

if (!function_exists('template_asset')) {
    /**
     * Generate URL for an asset specific to the active visual template.
     * Looks up /assets/templates/{active}/{path} or falls back to /assets/{path}.
     */
    function template_asset(string $path): string
    {
        $active = active_template();
        $cleanPath = ltrim($path, '/');
        $templateAssetPath = Application::$ROOT_DIR . '/public/assets/templates/' . $active . '/' . $cleanPath;
        if (file_exists($templateAssetPath)) {
            return asset('assets/templates/' . $active . '/' . $cleanPath);
        }
        return asset($cleanPath);
    }
}

if (!function_exists('idempotency')) {
    /**
     * Get the IdempotencyManager instance or execute a callback with idempotency guarantee.
     *
     * Example:
     *   $result = idempotency()->check($key, $scope, $hash);
     */
    function idempotency(): \Ace\IdempotencyManager
    {
        return new \Ace\IdempotencyManager();
    }
}



if (!function_exists('paginate_links')) {
    /**
     * Render pagination HTML links for a Paginator instance or array.
     *
     * @param mixed $paginator Paginator instance or array with pagination data
     * @param array $options Styling and display options (theme, classes, styles, etc.)
     * @return string
     */
    function paginate_links(mixed $paginator, array $options = []): string
    {
        if ($paginator instanceof \Ace\Paginator) {
            return $paginator->render($options);
        }
        if (is_array($paginator)) {
            $items = $paginator['data'] ?? $paginator['items'] ?? [];
            $total = $paginator['total'] ?? count($items);
            $perPage = $paginator['per_page'] ?? count($items);
            $currentPage = $paginator['current_page'] ?? 1;
            $p = new \Ace\Paginator($items, $total, $perPage, $currentPage, $options);
            return $p->render($options);
        }
        return '';
    }
}

if (!function_exists('paginator')) {
    /**
     * Create a new Paginator instance.
     *
     * @param array $items Items on current page
     * @param int $total Total items in dataset
     * @param int $perPage Items per page
     * @param int|null $currentPage Current page (null to auto-detect from request)
     * @param array $options Extra options (path, pageName, query, etc.)
     * @return \Ace\Paginator
     */
    function paginator(array $items, int $total, int $perPage = 15, ?int $currentPage = null, array $options = []): \Ace\Paginator
    {
        return new \Ace\Paginator($items, $total, $perPage, $currentPage, $options);
    }
}

if (!function_exists('request')) {
    /**
     * Get the request instance or an input item from the request.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = Application::$app->request ?? null;
        if ($key === null) {
            return $request;
        }
        return $request ? $request->get($key, $default) : $default;
    }
}

if (!function_exists('css_framework')) {
    /**
     * Render the configured or specified CSS framework CDN tags.
     * Supports 'tailwind', 'bootstrap', or 'none'. Zero npm required.
     *
     * @param string|null $framework Framework name ('tailwind', 'bootstrap', or null for default)
     * @return string
     */
    function css_framework(?string $framework = null): string
    {
        return \Ace\View::renderCssFramework($framework);
    }
}

if (!function_exists('tailwind_cdn')) {
    /**
     * Render the official Tailwind CSS Play CDN script tag.
     *
     * @return string
     */
    function tailwind_cdn(): string
    {
        return \Ace\View::renderTailwind();
    }
}

if (!function_exists('bootstrap_cdn')) {
    /**
     * Render the official Bootstrap 5.3 CSS and JS Bundle CDN tags.
     *
     * @return string
     */
    function bootstrap_cdn(): string
    {
        return \Ace\View::renderBootstrap();
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect to a given URL path with optional status code and session flash data.
     */
    function redirect(string $url, int $statusCode = 302, array $flashData = []): void
    {
        Application::$app->response->redirect($url, $statusCode, $flashData);
    }
}

if (!function_exists('url')) {
    /**
     * Generate an absolute or relative URL.
     */
    function url(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $path = ltrim($path, '/');
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . $host . '/' . $path;
    }
}

if (!function_exists('route')) {
    /**
     * Generate relative URL path for route linking.
     *
     * @param string $name Route name (e.g. 'events.id' or 'login') or direct path
     * @param array|string|int $params Route parameters or query parameters
     * @return string
     */
    function route(string $name, array|string|int $params = []): string
    {
        $router = Application::$app->router;
        $resolvedPath = $router->resolveRouteName($name);

        if ($resolvedPath !== null) {
            $path = $resolvedPath;
        } else {
            // Fallback for direct paths
            $path = $name;
        }

        // Normalize scalar parameter to array if there's a dynamic parameter
        if (!is_array($params)) {
            preg_match_all('/\{([a-zA-Z0-9_]+)\??\}/', $path, $matches);
            if (!empty($matches[1])) {
                $params = [$matches[1][0] => $params];
            } else {
                $params = [];
            }
        }

        $queryParams = [];
        foreach ($params as $key => $val) {
            // Try both optional {key?} and required {key} placeholders
            $optionalPlaceholder = '{' . $key . '?}';
            $requiredPlaceholder = '{' . $key . '}';

            if (str_contains($path, $optionalPlaceholder)) {
                $path = str_replace($optionalPlaceholder, (string) $val, $path);
            } elseif (str_contains($path, $requiredPlaceholder)) {
                $path = str_replace($requiredPlaceholder, (string) $val, $path);
            } else {
                $queryParams[$key] = $val;
            }
        }

        // Remove any remaining unfilled optional placeholders (e.g. /{id?} with no value)
        $path = preg_replace('#/\{[a-zA-Z0-9_]+\?\}#', '', $path);

        if (!empty($queryParams)) {
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($queryParams);
        }

        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $basePath = str_replace('\\', '/', $basePath);
        if (str_ends_with($basePath, '/public') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/public')) {
            $basePath = substr($basePath, 0, -7);
        }
        return $basePath . '/' . ltrim($path, '/');
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get the CSRF token from the session.
     */
    function csrf_token(): string
    {
        return Application::$app->session->getCsrfToken();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate a CSRF token hidden input field.
     */
    function csrf_field(): string
    {
        $token = csrf_token();
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
}

if (!function_exists('method_field')) {
    /**
     * Generate an HTTP method spoofing hidden input field.
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . strtoupper($method) . '">';
    }
}

if (!function_exists('user')) {
    /**
     * Get the currently authenticated user, or null if guest.
     */
    function user(): ?\Ace\Model
    {
        return Application::$app->user;
    }
}

if (!function_exists('user_first_name')) {
    /**
     * Get the first name of the currently authenticated user, or 'Profile' if guest or unnamed.
     * Optionally caps the output to $maxLen characters.
     */
    function user_first_name(int $maxLen = 0): string
    {
        $user = Application::$app->user;
        if (!$user || empty($user->name)) {
            return $maxLen > 0 ? mb_substr('Profile', 0, $maxLen) : 'Profile';
        }
        $parts = explode(' ', trim($user->name));
        $name = $parts[0] ?: 'Profile';
        if ($maxLen > 0 && mb_strlen($name) > $maxLen) {
            return mb_substr($name, 0, $maxLen);
        }
        return $name;
    }
}

if (!function_exists('hasRole')) {
    /**
     * Check if the current user has a specific role.
     *
     * Usage in views: @if(hasRole('admin')) ... @endif
     */
    function hasRole(string $slug): bool
    {
        $user = Application::$app->user;
        if (!$user) {
            return false;
        }

        // Prefer user->hasRole if available
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole($slug);
        }

        // Fallback: check roles property (array of slugs) if present
        if (property_exists($user, 'roles') && is_array($user->roles)) {
            return in_array($slug, $user->roles, true);
        }

        return false;
    }
}

if (!function_exists('hasPermission')) {
    /**
     * Check if the current user has a specific permission.
     *
     * Usage in views: @if(hasPermission('edit-posts')) ... @endif
     */
    function hasPermission(string $slug): bool
    {
        $user = Application::$app->user;
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($slug);
        }

        // Fallback: check permissions property (array of slugs) if present
        if (property_exists($user, 'permissions') && is_array($user->permissions)) {
            return in_array($slug, $user->permissions, true);
        }

        return false;
    }
}

if (!function_exists('xss_clean')) {
    /**
     * Clean dynamic variables against XSS attacks.
     */
    function xss_clean(mixed $value): mixed
    {
        return \Ace\XssSanitizer::clean($value);
    }
}

if (!function_exists('guard')) {
    /**
     * Sanitize a value for safe database storage (Input Guard).
     *
     *   $clean = guard($userInput);
     */
    function guard(mixed $value): mixed
    {
        return \Ace\Guard::input($value);
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for safe HTML output (Output Guard).
     *
     *   <p><?= e($name) ?></p>
     */
    function e(mixed $value): string
    {
        return \Ace\Guard::output($value);
    }
}

if (!function_exists('asset')) {
    /**
     * Generate URL for a static asset located in public/assets.
     *
     *   <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
     */
    function asset(string $path): string
    {
        $clean = ltrim($path, '/\\');
        if (str_starts_with($clean, 'assets/')) {
            return route($clean);
        }
        return route('assets/' . $clean);
    }
}

if (!function_exists('upload')) {
    /**
     * Generate URL for a user upload file located in public/uploads.
     *
     *   <img src="<?= upload('avatars/user.png') ?>">
     */
    function upload(string $path): string
    {
        return route('uploads/' . ltrim($path, '/'));
    }
}

if (!function_exists('public_path')) {
    /**
     * Generate URL for any file located in the public directory root.
     *
     *   <link rel="shortcut icon" href="<?= public_path('favicon.ico') ?>">
     */
    function public_path(string $path = ''): string
    {
        return route($path);
    }
}

if (!function_exists('public_dir')) {
    /**
     * Get the absolute filesystem path to the public directory on disk.
     * Automatically adapts between:
     * - local development (where assets are in public/)
     * - cPanel/shared hosting production (where the whole app loads from public_html/)
     * - web server DOCUMENT_ROOT
     * - direct root directory /
     */
    function public_dir(string $path = ''): string
    {
        $clean = ltrim($path, '/\\');
        $root = (class_exists('\Ace\Application', false) && isset(\Ace\Application::$ROOT_DIR))
            ? \Ace\Application::$ROOT_DIR
            : (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__));

        if (defined('ACE_PUBLIC_DIR') && is_dir(ACE_PUBLIC_DIR)) {
            $base = ACE_PUBLIC_DIR;
        } elseif (is_dir($root . '/public/assets') || is_dir($root . '/public')) {
            $base = $root . '/public';
        } elseif (is_dir($root . '/public_html/assets') || is_dir($root . '/public_html')) {
            $base = $root . '/public_html';
        } elseif (!empty($_SERVER['DOCUMENT_ROOT']) && (is_dir($_SERVER['DOCUMENT_ROOT'] . '/assets') || is_dir($_SERVER['DOCUMENT_ROOT']))) {
            $base = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
        } elseif (is_dir($root . '/assets')) {
            // Assets directory directly in application root /
            $base = $root;
        } else {
            // Fallback to application root /
            $base = $root;
        }

        return $clean !== '' ? $base . '/' . $clean : $base;
    }
}

if (!function_exists('asset_version')) {
    /**
     * Get cache-busting timestamp version for any public asset file.
     * Resolves the real file path using public_dir() so it works seamlessly in
     * local dev (public/), production (public_html/), DOCUMENT_ROOT, etc.
     */
    function asset_version(string $assetPath, string $default = '1.0.0'): string
    {
        $clean = ltrim($assetPath, '/\\');
        $filePath = public_dir($clean);
        if (file_exists($filePath)) {
            return (string) filemtime($filePath);
        }
        if (str_starts_with($clean, 'assets/')) {
            $alt = public_dir(substr($clean, 7));
            if (file_exists($alt)) {
                return (string) filemtime($alt);
            }
        } else {
            $alt = public_dir('assets/' . $clean);
            if (file_exists($alt)) {
                return (string) filemtime($alt);
            }
        }
        return $default;
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and Die — Premium debug dump with rich metadata.
     * Accepts multiple arguments: dd($user, $request, $config);
     */
    function dd(mixed ...$vars): void
    {
        // Get caller details
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $caller = $backtrace[0] ?? [];
        $file = $caller['file'] ?? 'Unknown';
        $line = $caller['line'] ?? 0;

        // Clean up file path for display
        $displayFile = str_replace('\\', '/', $file);
        if (defined('ROOT_DIR')) {
            $displayFile = str_replace(str_replace('\\', '/', ROOT_DIR) . '/', '', $displayFile);
        }

        $isCli = php_sapi_name() === 'cli';
        $isDev = (($_ENV['APP_ENV'] ?? 'development') === 'development');
        $varCount = count($vars);
        $memUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
        $peakMem = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $timestamp = date('Y-m-d H:i:s');

        // CLI mode — enhanced terminal output
        if ($isCli) {
            echo "\n\033[1;33m⚡ Ace Debug Dump (dd)\033[0m\n";
            echo "\033[90m   File: {$displayFile}:{$line}\033[0m\n";
            echo "\033[90m   Time: {$timestamp} | Memory: {$memUsage}MB (peak: {$peakMem}MB)\033[0m\n";
            echo "\033[90m   " . str_repeat('─', 60) . "\033[0m\n";
            foreach ($vars as $i => $var) {
                if ($varCount > 1) {
                    echo "\033[1;36m   #{$i} \033[90m(" . _ace_dd_type_label($var) . ")\033[0m\n";
                }
                var_dump($var);
                echo "\n";
            }
            exit(1);
        }

        // Clear output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
        }

        // Request context
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'N/A';
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'N/A';
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        $phpVersion = PHP_VERSION;

        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡ Ace DD</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:#0b1120;color:#e2e8f0;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;padding:24px;min-height:100vh;line-height:1.6}
        .dd-wrap{max-width:1200px;margin:0 auto}
        .dd-prod-warn{background:linear-gradient(135deg,#7f1d1d,#991b1b);color:#fca5a5;padding:12px 24px;text-align:center;font-weight:700;font-size:13px;border-radius:12px 12px 0 0;letter-spacing:.5px}
        .dd-card{background:#141c2e;border-radius:12px;box-shadow:0 20px 60px -15px rgba(0,0,0,.6);border:1px solid #1e293b;overflow:hidden;margin-bottom:24px}
        .dd-header{background:linear-gradient(135deg,#0f172a 0%,#1a1f3a 100%);padding:18px 24px;border-bottom:1px solid #1e293b;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
        .dd-brand{display:flex;align-items:center;gap:10px}
        .dd-brand-icon{font-size:20px}
        .dd-brand-text{font-weight:800;font-size:16px;background:linear-gradient(135deg,#38bdf8,#818cf8);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .dd-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .dd-badge{font-size:11px;padding:4px 10px;border-radius:6px;font-weight:600;border:1px solid}
        .dd-badge-file{color:#38bdf8;border-color:#1e3a5f;background:rgba(56,189,248,.08)}
        .dd-badge-count{color:#a78bfa;border-color:#3b2f63;background:rgba(167,139,250,.08)}
        .dd-badge-mem{color:#34d399;border-color:#1a3a2a;background:rgba(52,211,153,.08)}
        .dd-context{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1px;background:#1e293b;border-bottom:1px solid #1e293b}
        .dd-ctx-item{background:#111827;padding:10px 20px;font-size:12px}
        .dd-ctx-label{color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:1px;font-size:10px;margin-bottom:2px}
        .dd-ctx-val{color:#cbd5e1}
        .dd-body{padding:20px 24px}
        .dd-item{margin-bottom:16px;background:#0f172a;border:1px solid #1e293b;border-radius:10px;overflow:hidden}
        .dd-item:last-child{margin-bottom:0}
        .dd-item-header{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#0d1425;border-bottom:1px solid #1e293b;cursor:default}
        .dd-item-idx{color:#64748b;font-size:11px;font-weight:700;min-width:24px}
        .dd-type-badge{font-size:10px;padding:3px 8px;border-radius:4px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
        .dd-type-string{color:#34d399;background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.2)}
        .dd-type-integer,.dd-type-float,.dd-type-double{color:#38bdf8;background:rgba(56,189,248,.12);border:1px solid rgba(56,189,248,.2)}
        .dd-type-boolean{color:#fb923c;background:rgba(251,146,60,.12);border:1px solid rgba(251,146,60,.2)}
        .dd-type-null{color:#94a3b8;background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.2)}
        .dd-type-array{color:#a78bfa;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.2)}
        .dd-type-object{color:#f472b6;background:rgba(244,114,182,.12);border:1px solid rgba(244,114,182,.2)}
        .dd-type-resource{color:#facc15;background:rgba(250,204,21,.12);border:1px solid rgba(250,204,21,.2)}
        .dd-item-size{color:#475569;font-size:11px;margin-left:auto}
        .dd-item-body{padding:14px 16px;overflow-x:auto}
        .dd-item-body pre{margin:0;font-size:13px;line-height:1.7;white-space:pre-wrap;word-break:break-word}
        .dd-toggle{cursor:pointer;user-select:none;color:#475569;font-size:11px;transition:color .15s}.dd-toggle:hover{color:#94a3b8}
        .dd-collapsed{display:none}
        .dd-key-int{color:#f43f5e}.dd-key-str{color:#e2e8f0}
        .dd-val-str{color:#34d399}.dd-val-num{color:#38bdf8}.dd-val-bool{color:#fb923c;font-weight:700}.dd-val-null{color:#64748b;font-weight:700}
        .dd-val-obj{color:#f472b6;font-weight:700}.dd-val-res{color:#facc15;font-weight:700}
        .dd-strlen{color:#475569;font-size:11px;font-style:italic}
        .dd-arrow{color:#334155}
        .dd-copy{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.06);border:1px solid #334155;color:#94a3b8;padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer;font-family:inherit;transition:all .15s}.dd-copy:hover{background:rgba(255,255,255,.12);color:#e2e8f0}
        .dd-item-body-wrap{position:relative}
        .dd-footer{padding:12px 24px;border-top:1px solid #1e293b;text-align:center;font-size:11px;color:#334155}
    </style>
</head>
<body>
<div class="dd-wrap">';

        if (!$isDev) {
            echo '<div class="dd-prod-warn">⚠️ WARNING: Debug dump active in production! Remove all dd() calls before deploying.</div>';
        }

        echo '<div class="dd-card">
    <div class="dd-header">
        <div class="dd-brand">
            <span class="dd-brand-icon">⚡</span>
            <span class="dd-brand-text">Ace Debug Dump</span>
        </div>
        <div class="dd-meta">
            <span class="dd-badge dd-badge-file">' . htmlspecialchars($displayFile) . ':' . $line . '</span>
            <span class="dd-badge dd-badge-count">' . $varCount . ' variable' . ($varCount !== 1 ? 's' : '') . '</span>
            <span class="dd-badge dd-badge-mem">' . $memUsage . 'MB / ' . $peakMem . 'MB peak</span>
        </div>
    </div>
    <div class="dd-context">
        <div class="dd-ctx-item"><div class="dd-ctx-label">Method</div><div class="dd-ctx-val">' . htmlspecialchars($requestMethod) . '</div></div>
        <div class="dd-ctx-item"><div class="dd-ctx-label">URI</div><div class="dd-ctx-val">' . htmlspecialchars($requestUri) . '</div></div>
        <div class="dd-ctx-item"><div class="dd-ctx-label">IP</div><div class="dd-ctx-val">' . htmlspecialchars($clientIp) . '</div></div>
        <div class="dd-ctx-item"><div class="dd-ctx-label">Time</div><div class="dd-ctx-val">' . $timestamp . '</div></div>
        <div class="dd-ctx-item"><div class="dd-ctx-label">PHP</div><div class="dd-ctx-val">' . $phpVersion . '</div></div>
    </div>
    <div class="dd-body">';

        foreach ($vars as $i => $var) {
            $typeLabel = _ace_dd_type_label($var);
            $typeCss = strtolower(str_replace(' ', '-', $typeLabel));
            $sizeInfo = _ace_dd_size_info($var);
            $uid = 'dd_' . $i . '_' . mt_rand(1000, 9999);

            echo '<div class="dd-item">
                <div class="dd-item-header">
                    <span class="dd-item-idx">#' . $i . '</span>
                    <span class="dd-type-badge dd-type-' . $typeCss . '">' . $typeLabel . '</span>
                    ' . ($sizeInfo ? '<span class="dd-item-size">' . $sizeInfo . '</span>' : '') . '
                </div>
                <div class="dd-item-body-wrap">
                    <button class="dd-copy" onclick="let t=this.closest(\'.dd-item-body-wrap\').querySelector(\'pre\').innerText;navigator.clipboard.writeText(t);this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy\',1500)">Copy</button>
                    <div class="dd-item-body"><pre>' . _ace_dd_format($var, 0, $uid) . '</pre></div>
                </div>
            </div>';
        }

        echo '</div>
    <div class="dd-footer">Ace Framework • Debug Dump • ' . $timestamp . '</div>
</div></div>
<script>
function aceToggle(id){var e=document.getElementById(id);var a=document.getElementById(id+"_arrow");if(e){if(e.classList.contains("dd-collapsed")){e.classList.remove("dd-collapsed");if(a)a.textContent="▼"}else{e.classList.add("dd-collapsed");if(a)a.textContent="▶"}}}
</script>
</body></html>';
        exit(1);
    }
}

if (!function_exists('_ace_dd_type_label')) {
    /**
     * Get a human-readable type label for a variable.
     */
    function _ace_dd_type_label(mixed $var): string
    {
        if ($var === null)
            return 'null';
        if (is_bool($var))
            return 'boolean';
        if (is_int($var))
            return 'integer';
        if (is_float($var))
            return 'float';
        if (is_string($var))
            return 'string';
        if (is_array($var))
            return 'array';
        if (is_object($var))
            return 'object';
        if (is_resource($var))
            return 'resource';
        return 'unknown';
    }
}

if (!function_exists('_ace_dd_size_info')) {
    /**
     * Get size/length info for a variable.
     */
    function _ace_dd_size_info(mixed $var): string
    {
        if (is_string($var))
            return strlen($var) . ' chars';
        if (is_array($var))
            return count($var) . ' items';
        if (is_object($var))
            return count((array) $var) . ' properties';
        return '';
    }
}

if (!function_exists('_ace_dd_format')) {
    /**
     * Recursively format a variable for the debug dump with collapsible sections.
     */
    function _ace_dd_format(mixed $var, int $depth = 0, string $uid = ''): string
    {
        $indent = str_repeat('  ', $depth);
        $maxDepth = 10;

        if ($depth > $maxDepth) {
            return '<span class="dd-val-null">… (max depth reached)</span>';
        }

        if ($var === null) {
            return '<span class="dd-val-null">null</span>';
        }
        if (is_bool($var)) {
            return '<span class="dd-val-bool">' . ($var ? 'true' : 'false') . '</span>';
        }
        if (is_int($var) || is_float($var)) {
            return '<span class="dd-val-num">' . $var . '</span>';
        }
        if (is_string($var)) {
            $display = htmlspecialchars($var);
            // Truncate very long strings visually
            $len = strlen($var);
            if ($len > 500) {
                $display = htmlspecialchars(substr($var, 0, 500)) . '<span class="dd-strlen">… truncated</span>';
            }
            return '<span class="dd-val-str">"' . $display . '"</span> <span class="dd-strlen">(length=' . $len . ')</span>';
        }
        if (is_array($var)) {
            if (empty($var)) {
                return '<span class="dd-val-null">[] <span class="dd-strlen">(empty)</span></span>';
            }
            $count = count($var);
            $toggleId = $uid . '_arr_' . $depth . '_' . mt_rand(100, 999);
            $output = '<span class="dd-type-badge dd-type-array" style="font-size:11px;vertical-align:middle">array:' . $count . '</span> ';
            $output .= '<span class="dd-toggle" id="' . $toggleId . '_arrow" onclick="aceToggle(\'' . $toggleId . '\')">▼</span>' . "\n";
            $output .= '<span id="' . $toggleId . '">';
            foreach ($var as $key => $val) {
                $fKey = is_int($key)
                    ? '<span class="dd-key-int">' . $key . '</span>'
                    : '<span class="dd-key-str">"' . htmlspecialchars($key) . '"</span>';
                $output .= $indent . '  ' . $fKey . ' <span class="dd-arrow">=></span> ' . _ace_dd_format($val, $depth + 1, $toggleId . '_' . $key) . "\n";
            }
            $output .= '</span>';
            return $output;
        }
        if (is_object($var)) {
            $className = get_class($var);
            $props = (array) $var;
            if (empty($props)) {
                return '<span class="dd-val-obj">' . $className . '</span> <span class="dd-strlen">{}</span>';
            }
            $toggleId = $uid . '_obj_' . $depth . '_' . mt_rand(100, 999);
            $output = '<span class="dd-val-obj">' . htmlspecialchars($className) . '</span> ';
            $output .= '<span class="dd-toggle" id="' . $toggleId . '_arrow" onclick="aceToggle(\'' . $toggleId . '\')">▼</span>' . "\n";
            $output .= '<span id="' . $toggleId . '">';
            foreach ($props as $key => $val) {
                // Decode PHP's internal property naming
                $visibility = '';
                if (str_contains($key, "\0*\0")) {
                    $key = str_replace("\0*\0", '', $key);
                    $visibility = '<span class="dd-strlen">#protected</span> ';
                } elseif (str_contains($key, "\0")) {
                    $key = preg_replace('/\0[^\0]+\0/', '', $key);
                    $visibility = '<span class="dd-strlen">-private</span> ';
                }
                $fKey = '<span class="dd-key-str">' . htmlspecialchars($key) . '</span>';
                $output .= $indent . '  ' . $visibility . $fKey . ' <span class="dd-arrow">=></span> ' . _ace_dd_format($val, $depth + 1, $toggleId . '_' . $key) . "\n";
            }
            $output .= '</span>';
            return $output;
        }
        if (is_resource($var)) {
            return '<span class="dd-val-res">resource(' . get_resource_type($var) . ')</span>';
        }
        return htmlspecialchars(print_r($var, true));
    }
}

if (!function_exists('back')) {
    /**
     * Redirect back to the previous page (HTTP Referer) or a fallback URL.
     *
     * @param string $fallback Fallback URL path if Referer is missing or external
     * @param int $statusCode HTTP status code (default 302)
     * @param array $flashData Session flash messages to carry over
     */
    function back(string $fallback = '/', int $statusCode = 302, array $flashData = []): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;

        // Prevent open redirect vulnerabilities: Ensure referrer is internal
        $appUrl = env('APP_URL', '');
        if (!empty($appUrl)) {
            $host = parse_url($appUrl, PHP_URL_HOST);
            $refererHost = parse_url($referer, PHP_URL_HOST);

            // If the referer points to an external host, fallback to safe internal route
            if ($refererHost && $refererHost !== $host) {
                $referer = $fallback;
            }
        }

        redirect($referer, $statusCode, $flashData);
    }
}

if (!function_exists('flash_input')) {
    /**
     * Flash current request input data to the session.
     *
     * @param array|null $data The input data to flash (defaults to current request body)
     */
    function flash_input(?array $data = null): void
    {
        $request = Application::$app->request;
        $session = Application::$app->session;
        if ($request && $session) {
            $data = $data ?? $request->getBody();
            // Exclude sensitive fields from flashing
            $sensitiveFields = ['password', 'passwordConfirm', 'csrf_token'];
            foreach ($sensitiveFields as $field) {
                unset($data[$field]);
            }
            $session->setFlash('_old_input', $data);
        }
    }
}

if (!function_exists('old')) {
    /**
     * Retrieve the previous input value for a form field.
     * Checks both session-flashed old input and current request body.
     *
     * @param string $key The input field name
     * @param mixed $default Default value if field is missing
     * @return mixed
     */
    function old(string $key, mixed $default = null): mixed
    {
        // 1. Check session flashed old input
        $session = Application::$app->session;
        if ($session) {
            $oldInput = $session->getFlash('_old_input');
            if (is_array($oldInput) && isset($oldInput[$key])) {
                return $oldInput[$key];
            }
        }

        // 2. Check current request body input (in case of direct render on validation failure)
        $request = Application::$app->request;
        if ($request) {
            $body = $request->getBody();
            if (is_array($body) && isset($body[$key])) {
                return $body[$key];
            }
        }

        return $default;
    }
}

if (!function_exists('setting')) {
    /**
     * Get a setting from config/settings.json (with request-level caching and dot-notation support).
     */
    function setting(string $key, mixed $default = null): mixed
    {
        static $settings = null;

        if ($settings === null) {
            $settingsFile = Application::$ROOT_DIR . '/config/settings.json';
            if (file_exists($settingsFile)) {
                $content = @file_get_contents($settingsFile);
                $settings = json_decode($content, true);
                if (!is_array($settings)) {
                    $settings = [];
                }
            } else {
                $settings = [];
            }
        }

        // Support dot-notation access (e.g. setting('payment.provider'))
        $data = $settings;
        foreach (explode('.', $key) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return $default;
            }
        }

        return $data;
    }
}

if (!function_exists('format_price')) {
    /**
     * Format a price according to active currency settings.
     * Prevents division-by-zero errors, supports multiple currencies, and runs optimally.
     */
    function format_price(float|int $amount, ?string $currencyCode = null): string
    {
        $currency = strtoupper($currencyCode ?: setting('currency', 'NGN'));
        $exchangeRate = (float) setting('exchange_rate', 1500.00);

        // Prevent division-by-zero errors in production
        if ($exchangeRate <= 0) {
            $exchangeRate = 1.0;
        }

        switch ($currency) {
            case 'USD':
                return '$' . number_format($amount, 2);
            case 'EUR':
                return '€' . number_format($amount, 2);
            case 'GBP':
                return '£' . number_format($amount, 2);
            case 'NGN':
                return '₦' . number_format($amount, 2);
            case 'BOTH':
                // Base amount is assumed to be in NGN, convert to USD for secondary display
                $usdAmount = $amount / $exchangeRate;
                return '₦' . number_format($amount, 2) . ' ($' . number_format($usdAmount, 2) . ')';
            default:
                // Try dynamic symbol from configuration or default to currency code prefix
                $symbol = setting("currency_symbols.{$currency}", $currency . ' ');
                return $symbol . number_format($amount, 2);
        }
    }
}

if (!function_exists('event')) {
    /**
     * Dispatch an event to all registered listeners.
     *
     * @param string $event The event name
     * @param mixed ...$payload Data passed to listeners
     * @return array Listener responses
     */
    function event(string $event, mixed ...$payload): array
    {
        return \Ace\Event::dispatch($event, ...$payload);
    }
}

if (!function_exists('slugify')) {
    /**
     * Generate a URL-friendly slug from a string.
     * Transliterates non-ASCII characters, removes special chars, and handles custom delimiters.
     *
     * @param string $text The input string to slugify
     * @param string $divider The word divider (default is '-')
     * @return string The generated slug
     */
    function slugify(string $text, string $divider = '-'): string
    {
        // Transliterate non-ASCII characters to ASCII (e.g. "München" -> "Munchen")
        if (class_exists('Transliterator') || function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        } else {
            // Fallback transliteration via iconv if Transliterator is unavailable
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        }

        // Lowercase the text (in case transliterator was not used/installed or iconv was used)
        $text = strtolower($text);

        // Replace non-alphanumeric characters with the divider
        $text = preg_replace('/[^a-z0-9]+/', $divider, $text);

        // Remove duplicate dividers
        $text = preg_replace('/' . preg_quote($divider, '/') . '+/', $divider, $text);

        // Trim leading and trailing dividers
        return trim($text, $divider);
    }
}

if (!function_exists('generate_event_slug')) {
    /**
     * Generate a secure, detailed, non-sequential slug for an event.
     * Combines the URL-friendly event name with an 8-character unique hash.
     * E.g., "Summer Beats Festival" -> "summer-beats-festival-bf786668"
     */
    function generate_event_slug(string $name, int|string|null $id = null): string
    {
        $base = slugify($name);
        if (empty($base)) {
            $base = 'event';
        }
        $hash = substr(md5(($id ? $id : '') . '_' . $name . '_' . microtime(true) . '_' . bin2hex(random_bytes(4))), 0, 8);
        return $base . '-' . $hash;
    }
}

// if (!function_exists('event_url')) {
//     /**
//      * Generate canonical, secure URL for an event.
//      * Handles both arrays and Model objects (extracting attributes/magic properties).
//      */
//     function event_url(array|object|null $event): string
//     {
//         if (!$event) {
//             return route('/');
//         }

//         $slug = '';
//         $id = '';

//         if (is_object($event)) {
//             $slug = $event->slug ?? ($event->custom_url ?? '');
//             $id = $event->id ?? '';
//         } else {
//             $slug = !empty($event['slug']) ? $event['slug'] : (!empty($event['custom_url']) ? $event['custom_url'] : '');
//             $id = $event['id'] ?? '';
//         }

//         $target = !empty($slug) ? $slug : $id;

//         if (empty($target)) {
//             return route('/');
//         }

//         return route('event/{id}', ['id' => $target]);
//     }
// }

if (!function_exists('value')) {
    function value(array|object $item, string|int $key, $default = null)
    {
        if (is_array($item)) {
            return $item[$key] ?? $default;
        }

        if (is_object($item)) {
            return $item->$key ?? $default;
        }

        return $default;
    }
}

if (!function_exists('event_url')) {
    function event_url(array|object|null $event): string
    {
        if (!$event) {
            return url('/');
        }

        $customUrl = trim((string) value($event, 'custom_url'));
        $slug = trim((string) value($event, 'slug'));

        $identifier = $customUrl !== '' ? $customUrl : ($slug !== '' ? $slug : value($event, 'id'));

        return url('event/' . $identifier);
    }
}

if (!function_exists('event_flyer_url')) {
    function event_flyer_url(array|object|null $event): string
    {
        if (!$event) {
            return url('/');
        }

        $customUrl = trim((string) value($event, 'custom_url'));
        $slug = trim((string) value($event, 'slug'));

        $identifier = $customUrl !== '' ? $customUrl : ($slug !== '' ? $slug : value($event, 'id'));

        return url('f/' . $identifier);
    }
}

if (!function_exists('event_referral_url')) {
    function event_referral_url(array|object|null $event, string $referralCode): string
    {
        if (!$event) {
            return url('/');
        }

        $flyerEnabled = !empty(value($event, 'flyer_page_enabled'));
        $baseUrl = $flyerEnabled ? event_flyer_url($event) : event_url($event);

        return $baseUrl . '?ref=' . urlencode(trim($referralCode));
    }
}

if (!function_exists('site_setting')) {
    /**
     * Read a site setting from config/settings.json.
     * Cached per-request to avoid repeated file reads.
     *
     * @param string|null $key   Setting key, or null to get all settings
     * @param mixed       $default Fallback value if key is missing
     * @return mixed
     */
    function site_setting(?string $key = null, mixed $default = ''): mixed
    {
        static $cache = null;

        if ($cache === null) {
            $defaults = [
                'site_title' => 'Eventlyy',
                'site_description' => 'Discover and book amazing events near you.',
                'site_logo' => '',
                'site_favicon' => '',
                'support_email' => 'support@eventlyy.com',
                'maintenance_mode' => 'no',
                'enabled_gateways' => ['paystack'],
                'default_gateway' => 'paystack',
                'platform_fee' => '5',
                'extra_charge' => '100.00',
                'currency' => 'NGN',
                'exchange_rate' => '1500.00',
                'timezone' => 'Africa/Lagos',
                'event_completion_hours' => '12',
				'auto_payout_enabled' => '1',
                'auto_payout_delay_minutes' => '15',
                'custom_domain_fee' => '10000.00',
                'custom_domain_enabled' => '0',
                'social_twitter' => '',
                'social_instagram' => '',
                'social_facebook' => '',
                'social_linkedin' => '',
                'social_youtube' => '',
                'social_tiktok' => '',
                'meta_description' => '',
                'meta_keywords' => '',
                'announcement_enabled' => '0',
                'announcement_location' => 'home',
                'announcement_badge' => '🎉 Special Announcement',
                'announcement_title' => '',
                'announcement_message' => '',
                'announcement_image' => '',
                'announcement_link_text' => 'Check It Out →',
                'announcement_link_url' => '',
                'announcement_dismiss_text' => 'Continue to Site',
                'announcement_updated_at' => '0',
            ];

            $settingsFile = \Ace\Application::$ROOT_DIR . '/config/settings.json';

            if (file_exists($settingsFile)) {
                $data = json_decode(file_get_contents($settingsFile), true) ?: [];
                $cache = array_merge($defaults, $data);
            } else {
                $cache = $defaults;
            }
        }

        if ($key === null) {
            return $cache;
        }

        return $cache[$key] ?? $default;
    }
}

if (!function_exists('event_completion_hours')) {
    /**
     * Get the configured event auto-completion window in hours (default 12).
     */
    function event_completion_hours(): int
    {
        return max(1, (int) site_setting('event_completion_hours', 12));
    }
}

if (!function_exists('auto_payout_enabled')) {
    /**
     * Check if automatic payout requests on event completion are enabled.
     */
    function auto_payout_enabled(): bool
    {
        $val = site_setting('auto_payout_enabled', '1');
        return $val === '1' || $val === true || $val === 1 || $val === 'true';
    }
}

if (!function_exists('auto_payout_delay_minutes')) {
    /**
     * Get the delay window in minutes after event completion before auto-requesting payout (default 15).
     */
    function auto_payout_delay_minutes(): int
    {
        return max(0, (int) site_setting('auto_payout_delay_minutes', 15));
    }
}

if (!function_exists('pending_payouts_count')) {
    /**
     * Get count of pending payout requests for admin badge indicator.
     * Cached per-request to avoid redundant SQL queries.
     */
    function pending_payouts_count(): int
    {
        static $count = null;
        if ($count === null) {
            try {
                $db = \Ace\Application::$app->db->pdo;
                $count = (int) ($db->query("SELECT COUNT(*) FROM payouts WHERE status = 'pending'")->fetchColumn() ?: 0);
            } catch (\Throwable $e) {
                $count = 0;
            }
        }
        return $count;
    }
}

if (!function_exists('ticket_qr_url')) {
    /**
     * Generate a high-contrast, scannable QR Code image URL for an admission ticket.
     * Uses Level M error correction and white margins optimized for camera and optical gate scanners.
     *
     * @param string|int $ticketCode Unique ticket code (e.g. EV-1A2B3C4D)
     * @param int $size Image dimension in pixels (default: 200)
     * @return string Validated QR Code image URL
     */
    function ticket_qr_url(string|int $ticketCode, int $size = 200): string
    {
        $code = trim((string)$ticketCode);
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&margin=4&ecc=M&color=0-0-0&bgcolor=255-255-255&data=" . urlencode($code);
    }
}

if (!function_exists('app_base_url')) {
    /**
     * Get the absolute public base URL (origin) of the application.
     * Intelligently detects:
     * 1. Active request host & protocol (HTTPS, Cloudflare proxy, reverse proxy)
     * 2. Production APP_URL from environment
     * 3. Fallback to current host or localhost
     */
    function app_base_url(): string
    {
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';
        if (!empty($httpHost)) {
            $isLocal = str_contains($httpHost, 'localhost') || str_ends_with($httpHost, '.test') || str_starts_with($httpHost, '127.0.0.1');

            $envAppUrl = trim((string) env('APP_URL', ''), '"\'/ ');
            $envHasRealDomain = !empty($envAppUrl) && !str_contains($envAppUrl, 'localhost') && !str_contains($envAppUrl, '.test') && !str_contains($envAppUrl, '127.0.0.1');

            if ($envHasRealDomain && $isLocal) {
                if (!str_starts_with($envAppUrl, 'http://') && !str_starts_with($envAppUrl, 'https://')) {
                    $envAppUrl = 'https://' . $envAppUrl;
                }
                return rtrim($envAppUrl, '/');
            }

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], 'https'))
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

            $scheme = $isHttps ? 'https://' : 'http://';
            return rtrim($scheme . $httpHost, '/');
        }

        $envAppUrl = trim((string) env('APP_URL', ''), '"\'/ ');
        if (!empty($envAppUrl)) {
            if (!str_starts_with($envAppUrl, 'http://') && !str_starts_with($envAppUrl, 'https://')) {
                $envAppUrl = 'https://' . $envAppUrl;
            }
            return rtrim($envAppUrl, '/');
        }

        return 'http://localhost';
    }
}

if (!function_exists('email_logo_url')) {
    /**
     * Get the absolute web URL for the organization logo in email templates.
     * Guarantees a fully-qualified, reachable HTTP/HTTPS image URL for email clients.
     */
    function email_logo_url(): ?string
    {
        $logo = site_setting('site_logo');
        if (empty($logo)) {
            return null;
        }

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        $cleanPath = ltrim($logo, '/\\');

        // Normalize path: if it doesn't already start with assets/ or uploads/, prepend assets/
        if (!str_starts_with($cleanPath, 'assets/') && !str_starts_with($cleanPath, 'uploads/')) {
            $cleanPath = 'assets/' . $cleanPath;
        }

        $baseUrl = app_base_url();
        return $baseUrl . '/' . $cleanPath;
    }
}

if (!function_exists('email_logo_src')) {
    /**
     * Get the image source for the organization logo in emails.
     * - In Development (localhost / .test / Mailtrap sandbox): Returns base64 data URI so
     *   Mailtrap's HTTPS UI renders it without browser mixed-content blocks or local DNS failure.
     * - In Production (live public domain): Returns the fully-qualified public HTTPS URL
     *   (e.g. https://eventlyy.com/assets/img/branding/...) which Gmail, Outlook, Yahoo,
     *   and mobile clients proxy and render without blocking.
     */
    function email_logo_src(): ?string
    {
        $logo = site_setting('site_logo');
        if (empty($logo)) {
            return null;
        }

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        $appUrl = app_base_url();
        $isLocal = str_contains($appUrl, 'localhost') || str_contains($appUrl, '.test') || str_contains($appUrl, '127.0.0.1');

        if ($isLocal) {
            $cleanPath = ltrim($logo, '/\\');
            $rawPath = preg_replace('#^assets/#', '', $cleanPath);
            $root = \Ace\Application::$ROOT_DIR;

            $candidatePaths = [
                public_dir('assets/' . $rawPath),
                public_dir($cleanPath),
                $root . '/assets/' . $rawPath,
                $root . '/public/assets/' . $rawPath,
                $root . '/public_html/assets/' . $rawPath,
                $root . '/' . $cleanPath,
                $root . '/' . $rawPath,
            ];
            if (!empty($_SERVER['DOCUMENT_ROOT'])) {
                $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
                $candidatePaths[] = $docRoot . '/assets/' . $rawPath;
                $candidatePaths[] = $docRoot . '/' . $cleanPath;
                $candidatePaths[] = $docRoot . '/' . $rawPath;
            }

            foreach ($candidatePaths as $p) {
                if (file_exists($p) && is_readable($p) && !is_dir($p)) {
                    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                    $mime = match($ext) {
                        'png' => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'gif' => 'image/gif',
                        'svg' => 'image/svg+xml',
                        'webp' => 'image/webp',
                        default => 'image/png'
                    };
                    $content = @file_get_contents($p);
                    if ($content !== false && strlen($content) > 0 && strlen($content) < 800000) {
                        return "data:{$mime};base64," . base64_encode($content);
                    }
                }
            }
        }

        return email_logo_url();
    }
}

if (!function_exists('event_calendar_times')) {
    /**
     * Compute UTC and ISO calendar timestamps for an event.
     */
    function event_calendar_times($event): array
    {
        $rawDate = is_array($event) ? ($event['date'] ?? '') : ($event->date ?? '');
        $startTimestamp = !empty($rawDate) ? strtotime($rawDate) : time();
        if (!$startTimestamp) $startTimestamp = time();
        // Default duration: 3 hours if not specified
        $endTimestamp = $startTimestamp + (3 * 3600);

        return [
            'start_utc' => gmdate('Ymd\THis\Z', $startTimestamp),
            'end_utc'   => gmdate('Ymd\THis\Z', $endTimestamp),
            'start_iso' => gmdate('Y-m-d\TH:i:s\Z', $startTimestamp),
            'end_iso'   => gmdate('Y-m-d\TH:i:s\Z', $endTimestamp),
            'timestamp' => $startTimestamp
        ];
    }
}

if (!function_exists('event_google_calendar_url')) {
    /**
     * Generate direct Google Calendar add event URL.
     */
    function event_google_calendar_url($event): string
    {
        $name = is_array($event) ? ($event['name'] ?? '') : ($event->name ?? '');
        $location = is_array($event) ? ($event['location'] ?? '') : ($event->location ?? '');
        $description = is_array($event) ? ($event['description'] ?? '') : ($event->description ?? '');
        $times = event_calendar_times($event);

        $params = [
            'action' => 'TEMPLATE',
            'text' => $name,
            'dates' => $times['start_utc'] . '/' . $times['end_utc'],
            'details' => strip_tags((string)$description),
            'location' => $location
        ];

        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }
}

if (!function_exists('event_outlook_calendar_url')) {
    /**
     * Generate direct Outlook Calendar add event URL.
     */
    function event_outlook_calendar_url($event): string
    {
        $name = is_array($event) ? ($event['name'] ?? '') : ($event->name ?? '');
        $location = is_array($event) ? ($event['location'] ?? '') : ($event->location ?? '');
        $description = is_array($event) ? ($event['description'] ?? '') : ($event->description ?? '');
        $times = event_calendar_times($event);

        $params = [
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $name,
            'startdt' => $times['start_iso'],
            'enddt' => $times['end_iso'],
            'body' => strip_tags((string)$description),
            'location' => $location
        ];

        return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query($params);
    }
}

if (!function_exists('event_ics_url')) {
    /**
     * Generate iCalendar (.ics) download URL for Apple Calendar & Outlook desktop.
     */
    function event_ics_url($event): string
    {
        $identifier = is_array($event) 
            ? (!empty($event['custom_url']) ? $event['custom_url'] : ($event['slug'] ?? $event['id']))
            : (!empty($event->custom_url) ? $event->custom_url : ($event->slug ?? $event->id));
        return \url('/event/' . $identifier . '/calendar.ics');
    }
}

if (!function_exists('parse_guest_socials')) {
    /**
     * Parse guest social handles and URLs into clickable structured button definitions.
     * Supports Instagram (@handle or full URL), X/Twitter, TikTok, YouTube, LinkedIn, Facebook, and generic websites.
     *
     * @param string|null $social
     * @return array<int, array{type: string, url: string, label: string, title: string, icon: string}>
     */
    function parse_guest_socials(?string $social): array
    {
        if (empty($social) || trim($social) === '') {
            return [];
        }

        $raw = trim($social);
        // Split by commas, semicolons, or newlines in case multiple handles/links exist
        $tokens = preg_split('/[\r\n,;]+/', $raw);
        $results = [];

        $instagramIcon = '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>';
        $twitterIcon = '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
        $tiktokIcon = '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>';
        $youtubeIcon = '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>';
        $globeIcon = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>';

        foreach ($tokens as $token) {
            $item = trim($token);
            if ($item === '') continue;

            // 1. Starts with @ (e.g. @davido) -> Instagram handle
            if (str_starts_with($item, '@')) {
                $handle = ltrim($item, '@');
                $results[] = [
                    'type' => 'instagram',
                    'url' => 'https://instagram.com/' . rawurlencode($handle),
                    'label' => '@' . $handle,
                    'title' => 'Instagram: @' . $handle,
                    'icon' => $instagramIcon,
                ];
                continue;
            }

            // 2. Instagram URL or domain (e.g. instagram.com/davido or https://www.instagram.com/davido/)
            if (preg_match('#(?:https?://)?(?:www\.)?instagr(?:\.am|am\.com)/([a-zA-Z0-9._]+)#i', $item, $m)) {
                $handle = rtrim($m[1], '/');
                $url = str_starts_with($item, 'http') ? $item : 'https://' . $item;
                $results[] = [
                    'type' => 'instagram',
                    'url' => $url,
                    'label' => '@' . $handle,
                    'title' => 'Instagram: @' . $handle,
                    'icon' => $instagramIcon,
                ];
                continue;
            }

            // 3. Twitter / X URL (e.g. twitter.com/davido or x.com/davido)
            if (preg_match('#(?:https?://)?(?:www\.)?(?:twitter\.com|x\.com)/([a-zA-Z0-9_]+)#i', $item, $m)) {
                $handle = rtrim($m[1], '/');
                $url = str_starts_with($item, 'http') ? $item : 'https://' . $item;
                $results[] = [
                    'type' => 'twitter',
                    'url' => $url,
                    'label' => '@' . $handle,
                    'title' => 'X (Twitter): @' . $handle,
                    'icon' => $twitterIcon,
                ];
                continue;
            }

            // 4. TikTok URL (e.g. tiktok.com/@davido)
            if (preg_match('#(?:https?://)?(?:www\.)?tiktok\.com/@?([a-zA-Z0-9._]+)#i', $item, $m)) {
                $handle = rtrim($m[1], '/');
                $url = str_starts_with($item, 'http') ? $item : 'https://' . $item;
                $results[] = [
                    'type' => 'tiktok',
                    'url' => $url,
                    'label' => '@' . $handle,
                    'title' => 'TikTok: @' . $handle,
                    'icon' => $tiktokIcon,
                ];
                continue;
            }

            // 5. YouTube URL
            if (preg_match('#(?:https?://)?(?:www\.)?(?:youtube\.com|youtu\.be)/#i', $item)) {
                $url = str_starts_with($item, 'http') ? $item : 'https://' . $item;
                $results[] = [
                    'type' => 'youtube',
                    'url' => $url,
                    'label' => 'YouTube',
                    'title' => 'Watch on YouTube',
                    'icon' => $youtubeIcon,
                ];
                continue;
            }

            // 6. Generic web URL (http:// or https:// or domain.com)
            if (str_starts_with($item, 'http://') || str_starts_with($item, 'https://') || preg_match('#^[a-zA-Z0-9-]+\.[a-zA-Z]{2,}(/.*)?$#i', $item)) {
                $url = str_starts_with($item, 'http') ? $item : 'https://' . $item;
                $host = parse_url($url, PHP_URL_HOST) ?: 'Website';
                $host = preg_replace('/^www\./i', '', $host);
                $results[] = [
                    'type' => 'website',
                    'url' => $url,
                    'label' => $host,
                    'title' => 'Visit website: ' . $url,
                    'icon' => $globeIcon,
                ];
                continue;
            }

            // 7. Plain text handle without @ (e.g. davido_official) -> Default to Instagram handle
            if (preg_match('/^[a-zA-Z0-9._]{2,30}$/', $item)) {
                $results[] = [
                    'type' => 'instagram',
                    'url' => 'https://instagram.com/' . rawurlencode($item),
                    'label' => '@' . $item,
                    'title' => 'Instagram: @' . $item,
                    'icon' => $instagramIcon,
                ];
                continue;
            }

            // Fallback: display as website link
            $results[] = [
                'type' => 'website',
                'url' => 'https://' . $item,
                'label' => $item,
                'title' => $item,
                'icon' => $globeIcon,
            ];
        }

        // Deduplicate by normalized URL
        $seen = [];
        $unique = [];
        foreach ($results as $res) {
            $norm = rtrim(strtolower($res['url']), '/');
            if (!isset($seen[$norm])) {
                $seen[$norm] = true;
                $unique[] = $res;
            }
        }

        return $unique;
    }
}



if (!function_exists('dump')) {
    /**
     * Pretty-print one or more variables for debugging.
     * Does NOT halt execution.
     */
    function dump(mixed ...$vars): void
    {
        $isCli = php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';

        foreach ($vars as $var) {
            if ($isCli) {
                echo "\n";
                var_export($var);
                echo "\n";
            } else {
                echo '<pre style="background:#1e1e2e;color:#cdd6f4;padding:12px 16px;margin:8px 0;border-radius:8px;font-family:\'JetBrains Mono\',monospace;font-size:13px;line-height:1.5;overflow-x:auto;border-left:4px solid #89b4fa;">';
                echo '<span style="color:#585b70;font-size:11px;display:block;margin-bottom:4px;">';
                $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
                if (!empty($bt[0])) {
                    echo htmlspecialchars(basename($bt[0]['file'])) . ':' . $bt[0]['line'];
                }
                echo '</span>';
                echo htmlspecialchars(var_export($var, true));
                echo '</pre>';
            }
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump variables and die. "Dump and Die" debugging helper.
     */
    function dd(mixed ...$vars): never
    {
        dump(...$vars);

        if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'phpdbg') {
            echo '<div style="background:#f38ba8;color:#1e1e2e;padding:6px 12px;border-radius:4px;font-family:monospace;font-size:12px;margin:8px 0;">⛔ Execution halted by dd()</div>';
        }

        exit(1);
    }
}

if (!function_exists('collect')) {
    /**
     * Create a new Collection instance from the given array.
     *
     * @param array $items
     * @return \Ace\Collection
     */
    function collect(array $items = []): \Ace\Collection
    {
        return new \Ace\Collection($items);
    }
}

if (!function_exists('dispatch')) {
    /**
     * Dispatch a background job onto the queue.
     *
     * @param string $jobClass FQCN of the job class extending \Ace\Job
     * @param array $data Payload data passed to the job
     * @param string $queue Target queue name (default 'default')
     * @param int $delay Delay in seconds
     * @return int|bool
     */
    function dispatch(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): int|bool
    {
        return \Ace\Queue::push($jobClass, $data, $queue, $delay);
    }
}

if (!function_exists('payment')) {
    /**
     * Get the payment manager instance or a specific payment gateway driver.
     *
     * @param string|null $driver 'paystack', 'flutterwave', 'stripe', 'crypto', or null for manager
     * @return \Ace\Payments\PaymentManager|\Ace\Payments\PaymentGatewayInterface
     */
    function payment(?string $driver = null): \Ace\Payments\PaymentManager|\Ace\Payments\PaymentGatewayInterface
    {
        static $manager = null;
        if ($manager === null) {
            $manager = new \Ace\Payments\PaymentManager();
        }

        if ($driver !== null) {
            return $manager->driver($driver);
        }

        return $manager;
    }
}
