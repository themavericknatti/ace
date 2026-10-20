<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\TemplateManager;
use Ace\View;
use Ace\Application;
use Ace\Session;
use InvalidArgumentException;

class TemplateTest extends TestCase
{
    protected string $viewsDir;
    protected string $cacheDir;
    protected ?Session $originalSession = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewsDir = dirname(__DIR__, 2) . '/views';
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/views';

        // Clear session and cookies before each test
        $_SESSION = [];
        $_COOKIE = [];
        $_GET = [];

        // Boot minimal Application if not already available
        if (!isset(Application::$app)) {
            $rootDir = dirname(__DIR__, 2);
            new Application($rootDir, []);
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE = [];
        $_GET = [];
        unset($_ENV['TEMPLATES_ENABLED'], $_ENV['DEFAULT_TEMPLATE'], $_ENV['TEMPLATE_STRATEGY']);
        parent::tearDown();
    }

    // =========================================================================
    // 1. Backward Compatibility & Disabled State
    // =========================================================================

    public function test_when_disabled_standard_view_renders_unaltered(): void
    {
        $manager = new TemplateManager([
            'enabled' => false,
            'default' => 'minimal',
            'available' => ['minimal', 'premium'],
        ]);

        $view = new View($this->viewsDir, $this->cacheDir, $manager);

        // Standard home view exists in views/home.php
        $resolved = $view->resolveViewFile('home');
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('views/home.php', str_replace('\\', '/', $resolved));
    }

    public function test_disabled_by_default_preserves_existing_behavior(): void
    {
        $manager = new TemplateManager();
        $this->assertFalse($manager->isEnabled());
        $this->assertEquals('minimal', $manager->getDefault());
    }

    // =========================================================================
    // 2. Policy E: Fixed Default Template Selection
    // =========================================================================

