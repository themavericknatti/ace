<?php

namespace Ace;

class Session
{
    protected const FLASH_KEY = 'flash_messages';

    /**
     * CSRF token lifetime in seconds (default: 2 hours)
     * After this period, the token is regenerated.
     */
    protected const CSRF_TOKEN_LIFETIME = 7200;

    public function __construct()
    {
        if (php_sapi_name() !== 'cli') {
            // Harden session cookie settings BEFORE session_start()
            if (session_status() === PHP_SESSION_NONE) {
                // Set secure session cookie parameters
                session_set_cookie_params([
                    'lifetime' => 0,               // Session cookie (expires when browser closes)
                    'path'     => '/',
                    'domain'   => '',               // Current domain only
                    'secure'   => $this->isHttps(), // Only send over HTTPS in production
                    'httponly' => true,              // Prevents JavaScript access to session cookie
                    'samesite' => 'Lax',            // Prevents cross-site request forgery
                ]);

                // Use a custom session name (don't expose default 'PHPSESSID')
                session_name('ACE_SESSION');

                session_start();

                // Regenerate session ID periodically to prevent session fixation
                $this->preventSessionFixation();
            }
        } else {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
        }

        // Retrieve flash messages and mark them to be removed
        $flashMessages = $_SESSION[self::FLASH_KEY] ?? [];
        foreach ($flashMessages as $key => &$flashMessage) {
            $flashMessage['remove'] = true;
        }
        $_SESSION[self::FLASH_KEY] = $flashMessages;
    }

    /**
     * Set a flash message
     */
    public function setFlash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH_KEY][$key] = [
            'remove' => false,
            'value' => $value
        ];
    }

    /**
     * Get a flash message
     */
    public function getFlash(string $key): mixed
    {
        return $_SESSION[self::FLASH_KEY][$key]['value'] ?? false;
    }

    /**
     * Set a standard session variable
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Get a session variable
     */
    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    /**
     * Remove a session variable
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Check if a session key exists
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    // -------------------------------------------------------
    //  CSRF Token Management
    // -------------------------------------------------------

    /**
     * Retrieve or generate a time-limited CSRF token.
     *
     * Token is regenerated when:
     * - No token exists yet (first visit)
     * - The token has exceeded its lifetime (expired)
     *
     * The token uses 256 bits of cryptographic randomness (bin2hex(random_bytes(32))).
     */
    public function getCsrfToken(): string
    {
        $token = $this->get('csrf_token');
        $createdAt = $this->get('csrf_token_time');
        $now = time();

        // Regenerate if token is missing or expired
        if (!$token || !$createdAt || ($now - $createdAt) > self::CSRF_TOKEN_LIFETIME) {
            $token = bin2hex(random_bytes(32));
            $this->set('csrf_token', $token);
            $this->set('csrf_token_time', $now);
        }

        return $token;
    }

    /**
     * Validate a submitted CSRF token against the session token.
     * Uses hash_equals() to prevent timing attacks.
     *
     * @return bool True if valid, false otherwise
     */
    public function validateCsrfToken(string $submittedToken): bool
    {
        $sessionToken = $this->get('csrf_token');
        $createdAt = $this->get('csrf_token_time');
        $now = time();

        // Token must exist
        if (empty($sessionToken) || empty($submittedToken)) {
            return false;
        }

        // Token must not be expired
        if (!$createdAt || ($now - $createdAt) > self::CSRF_TOKEN_LIFETIME) {
            // Force regeneration on next getCsrfToken() call
            $this->remove('csrf_token');
            $this->remove('csrf_token_time');
            return false;
        }

        // Timing-safe comparison
        return hash_equals($sessionToken, $submittedToken);
    }

    /**
     * Force-regenerate the CSRF token (e.g. after login to prevent session fixation)
     */
    public function regenerateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->set('csrf_token', $token);
        $this->set('csrf_token_time', time());
        return $token;
    }

    // -------------------------------------------------------
    //  Session Security
    // -------------------------------------------------------

    /**
     * Regenerate session ID periodically to prevent session fixation attacks.
     * Regenerates every 30 minutes.
     */
    protected function preventSessionFixation(): void
    {
        $regenerateInterval = 1800; // 30 minutes
        $lastRegenerated = $_SESSION['_session_regenerated_at'] ?? 0;

        if (time() - $lastRegenerated > $regenerateInterval) {
            session_regenerate_id(true); // Delete old session file
            $_SESSION['_session_regenerated_at'] = time();
        }
    }

    /**
     * Destroy the session entirely (e.g. on logout)
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Check if connection is HTTPS
     */
    protected function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 0) == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Clean up expired flash messages at the end of the request lifecycle
     */
    public function __destruct()
    {
        if (!isset($_SESSION)) return;

        $flashMessages = $_SESSION[self::FLASH_KEY] ?? [];
        foreach ($flashMessages as $key => $flashMessage) {
            if ($flashMessage['remove']) {
                unset($flashMessages[$key]);
            }
        }
        $_SESSION[self::FLASH_KEY] = $flashMessages;
    }
}
