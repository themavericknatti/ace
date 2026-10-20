<?php

namespace Ace;

use InvalidArgumentException;

/**
 * TemplateManager — Manages Multi-Template Presentation Layer
 *
 * Coordinates registration, resolution, strategy policies (Session, Visit,
 * Persistent, Random, Fixed), and explicit runtime overrides for visual templates.
 */
class TemplateManager
{
    public const STRATEGY_SESSION = 'session';
    public const STRATEGY_VISIT = 'visit';
    public const STRATEGY_PERSISTENT = 'persistent';
    public const STRATEGY_RANDOM = 'random';
    public const STRATEGY_FIXED = 'fixed';

    protected const SESSION_KEY = '__ace_template';
    protected const SESSION_VISIT_ID_KEY = '__ace_visit_id';

    protected bool $enabled = false;
    protected string $default = 'minimal';
    protected string $fallback = 'minimal';
    protected string $strategy = self::STRATEGY_FIXED;
    protected array $available = ['minimal'];
    protected bool $allowOverrides = true;
    protected bool $allowCustomerSelection = false;

    protected array $cookieConfig = [
        'visit_name'       => 'ace_template_visit',
        'visit_lifetime'   => 1800,        // 30 min
        'persist_name'     => 'ace_template_visitor',
        'persist_lifetime' => 31536000,    // 1 year
    ];

    /**
     * Active runtime override (if set for this request)
     */
    protected ?string $override = null;

    /**
     * Cached resolved template for the current request
     */
    protected ?string $resolvedTemplate = null;

    /**
     * Injectable random resolver for deterministic unit testing
     * @var callable|null
     */
    protected $randomResolver = null;

    public function __construct(array $config = [])
    {
        $this->loadConfig($config);
    }

    /**
     * Configure manager with options array
     */
    public function loadConfig(array $config): self
    {
        if (isset($config['enabled'])) {
            $this->enabled = (bool) $config['enabled'];
        }
        if (!empty($config['available']) && is_array($config['available'])) {
            $this->register($config['available']);
        }
        if (!empty($config['default'])) {
            $this->setDefault($config['default']);
        }
        if (!empty($config['fallback'])) {
            $this->setFallback($config['fallback']);
        }
        if (!empty($config['strategy'])) {
            $this->setStrategy($config['strategy']);
        }
        if (isset($config['allow_overrides'])) {
            $this->allowOverrides = (bool) $config['allow_overrides'];
        }
        if (isset($config['allow_customer_selection'])) {
            $this->allowCustomerSelection = (bool) $config['allow_customer_selection'];
        }
        if (!empty($config['cookie']) && is_array($config['cookie'])) {
            $this->cookieConfig = array_merge($this->cookieConfig, $config['cookie']);
        }

        // Reset any cached resolution on re-config
        $this->resolvedTemplate = null;

        return $this;
    }

    /**
     * Check if multi-template visual layer is active
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): self
    {
        $this->enabled = true;
        $this->resolvedTemplate = null;
        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;
        $this->resolvedTemplate = null;
        return $this;
    }

    /**
     * Register available template identifiers
     */
    public function register(array $templates): self
    {
        $sanitized = [];
        foreach ($templates as $t) {
            $clean = $this->sanitizeTemplateName($t);
            if ($clean !== '') {
                $sanitized[] = $clean;
            }
        }
        $this->available = array_values(array_unique(array_merge($this->available, $sanitized)));
        return $this;
    }

    /**
     * Get list of registered template identifiers
     */
    public function getAvailable(): array
    {
        return $this->available;
    }

    /**
     * Check if a template identifier is valid and registered
     */
    public function isValid(string $template): bool
    {
        $clean = $this->sanitizeTemplateName($template);
        return $clean !== '' && in_array($clean, $this->available, true);
    }

    /**
     * Set default template
     */
    public function setDefault(string $template): self
    {
        $clean = $this->sanitizeTemplateName($template);
        if (!$this->isValid($clean)) {
            // Add if not present yet
            $this->available[] = $clean;
        }
        $this->default = $clean;
        $this->resolvedTemplate = null;
        return $this;
    }

    public function getDefault(): string
    {
        return $this->default;
    }

    /**
     * Set fallback template
     */
    public function setFallback(string $template): self
    {
        $clean = $this->sanitizeTemplateName($template);
        if (!$this->isValid($clean)) {
            $this->available[] = $clean;
        }
        $this->fallback = $clean;
        return $this;
    }

