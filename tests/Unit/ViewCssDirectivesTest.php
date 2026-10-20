<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\View;
use Ace\Paginator;

class ViewCssDirectivesTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $this->view = new View(
            dirname(__DIR__, 2) . '/views',
            dirname(__DIR__, 2) . '/storage/views'
        );
    }

    public function test_compile_tailwind_directive(): void
    {
        $template = "@tailwind";
        $compiled = $this->view->compileString($template);
        $this->assertStringContainsString('\Ace\View::renderTailwind()', $compiled);

        $html = View::renderTailwind();
        $this->assertStringContainsString('https://cdn.tailwindcss.com', $html);
        $this->assertEquals('tailwind', Paginator::getDefaultTheme());
    }

    public function test_compile_bootstrap_directive(): void
    {
        $template = "@bootstrap";
        $compiled = $this->view->compileString($template);
        $this->assertStringContainsString('\Ace\View::renderBootstrap()', $compiled);

        $html = View::renderBootstrap();
        $this->assertStringContainsString('bootstrap.min.css', $html);
        $this->assertStringContainsString('bootstrap.bundle.min.js', $html);
        $this->assertEquals('bootstrap', Paginator::getDefaultTheme());
    }

    public function test_compile_bootstrap_split_directives(): void
    {
        $template = "@bootstrapCss\n@bootstrapJs";
        $compiled = $this->view->compileString($template);
        $this->assertStringContainsString('\Ace\View::renderBootstrapCss()', $compiled);
        $this->assertStringContainsString('\Ace\View::renderBootstrapJs()', $compiled);

        $css = View::renderBootstrapCss();
        $this->assertStringContainsString('bootstrap.min.css', $css);

        $js = View::renderBootstrapJs();
        $this->assertStringContainsString('bootstrap.bundle.min.js', $js);
    }

    public function test_compile_css_directive_with_and_without_parameter(): void
    {
        $template1 = "@css('tailwind')";
        $compiled1 = $this->view->compileString($template1);
        $this->assertStringContainsString('\Ace\View::renderCssFramework(\'tailwind\')', $compiled1);

        $template2 = "@css('bootstrap')";
        $compiled2 = $this->view->compileString($template2);
        $this->assertStringContainsString('\Ace\View::renderCssFramework(\'bootstrap\')', $compiled2);

        $template3 = "@css";
        $compiled3 = $this->view->compileString($template3);
        $this->assertStringContainsString('\Ace\View::renderCssFramework()', $compiled3);
    }

    public function test_css_framework_helpers(): void
    {
        $this->assertStringContainsString('cdn.tailwindcss.com', tailwind_cdn());
        $this->assertStringContainsString('bootstrap.min.css', bootstrap_cdn());
        $this->assertStringContainsString('cdn.tailwindcss.com', css_framework('tailwind'));
        $this->assertStringContainsString('bootstrap.min.css', css_framework('bootstrap'));
        $this->assertEquals('', css_framework('none'));
    }
}
