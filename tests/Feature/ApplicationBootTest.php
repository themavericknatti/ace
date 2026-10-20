<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Ace\Application;

class ApplicationBootTest extends TestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }

    public function test_application_boots_and_core_singletons_registered(): void
    {
        $this->assertInstanceOf(Application::class, Application::$app);
        $this->assertSame($this->app, app());
        $this->assertNotNull($this->app->router);
        $this->assertNotNull($this->app->request);
        $this->assertNotNull($this->app->response);
        $this->assertNotNull($this->app->session);
        $this->assertNotNull($this->app->view);
    }

    public function test_env_helper_function(): void
    {
        $_ENV['TEST_KEY'] = 'test-value';
        $this->assertEquals('test-value', env('TEST_KEY'));
        $this->assertEquals('default-fallback', env('NON_EXISTENT_KEY', 'default-fallback'));

        $_ENV['TEST_BOOL_TRUE'] = 'true';
        $this->assertTrue(env('TEST_BOOL_TRUE'));

        $_ENV['TEST_BOOL_FALSE'] = 'false';
        $this->assertFalse(env('TEST_BOOL_FALSE'));
    }

    public function test_method_field_helper_function(): void
    {
        $html = method_field('DELETE');
        $this->assertStringContainsString('name="_method"', $html);
        $this->assertStringContainsString('value="DELETE"', $html);
    }
}