    public function getFallback(): string
    {
        return $this->fallback;
    }

    /**
     * Set selection policy/strategy
     */
    public function setStrategy(string $strategy): self
    {
        $validStrategies = [
            self::STRATEGY_SESSION,
            self::STRATEGY_VISIT,
            self::STRATEGY_PERSISTENT,
            self::STRATEGY_RANDOM,
            self::STRATEGY_FIXED,
        ];

        if (!in_array($strategy, $validStrategies, true)) {
            throw new InvalidArgumentException("Unsupported template selection strategy: '{$strategy}'. Supported: " . implode(', ', $validStrategies));
        }

        $this->strategy = $strategy;
        $this->resolvedTemplate = null;
        return $this;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Set explicit runtime template override (e.g. for controller or preview)
     */
    public function setOverride(?string $template): self
    {
        if ($template !== null) {
            if (!$this->allowOverrides) {
                throw new \RuntimeException("Template overrides are disabled in configuration.");
            }
            $clean = $this->sanitizeTemplateName($template);
            if (!$this->isValid($clean)) {
                throw new InvalidArgumentException("Cannot override with invalid or unregistered template '{$template}'.");
            }
            $this->override = $clean;
        } else {
            $this->override = null;
        }

        $this->resolvedTemplate = null;
        return $this;
    }

    /**
     * Clear explicit runtime override
     */
    public function clearOverride(): self
    {
        $this->override = null;
        $this->resolvedTemplate = null;
        return $this;
    }

    /**
     * Explicitly switch/set customer template (affects session or persistent cookie)
     */
    public function set(string $template): bool
    {
        if (!$this->allowOverrides && !$this->allowCustomerSelection) {
            return false;
        }

        $clean = $this->sanitizeTemplateName($template);
        if (!$this->isValid($clean)) {
            return false;
        }

        // Store according to active strategy or default to session
        $session = Application::$app->session ?? null;
        if ($session) {
            $session->set(self::SESSION_KEY, $clean);
        }

        if ($this->strategy === self::STRATEGY_PERSISTENT || $this->strategy === self::STRATEGY_VISIT) {
            $this->setCookie(
                $this->cookieConfig['persist_name'],
                $clean,
                time() + $this->cookieConfig['persist_lifetime']
            );
        }

        $this->override = $clean;
        $this->resolvedTemplate = $clean;
        return true;
    }

    /**
     * Reset assigned template for current visitor / session
     */
    public function reset(): void
    {
        $session = Application::$app->session ?? null;
        if ($session) {
            $session->remove(self::SESSION_KEY);
            $session->remove(self::SESSION_VISIT_ID_KEY);
        }

        $this->setCookie($this->cookieConfig['visit_name'], '', time() - 3600);
        $this->setCookie($this->cookieConfig['persist_name'], '', time() - 3600);

        $this->override = null;
        $this->resolvedTemplate = null;
    }

    /**
     * Resolve the active visual template based on configuration and policy.
     */
    public function resolve(): string
    {
        if (!$this->enabled) {
            return $this->default;
        }

        // Return cached resolution for this request if available
        if ($this->resolvedTemplate !== null && $this->strategy !== self::STRATEGY_RANDOM) {
            return $this->resolvedTemplate;
        }

        // 1. Explicit runtime override takes highest priority
        if ($this->override !== null && $this->isValid($this->override)) {
            return $this->resolvedTemplate = $this->override;
        }

        // 2. Customer query parameter override (if customer selection enabled)
        if ($this->allowCustomerSelection && isset($_GET['template'])) {
            $candidate = $this->sanitizeTemplateName((string)$_GET['template']);
            if ($this->isValid($candidate)) {
                $this->set($candidate);
                return $this->resolvedTemplate = $candidate;
            }
        }

        // 3. Resolve by strategy policy
        $selected = match ($this->strategy) {
            self::STRATEGY_SESSION    => $this->resolveSessionStrategy(),
            self::STRATEGY_VISIT      => $this->resolveVisitStrategy(),
            self::STRATEGY_PERSISTENT => $this->resolvePersistentStrategy(),
            self::STRATEGY_RANDOM     => $this->resolveRandomStrategy(),
            self::STRATEGY_FIXED      => $this->default,
            default                   => $this->default,
        };

        // 4. Validate resolved template; fallback if invalid or deleted
        if (!$this->isValid($selected)) {
            $selected = $this->isValid($this->fallback) ? $this->fallback : $this->default;
        }

        if ($this->strategy !== self::STRATEGY_RANDOM) {
            $this->resolvedTemplate = $selected;
        }

        return $selected;
    }

    /**
     * Policy A: Session-based selection.
     * Selects one template when new session starts, reuses across requests.
     */
    protected function resolveSessionStrategy(): string
    {
        $session = Application::$app->session ?? null;
        if (!$session) {
            return $this->default;
        }

        $existing = $session->get(self::SESSION_KEY);
        if ($existing && $this->isValid($existing)) {
            return $existing;
        }

        // Pick template for this session (e.g. from available pool or default)
        $chosen = $this->pickFromAvailable();
        $session->set(self::SESSION_KEY, $chosen);

        return $chosen;
    }

    /**
     * Policy B: Per-visit selection.
     * Keeps template stable during a visit (defined by 30-minute inactivity cookie).
     */
    protected function resolveVisitStrategy(): string
    {
        $cookieName = $this->cookieConfig['visit_name'];
        $existingCookie = $_COOKIE[$cookieName] ?? null;

        if ($existingCookie && $this->isValid($existingCookie)) {
            // Refresh visit cookie lifetime
            $this->setCookie($cookieName, $existingCookie, time() + $this->cookieConfig['visit_lifetime']);
            return $existingCookie;
        }

        // New visit recognized: choose template and issue visit cookie
        $chosen = $this->pickFromAvailable();
        $this->setCookie($cookieName, $chosen, time() + $this->cookieConfig['visit_lifetime']);

        return $chosen;
    }

    /**
     * Policy C: Persistent visitor assignment.
     * Retains assignment across sessions via long-lived 1-year first-party cookie or account preference.
     */
    protected function resolvePersistentStrategy(): string
    {
        // 1. Check logged-in user preference if model supports it
        $user = Application::$app->user ?? null;
        if ($user && isset($user->theme) && $this->isValid($user->theme)) {
            return $user->theme;
        }

        // 2. Check persistent cookie
        $cookieName = $this->cookieConfig['persist_name'];
        $existingCookie = $_COOKIE[$cookieName] ?? null;

        if ($existingCookie && $this->isValid($existingCookie)) {
            return $existingCookie;
        }

        // 3. Check session fallback
        $session = Application::$app->session ?? null;
        if ($session) {
            $sessionVal = $session->get(self::SESSION_KEY);
            if ($sessionVal && $this->isValid($sessionVal)) {
                $this->setCookie($cookieName, $sessionVal, time() + $this->cookieConfig['persist_lifetime']);
                return $sessionVal;
            }
        }

        // New visitor: assign and set long-lived cookie
        $chosen = $this->pickFromAvailable();
        $this->setCookie($cookieName, $chosen, time() + $this->cookieConfig['persist_lifetime']);

        return $chosen;
    }

    /**
     * Policy D: Random per-request selection.
     * Picks a random template per request without changing session or database.
     */
    protected function resolveRandomStrategy(): string
    {
        if (is_callable($this->randomResolver)) {
            $custom = call_user_func($this->randomResolver, $this->available);
            if (is_string($custom) && $this->isValid($custom)) {
                return $custom;
            }
        }

        if (empty($this->available)) {
            return $this->default;
        }

        $randomIndex = array_rand($this->available);
        return $this->available[$randomIndex];
    }

    /**
     * Pick a template from available pool based on default or distribution
     */
    protected function pickFromAvailable(): string
    {
        if (in_array($this->default, $this->available, true)) {
            return $this->default;
        }
        return $this->available[0] ?? 'minimal';
    }

    /**
     * Set an injectable random resolver for deterministic unit testing
     */
    public function setRandomResolver(?callable $resolver): self
    {
        $this->randomResolver = $resolver;
        return $this;
    }

    /**
     * Sanitize template name against path traversal (rejects ../, slashes, non-alphanumeric chars)
     */
    public function sanitizeTemplateName(string $name): string
    {
        $name = trim($name);
        // Disallow path separators, dots, or null bytes
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..') || str_contains($name, "\0")) {
            return '';
        }
        // Strict whitelist: alphanumeric, dash, underscore
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    }

    /**
     * Safely set cookie without output headers warning in CLI/testing
     */
    protected function setCookie(string $name, string $value, int $expire): void
    {
        $_COOKIE[$name] = $value;
        if (!headers_sent() && php_sapi_name() !== 'cli') {
            setcookie($name, $value, [
                'expires'  => $expire,
                'path'     => '/',
                'domain'   => '',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
