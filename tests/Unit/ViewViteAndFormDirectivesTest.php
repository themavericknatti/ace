<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\View;

class ViewViteAndFormDirectivesTest extends TestCase
{
    protected View $view;
    protected string $tempViewsDir;
    protected string $tempCacheDir;

    protected function setUp(): void
    {
        $this->tempViewsDir = sys_get_temp_dir() . '/ace_views_test_' . uniqid();
        $this->tempCacheDir = sys_get_temp_dir() . '/ace_cache_test_' . uniqid();
        mkdir($this->tempViewsDir, 0755, true);
        mkdir($this->tempCacheDir, 0755, true);

        $this->view = new View($this->tempViewsDir, $this->tempCacheDir);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tempViewsDir);
        $this->cleanup($this->tempCacheDir);
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

    public function test_compile_boolean_form_directives(): void
    {
        $ref = new \ReflectionClass($this->view);
        $method = $ref->getMethod('compileString');
        $method->setAccessible(true);

        $template = '<input type="checkbox" @checked($isActive)>' .
                    '<option @selected($role === "admin")>Admin</option>' .
                    '<button @disabled($isDisabled)>Submit</button>' .
                    '<input @readonly($isReadOnly)>' .
                    '<input @required($isRequired)>';

        $compiled = $method->invoke($this->view, $template);

        $this->assertStringContainsString("<?php if(\$isActive): echo 'checked'; endif; ?>", $compiled);
        $this->assertStringContainsString("<?php if(\$role === \"admin\"): echo 'selected'; endif; ?>", $compiled);
        $this->assertStringContainsString("<?php if(\$isDisabled): echo 'disabled'; endif; ?>", $compiled);
        $this->assertStringContainsString("<?php if(\$isReadOnly): echo 'readonly'; endif; ?>", $compiled);
        $this->assertStringContainsString("<?php if(\$isRequired): echo 'required'; endif; ?>", $compiled);
    }

    public function test_compile_vite_directive(): void
    {
        $ref = new \ReflectionClass($this->view);
        $method = $ref->getMethod('compileString');
        $method->setAccessible(true);

        $template = "@vite('resources/css/app.css', 'resources/js/app.js')";
        $compiled = $method->invoke($this->view, $template);

        $this->assertStringContainsString("<?php echo \\Ace\\View::renderVite('resources/css/app.css', 'resources/js/app.js'); ?>", $compiled);
    }

    public function test_render_vite_fallback_tags(): void
    {
        $html = View::renderVite('resources/css/app.css', 'resources/js/app.js');

        $this->assertStringContainsString('<link rel="stylesheet" href="/resources/css/app.css">', $html);
        $this->assertStringContainsString('<script type="module" src="/resources/js/app.js"></script>', $html);
    }

    public function test_global_vite_helper(): void
    {
        $this->assertTrue(function_exists('vite'));
        $html = vite('resources/css/app.css');
        $this->assertStringContainsString('<link rel="stylesheet" href="/resources/css/app.css">', $html);
    }
}
