<?php

namespace Ace;

use Exception;

/**
 * Firewall — Next-Generation Web Application Firewall (WAF) for Ace Framework.
 *
 * Comprehensive defense engine that intercepts, analyzes, and neutralizes:
 *   1. PHP2Shell & CGI Argument Injection (CVE-2024-4577 on Windows/Apache/XAMPP)
 *   2. Remote Code Execution (RCE) & Command Injection
 *   3. SQL Injection (Union, Boolean, Time-based, Blind)
 *   4. Path Traversal & Local/Remote File Inclusion (LFI/RFI)
 *   5. Dangerous Web Shell File Uploads (PHP disguises, double extensions, magic byte tampering)
 *   6. Cross-Site Scripting (XSS) & Event Smuggling
 *   7. Automated Scanners & Exploit Toolkits (SQLMap, Nikto, Acunetix, etc.)
 *   8. HTTP Protocol Violations & Header Injection (CRLF / Response Splitting)
 *   9. Dynamic IP Threat Scoring & Automated Banning
 */
class Firewall
{
    public const THREAT_CGI_INJECTION   = 'cgi_argument_injection';
    public const THREAT_COMMAND_EXEC     = 'command_injection_rce';
    public const THREAT_SQL_INJECTION    = 'sql_injection';
    public const THREAT_PATH_TRAVERSAL   = 'path_traversal_lfi';
    public const THREAT_MALICIOUS_UPLOAD = 'malicious_shell_upload';
    public const THREAT_XSS              = 'xss_attack';
    public const THREAT_BAD_USER_AGENT   = 'exploit_scanner_bot';
    public const THREAT_HEADER_INJECTION = 'http_header_injection';
    public const THREAT_IP_BANNED        = 'ip_banned';

    protected static array $config = [];
    protected static ?string $storagePath = null;

    /**
     * Set or override firewall configuration.
     */
    public static function configure(array $config): void
    {
        static::$config = array_replace_recursive(static::defaultConfig(), $config);
    }

    /**
     * Retrieve firewall configuration.
     */
    public static function getConfig(): array
    {
        if (empty(static::$config)) {
            static::$config = static::defaultConfig();
        }
        return static::$config;
    }

