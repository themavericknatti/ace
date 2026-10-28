<?php

namespace Ace;

use Exception;

class Application
{
    public const VERSION = '1.0.0';

    public static string $ROOT_DIR;
    public static Application $app;
    
    public string $layout = 'main';
    public Request $request;
    public Response $response;
    public Controller $controller;
    public Router $router;
    public Session $session;
    public View $view;
    public TemplateManager $templates;
    public ?Database $db = null;
    public ?Model $user = null;
    public array $config = [];
    public Container $container;

    public function __construct(string $rootDir, array $config = [])
    {
        self::$ROOT_DIR = $rootDir;
        self::$app = $this;
        $this->config = $config;

        // Set system default timezone (settings.json overrides config/app.php)
        $timezone = $this->config['timezone'] ?? 'UTC';
        $settingsFile = $rootDir . '/config/settings.json';
        if (file_exists($settingsFile)) {
            $settingsData = json_decode(file_get_contents($settingsFile), true);
            if (!empty($settingsData['timezone'])) {
                $timezone = $settingsData['timezone'];
            }
        }
        date_default_timezone_set($timezone);

        // Initialize the Dependency Injection Container
        $this->container = new Container();

        // Load env variables
        $this->loadEnv($rootDir . '/.env');

        // Template configuration
        $templatesConfig = $this->config['templates'] ?? [];
        $templatesConfigFile = $rootDir . '/config/templates.php';
        if (empty($templatesConfig) && file_exists($templatesConfigFile)) {
            $templatesConfig = require $templatesConfigFile;
        }
        $this->templates = new TemplateManager($templatesConfig);

        // Core component instances
        $this->request = new Request();
        $this->response = new Response();
        $this->session = new Session();
        $this->view = new View(self::$ROOT_DIR . '/views', self::$ROOT_DIR . '/storage/views', $this->templates);
        $this->router = new Router($this->request, $this->response);
        $this->controller = new Controller($this->request, $this->response);


        // Database connection if configured
        $dbConfig = $this->config['db'] ?? [
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_NAME'] ?? '',
            'username' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
        ];

        if (!empty($dbConfig['database'])) {
            $this->db = new Database($dbConfig);
        }

        // Register core singletons in the container
        $this->registerCoreSingletons();

        // Error handling registration
        $this->registerErrorHandlers();

        // Load event listener registrations
        $eventsFile = self::$ROOT_DIR . '/config/events.php';
        if (file_exists($eventsFile)) {
            $events = require $eventsFile;
            if (is_array($events)) {
                foreach ($events as $event => $listeners) {
                    foreach ((array)$listeners as $listener) {
                        Event::listen($event, $listener);
                    }
                }
            }
        }

        // Resolve logged in user from session or remember-me cookie if exists
        $userClass = $this->config['userClass'] ?? null;
        if ($userClass) {
            $primaryKey = $this->session->get('user');
            if ($primaryKey) {
                $userModel = new $userClass();
                $this->user = $userModel::findOne([$userModel->primaryKey() => $primaryKey]);
            }

            if (!$this->user) {
                $this->loginFromRememberMeCookie($userClass);
            }
        }
    }

    /**
     * Parse and load .env file into $_ENV
     */
    private function loadEnv(string $envPath): void
    {
        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Split on the first '=' character
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);