    public function test_fixed_strategy_always_returns_configured_default(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'default' => 'premium',
            'strategy' => TemplateManager::STRATEGY_FIXED,
            'available' => ['minimal', 'premium', 'creative'],
        ]);

        $this->assertEquals('premium', $manager->resolve());

        // Multiple calls consistently return the same default
        $this->assertEquals('premium', $manager->resolve());
    }

    // =========================================================================
    // 3. Policy A: Session-Based Selection
    // =========================================================================

    public function test_session_strategy_stores_and_persists_template_across_requests(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'default' => 'minimal',
            'strategy' => TemplateManager::STRATEGY_SESSION,
            'available' => ['minimal', 'premium'],
        ]);

        // First resolution: stores into session
        $selected = $manager->resolve();
        $this->assertEquals('minimal', $selected);
        $this->assertEquals('minimal', Application::$app->session->get('__ace_template'));

        // Manually simulate existing session with a different template
        Application::$app->session->set('__ace_template', 'premium');

        // Create new manager instance simulating a subsequent HTTP request in the same session
        $nextRequestManager = new TemplateManager([
            'enabled' => true,
            'default' => 'minimal',
            'strategy' => TemplateManager::STRATEGY_SESSION,
            'available' => ['minimal', 'premium'],
        ]);

        $this->assertEquals('premium', $nextRequestManager->resolve());
    }

    public function test_different_sessions_can_have_different_templates(): void
    {
        // Session 1 receives 'minimal'
        $_SESSION['__ace_template'] = 'minimal';
        $manager1 = new TemplateManager([
            'enabled' => true,
            'strategy' => TemplateManager::STRATEGY_SESSION,
            'available' => ['minimal', 'premium'],
        ]);
        $this->assertEquals('minimal', $manager1->resolve());

        // Session 2 receives 'premium'
        $_SESSION['__ace_template'] = 'premium';
        $manager2 = new TemplateManager([
            'enabled' => true,
            'strategy' => TemplateManager::STRATEGY_SESSION,
            'available' => ['minimal', 'premium'],
        ]);
        $this->assertEquals('premium', $manager2->resolve());
    }

    // =========================================================================
    // 4. Policy B: Per-Visit Selection
    // =========================================================================

    public function test_visit_strategy_stays_stable_via_cookie(): void
    {
        $_COOKIE['ace_template_visit'] = 'creative';

        $manager = new TemplateManager([
            'enabled' => true,
            'strategy' => TemplateManager::STRATEGY_VISIT,
            'available' => ['minimal', 'premium', 'creative'],
        ]);

        $this->assertEquals('creative', $manager->resolve());
    }

    // =========================================================================
    // 5. Policy C: Persistent Visitor Assignment
    // =========================================================================

    public function test_persistent_strategy_reads_long_lived_cookie(): void
    {
        $_COOKIE['ace_template_visitor'] = 'premium';

        $manager = new TemplateManager([
            'enabled' => true,
            'strategy' => TemplateManager::STRATEGY_PERSISTENT,
            'available' => ['minimal', 'premium', 'creative'],
        ]);

        $this->assertEquals('premium', $manager->resolve());
    }

    // =========================================================================
    // 6. Policy D: Random Per-Request Selection (Deterministic)
    // =========================================================================

    public function test_random_strategy_with_injected_resolver_does_not_mutate_state(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'strategy' => TemplateManager::STRATEGY_RANDOM,
            'available' => ['minimal', 'premium', 'creative'],
        ]);

        // Inject deterministic sequence: call 1 -> creative, call 2 -> premium
        $calls = ['creative', 'premium'];
        $manager->setRandomResolver(function ($available) use (&$calls) {
            return array_shift($calls);
        });

        $this->assertEquals('creative', $manager->resolve());
        $this->assertEquals('premium', $manager->resolve());

        // Random strategy should NOT have written anything to the session
        $this->assertNull(Application::$app->session->get('__ace_template'));
    }

    // =========================================================================
    // 7. Explicit Overrides and Switching
    // =========================================================================

    public function test_explicit_runtime_override_takes_precedence(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'default' => 'minimal',
            'strategy' => TemplateManager::STRATEGY_FIXED,
            'available' => ['minimal', 'premium', 'creative'],
            'allow_overrides' => true,
        ]);

        $this->assertEquals('minimal', $manager->resolve());

        $manager->setOverride('creative');
        $this->assertEquals('creative', $manager->resolve());

        $manager->clearOverride();
        $this->assertEquals('minimal', $manager->resolve());
    }

    public function test_override_is_rejected_when_disabled(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'allow_overrides' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Template overrides are disabled");

        $manager->setOverride('premium');
    }

    public function test_switching_template_persists_to_session_and_cookie(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'strategy' => TemplateManager::STRATEGY_PERSISTENT,
            'available' => ['minimal', 'premium', 'creative'],
            'allow_overrides' => true,
        ]);

        $this->assertTrue($manager->set('premium'));
        $this->assertEquals('premium', $manager->resolve());
        $this->assertEquals('premium', Application::$app->session->get('__ace_template'));
        $this->assertEquals('premium', $_COOKIE['ace_template_visitor']);
    }

    public function test_reset_clears_assigned_template(): void
    {
        $_SESSION['__ace_template'] = 'premium';
        $_COOKIE['ace_template_visitor'] = 'premium';

        $manager = new TemplateManager([
            'enabled' => true,
            'strategy' => TemplateManager::STRATEGY_PERSISTENT,
            'available' => ['minimal', 'premium'],
        ]);

        $manager->reset();

        $this->assertNull(Application::$app->session->get('__ace_template'));
    }

    // =========================================================================
    // 8. Security & Invalid Inputs
    // =========================================================================

    public function test_path_traversal_attempts_are_sanitized(): void
    {
        $manager = new TemplateManager();
        $this->assertEquals('', $manager->sanitizeTemplateName('../etc/passwd'));
        $this->assertEquals('', $manager->sanitizeTemplateName('..\\windows\\system32'));
        $this->assertEquals('', $manager->sanitizeTemplateName("evil\0template"));
        $this->assertEquals('clean_template-123', $manager->sanitizeTemplateName('clean_template-123!@#$%'));
    }

    public function test_invalid_strategy_throws_exception(): void
    {
        $manager = new TemplateManager();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported template selection strategy");

        $manager->setStrategy('non_existent_policy');
    }

    public function test_unregistered_template_cannot_be_set(): void
    {
        $manager = new TemplateManager([
            'available' => ['minimal', 'premium'],
        ]);

        $this->assertFalse($manager->set('malicious_hacker_theme'));
    }

    // =========================================================================
    // 9. View File Resolution Order & Fallbacks
    // =========================================================================

    public function test_view_resolves_template_specific_view_when_present(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'default' => 'minimal',
            'available' => ['minimal', 'premium'],
        ]);

        $view = new View($this->viewsDir, $this->cacheDir, $manager);

        // views/templates/minimal/layouts/main.php was created by make:template minimal
        $resolved = $view->resolveViewFile('layouts/main');
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('views/templates/minimal/layouts/main.php', str_replace('\\', '/', $resolved));
    }

    public function test_missing_view_in_template_falls_back_to_root_views(): void
    {
        $manager = new TemplateManager([
            'enabled' => true,
            'default' => 'minimal',
            'available' => ['minimal', 'premium'],
        ]);

        $view = new View($this->viewsDir, $this->cacheDir, $manager);

        // 'home' does not exist in views/templates/minimal/home.php, so it falls back to views/home.php
        $resolved = $view->resolveViewFile('home');
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('views/home.php', str_replace('\\', '/', $resolved));
    }

    // =========================================================================
    // 10. Data Preservation: Session, Auth, and Business State Untouched
    // =========================================================================

    public function test_switching_template_preserves_cart_and_auth_session_data(): void
    {
        // Setup existing customer cart and user session
        $session = Application::$app->session;
        $session->set('user_id', 42);
        $session->set('user_role', 'customer');
        $session->set('cart', [
            'items' => [
                ['id' => 101, 'name' => 'Premium Shirt', 'qty' => 2, 'price' => 45.00],
            ],
            'subtotal' => 90.00,
        ]);

        $manager = new TemplateManager([
            'enabled' => true,
            'default' => 'minimal',
            'available' => ['minimal', 'premium', 'creative'],
        ]);

        // Switch templates multiple times
        $manager->set('premium');
        $this->assertEquals('premium', $manager->resolve());

        $manager->set('creative');
        $this->assertEquals('creative', $manager->resolve());

        // Verify cart and auth data are completely intact and untouched!
        $this->assertEquals(42, $session->get('user_id'));
        $this->assertEquals('customer', $session->get('user_role'));
        $cart = $session->get('cart');
        $this->assertCount(1, $cart['items']);
        $this->assertEquals('Premium Shirt', $cart['items'][0]['name']);
        $this->assertEquals(90.00, $cart['subtotal']);
    }
}