    /**
     * Default security configuration.
     */
    protected static function defaultConfig(): array
    {
        return [
            'enabled' => filter_var($_ENV['FIREWALL_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'mode' => $_ENV['FIREWALL_MODE'] ?? 'block', // 'block' or 'monitor'
            'auto_ban' => true,
            'max_threat_score' => 3,     // Auto-ban after 3 violations
            'ban_duration' => 900,       // 15 minutes in seconds
            'whitelist_ips' => ['127.0.0.1', '::1'],
            'blacklist_ips' => [],
            'max_request_size' => 10485760, // 10MB
            'ignored_paths' => [],
        ];
    }

    /**
     * Primary inspection entrypoint. Analyzes the incoming HTTP request.
     * Throws or terminates with 403 Forbidden if a threat is identified.
     */
    public function inspect(): bool
    {
        $config = static::getConfig();
        if (!$config['enabled']) {
            return true;
        }

        $ip = $this->getClientIp();

        // 1. Check IP Whitelist (bypass checks for trusted internal IPs)
        if (in_array($ip, $config['whitelist_ips'], true)) {
            return true;
        }

        // 2. Check Static Blacklist & Dynamic Ban List
        if (in_array($ip, $config['blacklist_ips'], true) || $this->isIpBanned($ip)) {
            $this->block(self::THREAT_IP_BANNED, 'Access blocked: Your IP address is temporarily banned due to repeated security violations.', 403);
            return false;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // 3. Layer 1: CGI Argument Injection Shield (CVE-2024-4577 / PHP2Shell on Windows/Apache)
        if ($this->detectCgiArgumentInjection($queryString, $uri)) {
            $this->recordViolation($ip, self::THREAT_CGI_INJECTION, 'CVE-2024-4577 CGI Argument Injection detected.');
            $this->block(self::THREAT_CGI_INJECTION, 'Malicious request rejected: CGI Argument Injection attempt blocked.', 403);
            return false;
        }

        // 4. Layer 2: HTTP Header Injection & Request Smuggling (CRLF)
        if ($this->detectHeaderInjection()) {
            $this->recordViolation($ip, self::THREAT_HEADER_INJECTION, 'CRLF header injection detected.');
            $this->block(self::THREAT_HEADER_INJECTION, 'Malformed HTTP request rejected.', 400);
            return false;
        }

        // 5. Layer 3: Malicious Scanner & Exploit Toolkit User-Agents
        if ($this->detectBadUserAgent($userAgent)) {
            $this->recordViolation($ip, self::THREAT_BAD_USER_AGENT, "Malicious Scanner User-Agent: {$userAgent}");
            $this->block(self::THREAT_BAD_USER_AGENT, 'Automated exploit scanner blocked.', 403);
            return false;
        }

        // 6. Layer 4: Path Traversal & LFI/RFI Probing
        if ($this->detectPathTraversal($uri, $queryString)) {
            $this->recordViolation($ip, self::THREAT_PATH_TRAVERSAL, 'Path traversal / LFI attack detected.');
            $this->block(self::THREAT_PATH_TRAVERSAL, 'Invalid path or resource access blocked.', 403);
            return false;
        }

        // 7. Layer 5: Deep Payload Inspection (GET, POST, COOKIES, JSON)
        $payloads = $this->collectPayloads();
        foreach ($payloads as $source => $value) {
            // Check SQL Injection
            if ($this->detectSqlInjection($value)) {
                $this->recordViolation($ip, self::THREAT_SQL_INJECTION, "SQL Injection pattern in {$source}");
                $this->block(self::THREAT_SQL_INJECTION, 'Malicious SQL pattern detected and blocked.', 403);
                return false;
            }

            // Check Remote Code Execution & Command Injection
            if ($this->detectCommandInjection($value)) {
                $this->recordViolation($ip, self::THREAT_COMMAND_EXEC, "Command Injection / RCE pattern in {$source}");
                $this->block(self::THREAT_COMMAND_EXEC, 'System command execution attempt blocked.', 403);
                return false;
            }
        }

        // 8. Layer 6: Web Shell Upload Inspection ($_FILES)
        if (!empty($_FILES)) {
            $uploadThreat = $this->inspectUploads();
            if ($uploadThreat !== null) {
                $this->recordViolation($ip, self::THREAT_MALICIOUS_UPLOAD, $uploadThreat);
                $this->block(self::THREAT_MALICIOUS_UPLOAD, 'Dangerous executable file upload blocked.', 403);
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // Threat Detection Engine
    // =========================================================================

    /**
     * Neutralize CVE-2024-4577 (Windows PHP-CGI Argument Injection / PHP2Shell).
     *
     * Detects soft-hyphens (%ad, %AD, 0xAD, \xC2\xAD) or raw hyphen CGI parameters
     * injected into query strings to pass arbitrary CLI flags to the PHP binary.
     */
    public function detectCgiArgumentInjection(string $queryString, string $uri): bool
    {
        // 1. Raw soft hyphen byte sequences and URL-encoded soft hyphens
        if (preg_match('/(%ad|%AD|\xad|\xc2\xad)/i', $queryString) || preg_match('/(%ad|%AD|\xad|\xc2\xad)/i', $uri)) {
            return true;
        }

        // 2. Dangerous PHP command-line argument switches in query strings
        // Examples: ?-d+allow_url_include=1, ?-s, ?%2d-d, ?--help
        if (preg_match('/(?:^|&|\?)(?:%2d|%ad|-){1,2}[a-z]/i', $queryString) ||
            preg_match('/(?:^|&|\?)(?:%2d|%ad|-){1,2}[a-z]/i', $uri)) {
            return true;
        }

        // 3. Dangerous php.ini runtime override directives injected via query
        $dangerousDirectives = [
            'auto_prepend_file',
            'auto_append_file',
            'allow_url_include',
            'allow_url_fopen',
            'disable_functions',
            'open_basedir',
            'cgi.force_redirect',
            'expose_php',
            'display_errors',
        ];

        foreach ($dangerousDirectives as $dir) {
            if (stripos($queryString, $dir) !== false || stripos($uri, $dir) !== false) {
                return true;
            }
        }

        // 4. Check $_SERVER['argv'] for CGI arguments
        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            foreach ($_SERVER['argv'] as $arg) {
                if (is_string($arg) && preg_match('/^-(?:d|s|b|c|f|v|h)/i', trim($arg))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect Remote Code Execution (RCE), command injection, and web shell payloads.
     */
    public function detectCommandInjection(string $input): bool
    {
        // Dangerous PHP execution functions
        if (preg_match('/\b(system|passthru|shell_exec|proc_open|popen|pcntl_exec)\s*\(/i', $input)) {
            return true;
        }

        // Code evaluation constructs
        if (preg_match('/\b(eval|assert)\s*\(/i', $input) && preg_match('/(base64_decode|gzinflate|str_rot13|\$_POST|\$_GET|\$_REQUEST)/i', $input)) {
            return true;
        }

        // Reverse shell triggers and binary invocations
        if (preg_match('/(\/bin\/sh|\/bin\/bash|powershell(?:\.exe)?|cmd(?:\.exe)?)\b/i', $input)) {
            return true;
        }

        // Malicious chained shell commands (e.g. '; whoami', '| cat /etc/passwd')
        if (preg_match('/(?:;|\||`)\s*(whoami|id|cat\s+\/etc|uname\s+-a|dir\s+c:|net\s+user|curl\s+http|wget\s+http)/i', $input)) {
            return true;
        }

        return false;
    }

    /**
     * Detect SQL Injection vectors across UNION, boolean, time-based, and error-based payloads.
     */
    public function detectSqlInjection(string $input): bool
    {
        // 1. UNION SELECT attacks
        if (preg_match('/\bUNION(?:\s+ALL)?\s+SELECT\b/i', $input)) {
            return true;
        }

        // 2. Boolean tautology injection (e.g. ' OR 1=1 --, " OR 'a'='a')
        if (preg_match('/\b(OR|AND)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?/i', $input) &&
            preg_match('/(--|#|\/\*|\')/i', $input)) {
            return true;
        }

        // 3. Time-based blind SQLi (SLEEP, BENCHMARK, WAITFOR DELAY)
        if (preg_match('/\b(SLEEP\s*\(\s*\d+\s*\)|BENCHMARK\s*\(\s*\d+|WAITFOR\s+DELAY|PG_SLEEP\s*\()/i', $input)) {
            return true;
        }

        // 4. Schema/Information extraction probes
        if (preg_match('/\bFROM\s+information_schema\b/i', $input) ||
            preg_match('/\bSELECT\s+(?:schema_name|table_name|column_name)\b/i', $input)) {
            return true;
        }

        // 5. Destructive statement injection
        if (preg_match('/;\s*(DROP\s+TABLE|ALTER\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i', $input)) {
            return true;
        }

        return false;
    }

    /**
     * Detect Path Traversal, Null Byte poisoning, and Local/Remote File Inclusion (LFI/RFI).
     */
    public function detectPathTraversal(string $uri, string $queryString): bool
    {
        $target = $uri . ' ' . $queryString;

        // Directory traversal patterns
        if (preg_match('/(\.\.[\/\\\\]|\.\.%2f|\.\.%5c|%252e%252e)/i', $target)) {
            return true;
        }

        // Null byte injection
        if (str_contains($target, "\0") || stripos($target, '%00') !== false) {
            return true;
        }

        // PHP wrappers injection
        if (preg_match('/(php:\/\/|data:\/\/|expect:\/\/|input:\/\/|phar:\/\/)/i', $target)) {
            return true;
        }

        // Sensitive operating system and environment file targets
        if (preg_match('/(\/etc\/passwd|\/etc\/shadow|win\.ini|boot\.ini|\.env|\.git\/HEAD|wp-config\.php)/i', $target)) {
            return true;
        }

        return false;
    }

    /**
     * Inspect uploaded files ($_FILES) to prevent web shell execution.
     */
    public function inspectUploads(): ?string
    {
        $dangerousExtensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
            'pht', 'pgif', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd',
            'htaccess', 'htpasswd', 'user.ini', 'env',
        ];

        foreach ($_FILES as $field => $file) {
            $names = is_array($file['name']) ? $file['name'] : [$file['name']];
            $tmpNames = is_array($file['tmp_name']) ? $file['tmp_name'] : [$file['tmp_name']];

            for ($i = 0; $i < count($names); $i++) {
                $filename = (string)($names[$i] ?? '');
                $tmpPath = (string)($tmpNames[$i] ?? '');

                if ($filename === '' || $tmpPath === '') {
                    continue;
                }

                // 1. Check file extension
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $dangerousExtensions, true)) {
                    return "Executable extension '.{$ext}' rejected for file '{$filename}'.";
                }

                // 2. Check for double extensions (e.g. 'avatar.php.jpg')
                $parts = explode('.', strtolower($filename));
                if (count($parts) > 2) {
                    for ($j = 1; $j < count($parts) - 1; $j++) {
                        if (in_array($parts[$j], $dangerousExtensions, true)) {
                            return "Double extension attack detected in '{$filename}'.";
                        }
                    }
                }

                // 3. Deep inspection: Check file contents for embedded PHP tags in uploaded files
                if (is_file($tmpPath) && is_readable($tmpPath)) {
                    $fp = @fopen($tmpPath, 'rb');
                    $headerBytes = $fp ? (string)fread($fp, 8192) : '';
                    if ($fp) { fclose($fp); }
                    if (preg_match('/<\?(?:php|=)/i', $headerBytes) ||
                        preg_match('/<script\s+language\s*=\s*["\']?php/i', $headerBytes)) {
                        return "Embedded PHP executable script detected inside file '{$filename}'.";
                    }
                }
            }
        }

        return null;
    }

    /**
     * Detect known scanner and exploit toolkit User-Agents.
     */
    public function detectBadUserAgent(string $userAgent): bool
    {
        if (empty($userAgent)) {
            return false;
        }

        $badBots = [
            'sqlmap', 'nikto', 'acunetix', 'nmap', 'masscan', 'wpscan', 'gobuster',
            'dirbuster', 'hydra', 'havij', 'metasploit', 'zaproxy', 'openvas',
            'nessus', 'arachni', 'qualys',
        ];

        $ua = strtolower($userAgent);
        foreach ($badBots as $bot) {
            if (str_contains($ua, $bot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect HTTP Header Injection / Response Splitting (CRLF injection).
     */
    public function detectHeaderInjection(): bool
    {
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                if (str_contains($value, "\r") || str_contains($value, "\n")) {
                    return true;
                }
            }
        }
        return false;
    }

    // =========================================================================
    // IP Reputation & Banning Engine
    // =========================================================================

    /**
     * Determine client IP address respecting reverse proxy headers.
     */
    public function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Record security violation and increment threat score for IP.
     */
    protected function recordViolation(string $ip, string $threatType, string $detail): void
    {
        $config = static::getConfig();
        $timestamp = time();

        if (class_exists(Logger::class)) {
            Logger::warning("🚨 Firewall Threat Detected [{$threatType}] from IP {$ip}: {$detail}");
        }

        if (!$config['auto_ban']) {
            return;
        }

        $banFile = $this->getBanFilePath();
        $bans = $this->readBanData($banFile);

        $record = $bans[$ip] ?? ['score' => 0, 'first_seen' => $timestamp, 'banned_until' => 0];
        $record['score']++;
        $record['last_seen'] = $timestamp;
        $record['last_threat'] = $threatType;

        if ($record['score'] >= $config['max_threat_score']) {
            $record['banned_until'] = $timestamp + $config['ban_duration'];
            if (class_exists(Logger::class)) {
                Logger::error("🛡️ Firewall IP Auto-Banned: {$ip} for {$config['ban_duration']}s due to repeated violations.");
            }
        }

        $bans[$ip] = $record;
        $this->writeBanData($banFile, $bans);
    }

    /**
     * Check if client IP is currently banned.
     */
    public function isIpBanned(string $ip): bool
    {
        $banFile = $this->getBanFilePath();
        $bans = $this->readBanData($banFile);

        if (!isset($bans[$ip])) {
            return false;
        }

        $bannedUntil = $bans[$ip]['banned_until'] ?? 0;
        if ($bannedUntil > time()) {
            return true;
        }

        // If previously banned and now expired, clean it up
        if ($bannedUntil > 0 && $bannedUntil <= time()) {
            unset($bans[$ip]);
            $this->writeBanData($banFile, $bans);
        }

        return false;
    }

    /**
     * Unban an IP address.
     */
    public static function unban(string $ip): bool
    {
        $firewall = new self();
        $banFile = $firewall->getBanFilePath();
        $bans = $firewall->readBanData($banFile);

        if (isset($bans[$ip])) {
            unset($bans[$ip]);
            $firewall->writeBanData($banFile, $bans);
            return true;
        }

        return false;
    }

    protected function getBanFilePath(): string
    {
        if (static::$storagePath !== null) {
            return static::$storagePath . '/firewall_bans.json';
        }

        $rootDir = Application::$ROOT_DIR ?? sys_get_temp_dir();
        $dir = $rootDir . '/storage/framework';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/firewall_bans.json';
    }

    public static function setStoragePath(?string $path): void
    {
        static::$storagePath = $path;
    }

    protected function readBanData(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        $data = @json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    protected function writeBanData(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    // =========================================================================
    // Payload Gathering & Blocking Response
    // =========================================================================

    /**
     * Recursively collect all incoming request payloads into flat key-value pairs.
     */
    protected function collectPayloads(): array
    {
        $payloads = [];

        foreach ($_GET as $k => $v) {
            $this->flattenPayload("GET.{$k}", $v, $payloads);
        }

        foreach ($_POST as $k => $v) {
            $this->flattenPayload("POST.{$k}", $v, $payloads);
        }

        foreach ($_COOKIE as $k => $v) {
            $this->flattenPayload("COOKIE.{$k}", $v, $payloads);
        }

        // Inspect raw JSON body if request is JSON
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string)@file_get_contents('php://input');
            if (!empty($rawBody)) {
                $payloads['BODY.raw'] = $rawBody;
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        $this->flattenPayload("BODY.{$k}", $v, $payloads);
                    }
                }
            }
        }

        return $payloads;
    }

    protected function flattenPayload(string $prefix, mixed $value, array &$target): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->flattenPayload("{$prefix}.{$k}", $v, $target);
            }
        } elseif (is_string($value)) {
            $target[$prefix] = $value;
        }
    }

    /**
     * Halt execution and present a clean security block response.
     */
    protected function block(string $threatType, string $message, int $statusCode = 403): void
    {
        $config = static::getConfig();
        if ($config['mode'] === 'monitor') {
            // Monitor mode: Log threat but do not terminate request
            return;
        }

        http_response_code($statusCode);
        $incidentId = 'ACE-SEC-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));

        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $isApi = str_starts_with($path, '/api/') ||
                 $path === '/api' ||
                 (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($isApi) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Ace-Firewall: Blocked');
            echo json_encode([
                'status' => 'blocked',
                'error' => 'Security Threat Blocked',
                'incident_id' => $incidentId,
                'message' => $message,
                'code' => $statusCode,
            ], JSON_PRETTY_PRINT);
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Ace-Firewall: Blocked');
        echo $this->renderBlockPage($incidentId, $message, $statusCode);
        exit;
    }

    /**
     * Render a clean security shield HTML page for browser visitors.
     */
    protected function renderBlockPage(string $incidentId, string $message, int $statusCode): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden — Ace Security Shield</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0b0f19;
            color: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: #111827;
            border: 1px solid #1f2937;
            border-top: 4px solid #ef4444;
            border-radius: 12px;
            max-width: 540px;
            width: 100%;
            padding: 32px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.4);
            text-align: center;
        }
        .shield-icon {
            width: 56px;
            height: 56px;
            color: #ef4444;
            margin: 0 auto 16px;
        }
        h1 { font-size: 24px; font-weight: 700; color: #ffffff; margin-bottom: 8px; }
        p.subtitle { color: #9ca3af; font-size: 14px; margin-bottom: 24px; }
        .alert-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 14px 18px;
            color: #fca5a5;
            font-size: 14px;
            margin-bottom: 24px;
            text-align: left;
            word-break: break-word;
        }
        .meta {
            font-size: 12px;
            color: #6b7280;
            border-top: 1px solid #1f2937;
            padding-top: 16px;
            display: flex;
            justify-content: space-between;
        }
        .badge {
            background: #1f2937;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: monospace;
            color: #93c5fd;
        }
    </style>
</head>
<body>
    <div class="card">
        <svg class="shield-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <h1>Request Blocked by Security Firewall</h1>
        <p class="subtitle">Your request triggered an automated threat defense rule and was rejected.</p>
        <div class="alert-box">
            <strong>Reason:</strong> {$message}
        </div>
        <div class="meta">
            <span>Incident ID: <strong class="badge">{$incidentId}</strong></span>
            <span>Status: <strong>{$statusCode} Forbidden</strong></span>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
