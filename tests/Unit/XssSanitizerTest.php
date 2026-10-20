<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\XssSanitizer;

class XssSanitizerTest extends TestCase
{
    public function test_strips_script_tags(): void
    {
        $input = '<script>alert("hacked")</script>Hello World';
        $cleaned = XssSanitizer::clean($input);

        $this->assertEquals('Hello World', $cleaned);
    }

    public function test_strips_inline_event_handlers(): void
    {
        $input = '<img src="avatar.jpg" onerror="alert(1)">';
        $cleaned = XssSanitizer::clean($input);

        $this->assertStringNotContainsString('onerror', $cleaned);
        $this->assertStringNotContainsString('alert', $cleaned);
    }

    public function test_strips_javascript_pseudo_protocol(): void
    {
        $input = '<a href="javascript:alert(1)">Click me</a>';
        $cleaned = XssSanitizer::clean($input);

        $this->assertStringNotContainsString('javascript:', $cleaned);
    }

    public function test_strips_iframe_and_object_tags(): void
    {
        $input = '<iframe src="evil.com"></iframe><object data="bad.swf"></object>';
        $cleaned = XssSanitizer::clean($input);

        $this->assertStringNotContainsString('iframe', $cleaned);
        $this->assertStringNotContainsString('object', $cleaned);
    }

    public function test_recursively_cleans_nested_arrays(): void
    {
        $input = [
            'name' => 'John',
            'bio' => '<script>steal()</script>Developer',
            'links' => [
                'website' => 'javascript:evil()',
                'safe' => 'https://example.com'
            ]
        ];

        $cleaned = XssSanitizer::clean($input);

        $this->assertEquals('John', $cleaned['name']);
        $this->assertEquals('Developer', $cleaned['bio']);
        $this->assertStringNotContainsString('javascript:', $cleaned['links']['website']);
        $this->assertEquals('https://example.com', $cleaned['links']['safe']);
    }
}
