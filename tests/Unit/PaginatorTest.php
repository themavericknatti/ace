<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Paginator;
use Ace\View;

class PaginatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Paginator::resetStylesRendered();
        Paginator::useDefault();
    }

    public function test_basic_pagination_metrics(): void
    {
        $items = ['Item 1', 'Item 2', 'Item 3'];
        $p = new Paginator($items, 53, 10, 2, [
            'path' => '/items',
            'query' => ['category' => 'tech']
        ]);

        $this->assertEquals(53, $p->total());
        $this->assertEquals(10, $p->perPage());
        $this->assertEquals(2, $p->currentPage());
        $this->assertEquals(6, $p->lastPage());
        $this->assertTrue($p->hasPages());
        $this->assertTrue($p->hasMorePages());
        $this->assertFalse($p->onFirstPage());
        $this->assertFalse($p->onLastPage());
        $this->assertEquals(11, $p->firstItem());
        $this->assertEquals(13, $p->lastItem());
        $this->assertEquals(1, $p->previousPage());
        $this->assertEquals(3, $p->nextPage());
        $this->assertCount(3, $p->items());
    }

    public function test_first_and_last_page_edge_cases(): void
    {
        // First page
        $p1 = new Paginator(['A', 'B'], 25, 10, 1, ['path' => '/test']);
        $this->assertTrue($p1->onFirstPage());
        $this->assertFalse($p1->onLastPage());
        $this->assertNull($p1->previousPage());
        $this->assertNull($p1->previousPageUrl());
        $this->assertEquals(2, $p1->nextPage());

        // Last page
        $pLast = new Paginator(['Y', 'Z'], 25, 10, 3, ['path' => '/test']);
        $this->assertFalse($pLast->onFirstPage());
        $this->assertTrue($pLast->onLastPage());
        $this->assertFalse($pLast->hasMorePages());
        $this->assertEquals(2, $pLast->previousPage());
        $this->assertNull($pLast->nextPage());
        $this->assertNull($pLast->nextPageUrl());

        // Empty dataset
        $pEmpty = new Paginator([], 0, 10, 1, ['path' => '/test']);
        $this->assertFalse($pEmpty->hasPages());
        $this->assertNull($pEmpty->firstItem());
        $this->assertNull($pEmpty->lastItem());
        $this->assertEquals(1, $pEmpty->lastPage());
    }

    public function test_url_generation_preserves_query_and_fragments(): void
    {
        $p = new Paginator(['item'], 100, 10, 1, [
            'path' => '/products',
            'query' => ['filter' => 'popular', 'sort' => 'desc'],
            'fragment' => 'catalog'
        ]);

        $this->assertEquals('/products?filter=popular&sort=desc&page=1#catalog', $p->url(1));
        $this->assertEquals('/products?filter=popular&sort=desc&page=4#catalog', $p->url(4));

        // Fluent appends
        $p->appends(['theme' => 'dark']);
        $this->assertStringContainsString('theme=dark', $p->url(2));
        $this->assertStringContainsString('page=2', $p->url(2));
    }

    public function test_array_access_compatibility(): void
    {
        $data = ['Alpha', 'Beta'];
        $p = new Paginator($data, 45, 15, 2);

        $this->assertTrue(isset($p['data']));
        $this->assertTrue(isset($p['total']));
        $this->assertTrue(isset($p['current_page']));
        $this->assertTrue(isset($p['last_page']));
        $this->assertTrue(isset($p['next_page']));
        $this->assertTrue(isset($p['prev_page']));

        $this->assertEquals($data, $p['data']);
        $this->assertEquals(45, $p['total']);
        $this->assertEquals(15, $p['per_page']);
        $this->assertEquals(2, $p['current_page']);
        $this->assertEquals(3, $p['last_page']);
        $this->assertEquals(3, $p['next_page']);
        $this->assertEquals(1, $p['prev_page']);
        $this->assertEquals(16, $p['from']);
        $this->assertEquals(17, $p['to']);
    }

    public function test_countable_and_iterable_and_json_serializable(): void
    {
        $items = ['X', 'Y', 'Z'];
        $p = new Paginator($items, 30, 10, 1);

        // Countable
        $this->assertCount(3, $p);

        // Iterable
        $collected = [];
        foreach ($p as $item) {
            $collected[] = $item;
        }
        $this->assertEquals($items, $collected);

        // JsonSerializable
        $json = json_encode($p);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals(30, $decoded['total']);
        $this->assertEquals(1, $decoded['current_page']);
        $this->assertEquals($items, $decoded['data']);
    }

    public function test_sliding_window_elements(): void
    {
        // Small page count (e.g. 5 pages) shows all without ellipsis
        $pShort = new Paginator(['item'], 50, 10, 3);
        $this->assertEquals([1, 2, 3, 4, 5], $pShort->elements());

        // Many pages (20 pages), near beginning (page 2)
        $pStart = new Paginator(['item'], 200, 10, 2);
        $elementsStart = $pStart->elements();
        $this->assertContains('...', $elementsStart);
        $this->assertEquals(1, $elementsStart[0]);
        $this->assertEquals(20, end($elementsStart));

        // Many pages, in the middle (page 10)
        $pMid = new Paginator(['item'], 200, 10, 10);
        $elementsMid = $pMid->elements();
        // Middle should have two ellipses
        $ellipsisCount = count(array_filter($elementsMid, fn($e) => $e === '...'));
        $this->assertEquals(2, $ellipsisCount);
        $this->assertContains(10, $elementsMid);

        // Many pages, near the end (page 19)
        $pEnd = new Paginator(['item'], 200, 10, 19);
        $elementsEnd = $pEnd->elements();
        $this->assertContains('...', $elementsEnd);
        $this->assertEquals(20, end($elementsEnd));
    }

    public function test_default_responsive_rendering_contains_desktop_tablet_and_mobile_structures(): void
    {
        $p = new Paginator(['item1', 'item2'], 50, 10, 2, ['path' => '/events']);
        $html = $p->render();

        // Check wrapper and container
        $this->assertStringContainsString('class="ace-pagination"', $html);
        $this->assertStringContainsString('class="ace-pagination-container"', $html);

        // Check info section
        $this->assertStringContainsString('class="ace-pagination-info"', $html);
        $this->assertStringContainsString('Showing <span class="ace-pagination-highlight">11</span> to <span class="ace-pagination-highlight">12</span> of <span class="ace-pagination-highlight">50</span> results', $html);

        // Check desktop/tablet navigation list
        $this->assertStringContainsString('class="ace-pagination-nav"', $html);
        $this->assertStringContainsString('class="ace-pagination-list"', $html);

        // Check mobile controls
        $this->assertStringContainsString('class="ace-pagination-mobile"', $html);
        $this->assertStringContainsString('class="ace-pagination-mobile-btn"', $html);
        $this->assertStringContainsString('Page <strong>2</strong> of <strong>5</strong>', $html);

        // Check responsive stylesheet injection
        $this->assertStringContainsString('<style class="ace-pagination-default-styles">', $html);
        $this->assertStringContainsString('@media (max-width: 480px)', $html);
        $this->assertStringContainsString('@media (max-width: 768px)', $html);
    }

    public function test_custom_classes_override(): void
    {
        $p = new Paginator(['a'], 40, 10, 1, ['path' => '/test']);
        $html = $p->render([
            'with_styles' => false,
            'classes' => [
                'wrapper'   => 'my-custom-wrapper',
                'container' => 'my-custom-container',
                'link'      => 'custom-link-btn',
                'active'    => 'custom-active',
                'info'      => 'custom-info-text',
            ]
        ]);

        $this->assertStringContainsString('my-custom-wrapper', $html);
        $this->assertStringContainsString('my-custom-container', $html);
        $this->assertStringContainsString('custom-link-btn', $html);
        $this->assertStringContainsString('custom-active', $html);
        $this->assertStringContainsString('custom-info-text', $html);
        $this->assertStringNotContainsString('<style class="ace-pagination-default-styles">', $html);
    }

    public function test_custom_styles_and_colors(): void
    {
        Paginator::resetStylesRendered();
        $p = new Paginator(['a'], 40, 10, 1, ['path' => '/test']);
        $html = $p->render([
            'styles' => [
                'primary' => '#10b981',
                'bg' => '#222222',
            ]
        ]);

        $this->assertStringContainsString('--ace-pg-primary: #10b981;', $html);
        $this->assertStringContainsString('--ace-pg-bg: #222222;', $html);
    }

    public function test_bootstrap_and_tailwind_themes(): void
    {
        $p = new Paginator(['a', 'b'], 60, 10, 2, ['path' => '/users']);

        // Bootstrap
        $bsHtml = $p->render(['theme' => 'bootstrap']);
        $this->assertStringContainsString('class="pagination mb-0', $bsHtml);
        $this->assertStringContainsString('class="page-item active"', $bsHtml);
        $this->assertStringContainsString('class="page-link"', $bsHtml);

        // Tailwind
        $twHtml = $p->render(['theme' => 'tailwind']);
        $this->assertStringContainsString('border-t border-gray-200', $twHtml);
        $this->assertStringContainsString('isolate inline-flex -space-x-px', $twHtml);
        $this->assertStringContainsString('bg-indigo-600', $twHtml);
    }

    public function test_helper_functions(): void
    {
        $p = paginator(['x', 'y'], 20, 5, 2, ['path' => '/helper']);
        $this->assertInstanceOf(Paginator::class, $p);
        $this->assertEquals(4, $p->lastPage());

        $htmlFromPaginator = paginate_links($p, ['with_styles' => false]);
        $this->assertStringContainsString('class="ace-pagination"', $htmlFromPaginator);

        // Passing array representation
        $arrayData = [
            'data' => ['x', 'y'],
            'total' => 20,
            'per_page' => 5,
            'current_page' => 2,
        ];
        $htmlFromArray = paginate_links($arrayData, ['with_styles' => false]);
        $this->assertStringContainsString('class="ace-pagination"', $htmlFromArray);
    }

    public function test_view_blade_paginate_directive_compilation(): void
    {
        $view = new View(sys_get_temp_dir(), sys_get_temp_dir());
        $template = '@paginate($users)';
        $compiled = $view->compileString($template);
        $this->assertStringContainsString('<?php echo paginate_links($users); ?>', $compiled);

        $templateWithOptions = '@paginate($users, [\'theme\' => \'tailwind\'])';
        $compiledWithOptions = $view->compileString($templateWithOptions);
        $this->assertStringContainsString('<?php echo paginate_links($users, [\'theme\' => \'tailwind\']); ?>', $compiledWithOptions);
    }
}