                // Strip surrounding quotes if any
                if (str_starts_with($val, '"') && str_ends_with($val, '"')) {
                    $val = substr($val, 1, -1);
                } elseif (str_starts_with($val, "'") && str_ends_with($val, "'")) {
                    $val = substr($val, 1, -1);
                }

                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }

    /**
     * Global Exception and Error Handlers
     */
    private function registerErrorHandlers(): void
    {
        // Exception handler
        set_exception_handler(function ($exception) {
            $this->handleException($exception);
        });

        // Error handler (convert PHP errors to ErrorException)
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * Handle uncaught exceptions
     */
    public function handleException(\Throwable $exception): void
    {
        // Clear all active output buffers to ensure a clean error page response
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $code = (int)$exception->getCode();
        
        // Normalize HTTP status codes
        if ($code < 400 || $code > 599) {
            $code = 500;
        }

        $this->response->setStatusCode($code);

		// Generate unique error incident reference ID for easy tracking in logs
        $errorId = 'ERR-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

        // Only log server errors (5xx) or non-404 client errors with full stack traces.
        // 404 (Page Not Found) is a standard HTTP client response (e.g. bots, mistyped URLs)
        // and should not clutter application logs with full exception stack traces.
        if ($code >= 500) {
            Logger::exception($exception, $errorId);
        }

        // Check if debug mode is active (via APP_DEBUG=true or APP_ENV=development)
        $isDebug = in_array(strtolower((string)($_ENV['APP_DEBUG'] ?? '')), ['true', '1', 'yes', 'on'], true)
            || ($_ENV['APP_ENV'] ?? 'development') === 'development';

        $isJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $code,
                'errorId' => $errorId
            ]);
            exit;
        }

        // Intelligent deployment diagnostic hints based on exception signatures
        $hints = [];
        $msg = $exception->getMessage();
        if (str_contains($msg, 'Access denied for user') || str_contains($msg, 'Database Connection Failed') || str_contains($msg, 'Connection refused') || str_contains($msg, 'Unknown database') || str_contains($msg, 'SQLSTATE[HY000] [2002]')) {
            $hints[] = [
                'title' => 'Database Connection Issue',
                'action' => 'Verify DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS in your .env file, and ensure MySQL is active.'
            ];
        }
        if (str_contains($msg, "doesn't exist") || str_contains($msg, 'Base table or view not found') || (str_contains($msg, 'Table ') && str_contains($msg, 'not found'))) {
            $hints[] = [
                'title' => 'Pending Database Migrations',
                'action' => 'Database tables have not been created yet. Run "php ace migrate" in your server terminal or SSH.'
            ];
        }
        if (str_contains($msg, 'Permission denied') || str_contains($msg, 'Failed to open stream') || str_contains($msg, 'mkdir():')) {
            $hints[] = [
                'title' => 'Folder Write Permissions',
                'action' => 'Ensure the web server user can write to storage and uploads. Run: "chmod -R 775 storage" and "chmod -R 775 uploads" in your public directory (public/ or public_html/).'
            ];
        }
        if (str_contains($msg, 'Class "PDO"') || str_contains($msg, 'curl_init') || str_contains($msg, 'finfo_open') || str_contains($msg, 'mb_')) {
            $hints[] = [
                'title' => 'Missing PHP Extension',
                'action' => 'A required PHP extension (pdo_mysql, curl, fileinfo, or mbstring) is disabled or missing in php.ini.'
            ];
        }

        $errorData = [
            'exception' => $exception,
            'message' => $exception->getMessage(),
            'code' => $code,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'errorId' => $errorId,
            'hints' => $hints,
            'isDebug' => $isDebug
        ];

        // Render appropriate error screen
        if ($code === 404) {
            echo $this->router->renderView('errors/404', $errorData);
        } else {
            echo $this->router->renderView('errors/500', $errorData);
        }
        exit;
    }

    /**
     * Whether the current request is an API request (/api/* path)
     */
    public bool $isApiRequest = false;

    public function run(): void
    {
        ob_start();
        try {
            // Check for maintenance mode
            $this->checkMaintenanceMode();

            // Determine if this is an API request
            $path = $this->request->getPath();
            $this->isApiRequest = str_starts_with($path, '/api/') || $path === '/api';

            // Run global middlewares
            (new \Ace\Middlewares\FirewallMiddleware())->execute('');
            (new \Ace\Middlewares\SecurityHeadersMiddleware())->execute('');
            (new \Ace\Middlewares\RateLimitMiddleware())->execute('');

            // Skip CSRF verification for API routes (they use API key auth instead)
            if (!$this->isApiRequest) {
                (new \Ace\Middlewares\CsrfMiddleware())->execute('');
            }

            echo $this->router->resolve();
            ob_end_flush();
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Check if application is currently in maintenance mode
     */
    protected function checkMaintenanceMode(): void
    {
        $downFile = self::$ROOT_DIR . '/storage/framework/down';
        if (!file_exists($downFile)) {
            return;
        }

        $data = json_decode(file_get_contents($downFile), true) ?: [];
        $secret = $data['secret'] ?? null;
        $hasSecret = false;

        // Check if bypass secret is provided in query param or cookie
        if ($secret) {
            if (($_GET['secret'] ?? '') === $secret) {
                setcookie('ace_bypass_secret', $secret, time() + 86400, '/');
                $hasSecret = true;
            } elseif (($_COOKIE['ace_bypass_secret'] ?? '') === $secret) {
                $hasSecret = true;
            }
        }

        if (!$hasSecret) {
            http_response_code(503);
            header('Retry-After: ' . ($data['retry'] ?? 60));

            $isJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
                || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

            if ($isJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'message' => $data['message'] ?? 'Service Unavailable for maintenance.',
                    'code' => 503,
                    'retry_after' => (int)($data['retry'] ?? 60)
                ]);
                exit;
            }

            echo $this->router->renderView('errors/503', [
                'message' => $data['message'] ?? 'We are performing scheduled maintenance. Please check back shortly.'
            ]);
            exit;
        }
    }

    /**
     * Login helper
     */
    public function login(Model $user, bool $remember = false): bool
    {
        $this->user = $user;
        $primaryKey = $user->primaryKey();
        $value = $user->{$primaryKey};
        $this->session->set('user', $value);

        // Regenerate CSRF token after login to prevent pre-login CSRF fixation
        $this->session->regenerateCsrfToken();

        if ($remember) {
            $this->createRememberMeToken($value);
        }

        return true;
    }

    /**
     * Logout helper
     */
    public function logout(): void
    {
        if ($this->user) {
            $primaryKey = $this->user->primaryKey();
            $userId = $this->user->{$primaryKey};
            $this->clearRememberMeCookie($userId);
        }
        $this->user = null;
        $this->session->remove('user');
    }

    /**
     * Check if current user is guest
     */
    public static function isGuest(): bool
    {
        return !self::$app->user;
    }

    /**
     * Create a new remember me token and set the cookie
     */
    private function createRememberMeToken(int|string $userId): void
    {
        if (!$this->db) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires = time() + (30 * 24 * 60 * 60); // 30 days
        $expiresAt = date('Y-m-d H:i:s', $expires);

        $stmt = $this->db->prepare("
            INSERT INTO remember_tokens (user_id, token_hash, expires_at)
            VALUES (:user_id, :token_hash, :expires_at)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt
        ]);

        // Secure cookie flags
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(
            'remember_me',
            "$userId:$token",
            [
                'expires' => $expires,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    /**
     * Attempt login using the remember_me cookie
     */
    private function loginFromRememberMeCookie(string $userClass): void
    {
        $cookie = $_COOKIE['remember_me'] ?? null;
        if (!$cookie || !$this->db) {
            return;
        }

        $parts = explode(':', $cookie, 2);
        if (count($parts) !== 2) {
            $this->clearRememberMeCookie();
            return;
        }

        [$userId, $token] = $parts;
        $tokenHash = hash('sha256', $token);

        // Find token in db
        $stmt = $this->db->prepare("
            SELECT * FROM remember_tokens
            WHERE user_id = :user_id AND token_hash = :token_hash AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
        $record = $stmt->fetch();

        if ($record) {
            $userModel = new $userClass();
            $user = $userModel::findOne([$userModel->primaryKey() => $userId]);
            if ($user) {
                $this->user = $user;
                $this->session->set('user', $userId);
                
                // Rotate token
                $this->rotateRememberMeToken($record['id'], $userId);
                return;
            }
        }

        // Token invalid or expired, clear it
        $this->clearRememberMeCookie($userId, $tokenHash);
    }

    /**
     * Rotate remember token on successful auto-login
     */
    private function rotateRememberMeToken(int $tokenId, int|string $userId): void
    {
        if (!$this->db) {
            return;
        }

        $newToken = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newToken);
        $expires = time() + (30 * 24 * 60 * 60); // 30 days
        $expiresAt = date('Y-m-d H:i:s', $expires);

        // Update database token
        $stmt = $this->db->prepare("
            UPDATE remember_tokens
            SET token_hash = :token_hash, expires_at = :expires_at
            WHERE id = :id
        ");
        $stmt->execute([
            'token_hash' => $newTokenHash,
            'expires_at' => $expiresAt,
            'id' => $tokenId
        ]);

        // Set cookie
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(
            'remember_me',
            "$userId:$newToken",
            [
                'expires' => $expires,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    /**
     * Clear remember cookie and remove token from db
     */
    private function clearRememberMeCookie(?int $userId = null, ?string $tokenHash = null): void
    {
        // Remove from db if specified
        if ($this->db) {
            if ($userId && $tokenHash) {
                $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE user_id = :user_id AND token_hash = :token_hash");
                $stmt->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
            } elseif ($userId) {
                // Delete all tokens for this user on logout
                $stmt = $this->db->prepare("DELETE FROM remember_tokens WHERE user_id = :user_id");
                $stmt->execute(['user_id' => $userId]);
            }
        }

        // Expire cookie
        setcookie('remember_me', '', time() - 3600, '/');
        if (isset($_COOKIE['remember_me'])) {
            unset($_COOKIE['remember_me']);
        }
    }

    /**
     * Bind core components to the Dependency Injection Container.
     */
    private function registerCoreSingletons(): void
    {
        $this->container->singleton(Application::class, fn() => $this);
        $this->container->singleton(Request::class, fn() => $this->request);
        $this->container->singleton(Response::class, fn() => $this->response);
        $this->container->singleton(Session::class, fn() => $this->session);
        $this->container->singleton(View::class, fn() => $this->view);
        $this->container->singleton(Router::class, fn() => $this->router);
        
        if ($this->db) {
            $this->container->singleton(Database::class, fn() => $this->db);
        }
    }
}

