<?php

namespace Ace;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;
use Stringable;

class Paginator implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, Stringable
{
    /**
     * Items on the current page.
     */
    protected array $items = [];

    /**
     * Total number of items across all pages.
     */
    protected int $total = 0;

    /**
     * Number of items per page.
     */
    protected int $perPage = 15;

    /**
     * Current page number (1-based).
     */
    protected int $currentPage = 1;

    /**
     * Last page number (total pages).
     */
    protected int $lastPage = 1;

    /**
     * Query string parameter name for the page number.
     */
    protected string $pageName = 'page';

    /**
     * Base path for pagination URLs.
     */
    protected string $path = '/';

    /**
     * Query string parameters to append to URLs.
     */
    protected array $query = [];

    /**
     * URL fragment (e.g. #results).
     */
    protected string $fragment = '';

    /**
     * Number of links to display on each side of the current page.
     */
    protected int $onEachSide = 2;

    /**
     * Default theme for rendering ('default', 'bootstrap', 'tailwind', 'simple').
     */
    protected static string $defaultTheme = 'default';

    /**
     * Whether default CSS styles should be injected when rendering.
     */
    protected static bool $useStyles = true;

    /**
     * Track if styles have already been rendered in the current request.
     */
    protected static bool $stylesRendered = false;

    /**
     * Global custom view resolver callback or view name.
     */
    protected static ?string $defaultView = null;

    /**
     * Global default classes override.
     */
    protected static array $globalClasses = [];

    /**
     * Create a new Paginator instance.
     *
     * @param array $items Items on the current page
     * @param int $total Total items in dataset
     * @param int $perPage Number of items per page
     * @param int|null $currentPage Current page (defaults to auto-detection from request)
     * @param array $options Configuration options (path, pageName, query, fragment, etc.)
     */
    public function __construct(
        array $items,
        int $total,
        int $perPage = 15,
        ?int $currentPage = null,
        array $options = []
    ) {
        $this->items = array_values($items);
        $this->total = max(0, $total);
        $this->perPage = max(1, $perPage);

        $this->pageName = $options['pageName'] ?? $options['page_name'] ?? 'page';

        if ($currentPage === null) {
            $req = Application::$app->request ?? null;
            $rawPage = $req ? $req->get($this->pageName, 1) : ($_GET[$this->pageName] ?? 1);
            $currentPage = is_numeric($rawPage) ? (int)$rawPage : 1;
        }

        $this->lastPage = (int)ceil($this->total / $this->perPage);
        if ($this->lastPage < 1) {
            $this->lastPage = 1;
        }

        $this->currentPage = max(1, min($currentPage, $this->lastPage));

        // Resolve base path
        if (isset($options['path'])) {
            $this->path = $options['path'];
        } else {
            $this->path = $this->resolveCurrentPath();
        }

        // Initialize query parameters preserving current $_GET
        $this->query = $options['query'] ?? $_GET ?? [];
        unset($this->query[$this->pageName]);

        if (isset($options['fragment'])) {
            $this->fragment = $options['fragment'];
        }
    }

    /**
     * Resolve current request URL path.
     */
    protected function resolveCurrentPath(): string
    {
        $req = Application::$app->request ?? null;
        if ($req && method_exists($req, 'getPath')) {
            return $req->getPath();
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        return $pos !== false ? substr($uri, 0, $pos) : $uri;
    }

    // =========================================================================
    // Core Accessors
    // =========================================================================

    /**
     * Get the items on the current page.
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Alias for items().
     */
    public function data(): array
    {
        return $this->items;
    }

    /**
     * Get the total number of items.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Get the number of items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the current page number.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the last page number.
     */
    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    /**
     * Determine if there are more pages after the current page.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Determine if the paginator is on the first page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    /**
     * Determine if the paginator is on the last page.
     */
    public function onLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage;
    }

    /**
     * Get the 1-based index of the first item on the current page.
     */
    public function firstItem(): ?int
    {
        if ($this->total === 0 || empty($this->items)) {
            return null;
        }
        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    /**
     * Alias for firstItem().
     */
    public function from(): ?int
    {
        return $this->firstItem();
    }

    /**
     * Get the 1-based index of the last item on the current page.
     */
    public function lastItem(): ?int
    {
        if ($this->total === 0 || empty($this->items)) {
            return null;
        }
        return min($this->firstItem() + count($this->items) - 1, $this->total);
    }

    /**
     * Alias for lastItem().
     */
    public function to(): ?int
    {
        return $this->lastItem();
    }

    /**
     * Get the previous page number, or null if on first page.
     */
    public function previousPage(): ?int
    {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    /**
     * Get the next page number, or null if on last page.
     */
    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    // =========================================================================
    // URL Generation & Fluent Customization
    // =========================================================================

    /**
     * Get the URL for a given page number.
     */
    public function url(int $page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        $params = $this->query;
        $params[$this->pageName] = $page;

        $queryString = http_build_query($params);
        $url = $this->path;

        if ($queryString !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }

        if ($this->fragment !== '') {
            $url .= '#' . ltrim($this->fragment, '#');
        }

        return $url;
    }

    /**
     * Get URL for previous page, or null if on first page.
     */
    public function previousPageUrl(): ?string
    {
        $prev = $this->previousPage();
        return $prev !== null ? $this->url($prev) : null;
    }

    /**
     * Get URL for next page, or null if on last page.
     */
    public function nextPageUrl(): ?string
    {
        $next = $this->nextPage();
        return $next !== null ? $this->url($next) : null;
    }

    /**
     * Append query parameters to pagination links.
     *
     * @param array|string $key Query parameters array or key string
     * @param mixed $value Parameter value when key is string
     * @return $this
     */
    public function appends(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                if ($k === $this->pageName) continue;
                $this->query[$k] = $v;
            }
        } else {
            if ($key !== $this->pageName) {
                $this->query[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * Set the base URL path for links.
     */
    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Get the base URL path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set the URL fragment (e.g. #results).
     */
    public function fragment(string $fragment): self
    {
        $this->fragment = $fragment;
        return $this;
    }

    /**
     * Set the query parameter name for page number.
     */
    public function setPageName(string $name): self
    {
        $this->pageName = $name;
        return $this;
    }

    /**
     * Get the query parameter name for page number.
     */
    public function getPageName(): string
    {
        return $this->pageName;
    }

    /**
     * Set how many pages to display on each side of the current page.
     */
    public function onEachSide(int $count): self
    {
        $this->onEachSide = max(0, $count);
        return $this;
    }

    // =========================================================================
    // Sliding Window Algorithm (Elements Calculation)
    // =========================================================================

    /**
     * Generate an array of page numbers and ellipses ('...') for rendering.
     *
     * @return array Array of integers and '...' strings
     */
    public function elements(): array
    {
        if ($this->lastPage <= 1) {
            return [1];
        }

        $side = $this->onEachSide;
        $totalWindow = ($side * 2) + 5; // e.g., 2*2 + 5 = 9 pages

        // If total pages is small enough, show all pages without ellipsis
        if ($this->lastPage <= $totalWindow) {
            return range(1, $this->lastPage);
        }

        $elements = [];

        // Check window near start
        if ($this->currentPage <= (2 * $side + 1)) {
            $end = 2 * $side + 2;
            for ($i = 1; $i <= $end; $i++) {
                $elements[] = $i;
            }
            $elements[] = '...';
            $elements[] = $this->lastPage - 1;
            $elements[] = $this->lastPage;
            return $elements;
        }

        // Check window near end
        if ($this->currentPage >= ($this->lastPage - (2 * $side))) {
            $elements[] = 1;
            $elements[] = 2;
            $elements[] = '...';
            $start = $this->lastPage - (2 * $side + 1);
            for ($i = $start; $i <= $this->lastPage; $i++) {
                $elements[] = $i;
            }
            return $elements;
        }

        // Window in the middle
        $elements[] = 1;
        $elements[] = 2;
        $elements[] = '...';
        for ($i = $this->currentPage - $side; $i <= $this->currentPage + $side; $i++) {
            $elements[] = $i;
        }
        $elements[] = '...';
        $elements[] = $this->lastPage - 1;
        $elements[] = $this->lastPage;

        return $elements;
    }

    // =========================================================================
    // Rendering & Themes
    // =========================================================================

    /**
     * Render the pagination display HTML.
     *
     * @param array $options Configuration and styling options
     * @return string
     */
    public function render(array $options = []): string
    {
        // Check if custom view template requested
        $customView = $options['view'] ?? static::$defaultView;
        if ($customView && Application::$app && Application::$app->view) {
            return Application::$app->view->render($customView, array_merge($options, [
                'paginator'   => $this,
                'items'       => $this->items,
                'total'       => $this->total,
                'perPage'     => $this->perPage,
                'currentPage' => $this->currentPage,
                'lastPage'    => $this->lastPage,
                'elements'    => $this->elements(),
                'from'        => $this->firstItem(),
                'to'          => $this->lastItem(),
            ]));
        }

        $theme = $options['theme'] ?? static::$defaultTheme;

        return match ($theme) {
            'bootstrap' => $this->renderBootstrap($options),
            'tailwind'  => $this->renderTailwind($options),
            'simple'    => $this->renderSimple($options),
            default     => $this->renderDefault($options),
        };
    }

    /**
     * Alias for render().
     */
    public function links(array $options = []): string
    {
        return $this->render($options);
    }

    /**
     * Render the default responsive Ace Framework pagination display.
     */
    protected function renderDefault(array $options = []): string
    {
        // Don't render anything if dataset is empty and hide_empty is true (default false)
        if ($this->total === 0 && ($options['hide_empty'] ?? false)) {
            return '';
        }

        // Hide if only one page and hide_single_page option is set
        if (!$this->hasPages() && ($options['hide_single_page'] ?? false)) {
            return '';
        }

        $classes = array_merge(static::$globalClasses, $options['classes'] ?? []);
        $styles = $options['styles'] ?? [];
        $withStyles = $options['with_styles'] ?? static::$useStyles;
        $showInfo = $options['show_info'] ?? true;
        $showFirstLast = $options['show_first_last'] ?? true;
        $inlineStyle = isset($options['style']) ? ' style="' . htmlspecialchars($options['style']) . '"' : '';

        // Labels / Icons
        $prevText = $options['prev_text'] ?? '&lsaquo; Prev';
        $nextText = $options['next_text'] ?? 'Next &rsaquo;';
        $firstText = $options['first_text'] ?? '&laquo; First';
        $lastText = $options['last_text'] ?? 'Last &raquo;';

        $clsWrapper   = 'ace-pagination ' . ($classes['wrapper'] ?? '');
        $clsContainer = 'ace-pagination-container ' . ($classes['container'] ?? '');
        $clsInfo      = 'ace-pagination-info ' . ($classes['info'] ?? '');
        $clsNav       = 'ace-pagination-nav ' . ($classes['nav'] ?? '');
        $clsList      = 'ace-pagination-list ' . ($classes['list'] ?? '');
        $clsItem      = 'ace-pagination-item ' . ($classes['item'] ?? '');
        $clsLink      = 'ace-pagination-link ' . ($classes['link'] ?? '');
        $clsActive    = 'is-active ' . ($classes['active'] ?? '');
        $clsDisabled  = 'is-disabled ' . ($classes['disabled'] ?? '');
        $clsDots      = 'ace-pagination-dots ' . ($classes['dots'] ?? '');
        $clsMobile    = 'ace-pagination-mobile ' . ($classes['mobile'] ?? '');

        $output = '';

        // Inject default stylesheet if requested and not yet rendered
        if ($withStyles && !static::$stylesRendered) {
            $output .= $this->renderDefaultStyles($styles);
            static::$stylesRendered = true;
        }

        $output .= '<nav class="' . trim($clsWrapper) . '"' . $inlineStyle . ' aria-label="Pagination Navigation" role="navigation">';
        $output .= '<div class="' . trim($clsContainer) . '">';

        // 1. Summary Information
        if ($showInfo) {
            $output .= '<div class="' . trim($clsInfo) . '">';
            if ($this->total > 0) {
                $from = $this->firstItem();
                $to = $this->lastItem();
                $total = number_format($this->total);
                $output .= 'Showing <span class="ace-pagination-highlight">' . $from . '</span> to <span class="ace-pagination-highlight">' . $to . '</span> of <span class="ace-pagination-highlight">' . $total . '</span> results';
            } else {
                $output .= '<span>No records found</span>';
            }
            $output .= '</div>';
        }

        // 2. Desktop & Tablet Page Links
        $output .= '<div class="' . trim($clsNav) . '">';
        $output .= '<ul class="' . trim($clsList) . '">';

        // First Page Button
        if ($showFirstLast && $this->lastPage > 2) {
            if ($this->onFirstPage()) {
                $output .= '<li class="' . trim($clsItem . ' ' . $clsDisabled) . '"><span class="' . trim($clsLink) . '" aria-disabled="true">' . $firstText . '</span></li>';
            } else {
                $output .= '<li class="' . trim($clsItem) . '"><a href="' . htmlspecialchars($this->url(1)) . '" class="' . trim($clsLink) . '">' . $firstText . '</a></li>';
            }
        }

        // Previous Page Button
        if ($this->onFirstPage()) {
            $output .= '<li class="' . trim($clsItem . ' ' . $clsDisabled) . '"><span class="' . trim($clsLink) . '" aria-disabled="true" aria-label="Previous">' . $prevText . '</span></li>';
        } else {
            $output .= '<li class="' . trim($clsItem) . '"><a href="' . htmlspecialchars($this->previousPageUrl()) . '" class="' . trim($clsLink) . '" rel="prev" aria-label="Previous">' . $prevText . '</a></li>';
        }

        // Page Numbers & Ellipses
        foreach ($this->elements() as $element) {
            if (is_string($element)) {
                $output .= '<li class="' . trim($clsItem . ' ' . $clsDots) . '"><span class="' . trim($clsLink) . '" aria-hidden="true">&hellip;</span></li>';
            } elseif (is_int($element)) {
                if ($element === $this->currentPage) {
                    $output .= '<li class="' . trim($clsItem . ' ' . $clsActive) . '"><span class="' . trim($clsLink) . '" aria-current="page">' . $element . '</span></li>';
                } else {
                    $output .= '<li class="' . trim($clsItem) . '"><a href="' . htmlspecialchars($this->url($element)) . '" class="' . trim($clsLink) . '">' . $element . '</a></li>';
                }
            }
        }

        // Next Page Button
        if ($this->hasMorePages()) {
            $output .= '<li class="' . trim($clsItem) . '"><a href="' . htmlspecialchars($this->nextPageUrl()) . '" class="' . trim($clsLink) . '" rel="next" aria-label="Next">' . $nextText . '</a></li>';
        } else {
            $output .= '<li class="' . trim($clsItem . ' ' . $clsDisabled) . '"><span class="' . trim($clsLink) . '" aria-disabled="true" aria-label="Next">' . $nextText . '</span></li>';
        }

        // Last Page Button
        if ($showFirstLast && $this->lastPage > 2) {
            if ($this->onLastPage()) {
                $output .= '<li class="' . trim($clsItem . ' ' . $clsDisabled) . '"><span class="' . trim($clsLink) . '" aria-disabled="true">' . $lastText . '</span></li>';
            } else {
                $output .= '<li class="' . trim($clsItem) . '"><a href="' . htmlspecialchars($this->url($this->lastPage)) . '" class="' . trim($clsLink) . '">' . $lastText . '</a></li>';
            }
        }

        $output .= '</ul>';
        $output .= '</div>';

        // 3. Dedicated Mobile Controls (shown on small phone screens)
        $output .= '<div class="' . trim($clsMobile) . '">';
        if ($this->onFirstPage()) {
            $output .= '<span class="ace-pagination-mobile-btn is-disabled" aria-disabled="true">&larr; Prev</span>';
        } else {
            $output .= '<a href="' . htmlspecialchars($this->previousPageUrl()) . '" class="ace-pagination-mobile-btn" rel="prev">&larr; Prev</a>';
        }

        $output .= '<span class="ace-pagination-mobile-status">Page <strong>' . $this->currentPage . '</strong> of <strong>' . $this->lastPage . '</strong></span>';

        if ($this->hasMorePages()) {
            $output .= '<a href="' . htmlspecialchars($this->nextPageUrl()) . '" class="ace-pagination-mobile-btn" rel="next">Next &rarr;</a>';
        } else {
            $output .= '<span class="ace-pagination-mobile-btn is-disabled" aria-disabled="true">Next &rarr;</span>';
        }
        $output .= '</div>';

        $output .= '</div>';
        $output .= '</nav>';

        return $output;
    }

    /**
     * Render Bootstrap 5 compatible pagination markup.
     */
    protected function renderBootstrap(array $options = []): string
    {
        $classes = array_merge(static::$globalClasses, $options['classes'] ?? []);
        $showInfo = $options['show_info'] ?? true;
        $size = isset($options['size']) ? ' pagination-' . $options['size'] : ''; // sm, lg

        $prevText = $options['prev_text'] ?? '&laquo;';
        $nextText = $options['next_text'] ?? '&raquo;';

        $output = '<nav aria-label="Page navigation" class="' . ($classes['wrapper'] ?? 'my-3') . '">';
        $output .= '<div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">';

        if ($showInfo && $this->total > 0) {
            $output .= '<div class="text-muted small ' . ($classes['info'] ?? '') . '">';
            $output .= 'Showing ' . $this->firstItem() . ' to ' . $this->lastItem() . ' of ' . number_format($this->total) . ' results';
            $output .= '</div>';
        }

        $output .= '<ul class="pagination mb-0' . $size . ' ' . ($classes['list'] ?? '') . '">';

        // Previous
        if ($this->onFirstPage()) {
            $output .= '<li class="page-item disabled"><span class="page-link" aria-disabled="true">' . $prevText . '</span></li>';
        } else {
            $output .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($this->previousPageUrl()) . '" rel="prev">' . $prevText . '</a></li>';
        }

        // Elements
        foreach ($this->elements() as $element) {
            if (is_string($element)) {
                $output .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            } elseif (is_int($element)) {
                if ($element === $this->currentPage) {
                    $output .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $element . '</span></li>';
                } else {
                    $output .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($this->url($element)) . '">' . $element . '</a></li>';
                }
            }
        }

        // Next
        if ($this->hasMorePages()) {
            $output .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($this->nextPageUrl()) . '" rel="next">' . $nextText . '</a></li>';
        } else {
            $output .= '<li class="page-item disabled"><span class="page-link" aria-disabled="true">' . $nextText . '</span></li>';
        }

        $output .= '</ul>';
        $output .= '</div>';
        $output .= '</nav>';

        return $output;
    }

    /**
     * Render Tailwind CSS compatible pagination markup.
     */
    protected function renderTailwind(array $options = []): string
    {
        $showInfo = $options['show_info'] ?? true;
        $classes = array_merge(static::$globalClasses, $options['classes'] ?? []);

        $output = '<nav role="navigation" aria-label="Pagination" class="flex items-center justify-between border-t border-gray-200 dark:border-gray-700 bg-transparent px-4 py-3 sm:px-6 ' . ($classes['wrapper'] ?? '') . '">';

        // Mobile Prev/Next
        $output .= '<div class="flex flex-1 justify-between sm:hidden">';
        if ($this->onFirstPage()) {
            $output .= '<span class="relative inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-800 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed">Previous</span>';
        } else {
            $output .= '<a href="' . htmlspecialchars($this->previousPageUrl()) . '" class="relative inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50">Previous</a>';
        }

        if ($this->hasMorePages()) {
            $output .= '<a href="' . htmlspecialchars($this->nextPageUrl()) . '" class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50">Next</a>';
        } else {
            $output .= '<span class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-800 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed">Next</span>';
        }
        $output .= '</div>';

        // Desktop / Tablet layout
        $output .= '<div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">';
        if ($showInfo && $this->total > 0) {
            $output .= '<div><p class="text-sm text-gray-600 dark:text-gray-300">';
            $output .= 'Showing <span class="font-medium">' . $this->firstItem() . '</span> to <span class="font-medium">' . $this->lastItem() . '</span> of <span class="font-medium">' . number_format($this->total) . '</span> results';
            $output .= '</p></div>';
        }

        $output .= '<div><nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">';

        // Prev link
        if ($this->onFirstPage()) {
            $output .= '<span class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 dark:ring-gray-600 cursor-not-allowed">&lsaquo;</span>';
        } else {
            $output .= '<a href="' . htmlspecialchars($this->previousPageUrl()) . '" class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700 ring-1 ring-inset ring-gray-300 dark:ring-gray-600">&lsaquo;</a>';
        }

        foreach ($this->elements() as $element) {
            if (is_string($element)) {
                $output .= '<span class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-500 ring-1 ring-inset ring-gray-300 dark:ring-gray-600">&hellip;</span>';
            } elseif (is_int($element)) {
                if ($element === $this->currentPage) {
                    $output .= '<span aria-current="page" class="relative z-10 inline-flex items-center bg-indigo-600 px-4 py-2 text-sm font-semibold text-white focus:z-20">' . $element . '</span>';
                } else {
                    $output .= '<a href="' . htmlspecialchars($this->url($element)) . '" class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 dark:text-gray-200 ring-1 ring-inset ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">' . $element . '</a>';
                }
            }
        }

        // Next link
        if ($this->hasMorePages()) {
            $output .= '<a href="' . htmlspecialchars($this->nextPageUrl()) . '" class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700 ring-1 ring-inset ring-gray-300 dark:ring-gray-600">&rsaquo;</a>';
        } else {
            $output .= '<span class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 dark:ring-gray-600 cursor-not-allowed">&rsaquo;</span>';
        }

        $output .= '</nav></div>';
        $output .= '</div>';
        $output .= '</nav>';

        return $output;
    }

    /**
     * Render a simple Previous / Next minimal pagination bar.
     */
    protected function renderSimple(array $options = []): string
    {
        $classes = array_merge(static::$globalClasses, $options['classes'] ?? []);
        $prevText = $options['prev_text'] ?? '&larr; Previous';
        $nextText = $options['next_text'] ?? 'Next &rarr;';

        $output = '<nav class="ace-pagination-simple ' . ($classes['wrapper'] ?? '') . '" role="navigation" aria-label="Pagination">';
        $output .= '<div class="d-flex justify-content-between align-items-center" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">';

        if ($this->onFirstPage()) {
            $output .= '<span class="is-disabled" style="opacity:0.5;pointer-events:none;">' . $prevText . '</span>';
        } else {
            $output .= '<a href="' . htmlspecialchars($this->previousPageUrl()) . '" rel="prev">' . $prevText . '</a>';
        }

        $output .= '<span class="page-status">Page ' . $this->currentPage . ' of ' . $this->lastPage . '</span>';

        if ($this->hasMorePages()) {
            $output .= '<a href="' . htmlspecialchars($this->nextPageUrl()) . '" rel="next">' . $nextText . '</a>';
        } else {
            $output .= '<span class="is-disabled" style="opacity:0.5;pointer-events:none;">' . $nextText . '</span>';
        }

        $output .= '</div>';
        $output .= '</nav>';

        return $output;
    }

    /**
     * Generate the scoped responsive CSS style block for the default Ace theme.
     * Supports overriding colors and variables via the $styles array.
     */
    public function renderDefaultStyles(array $customStyles = []): string
    {
        $primary = $customStyles['primary'] ?? $customStyles['primary_color'] ?? '#6366f1';
        $primaryText = $customStyles['primary_text'] ?? '#ffffff';
        $bg = $customStyles['bg'] ?? $customStyles['background'] ?? '#1e293b';
        $surface = $customStyles['surface'] ?? '#0f172a';
        $border = $customStyles['border'] ?? '#334155';
        $borderHover = $customStyles['border_hover'] ?? $primary;
        $text = $customStyles['text'] ?? '#e2e8f0';
        $textMuted = $customStyles['text_muted'] ?? '#94a3b8';
        $disabled = $customStyles['disabled'] ?? '#64748b';
        $radius = $customStyles['radius'] ?? '0.5rem';

        return <<<HTML
<style class="ace-pagination-default-styles">
    :root {
        --ace-pg-primary: {$primary};
        --ace-pg-primary-text: {$primaryText};
        --ace-pg-bg: {$bg};
        --ace-pg-surface: {$surface};
        --ace-pg-border: {$border};
        --ace-pg-border-hover: {$borderHover};
        --ace-pg-text: {$text};
        --ace-pg-text-muted: {$textMuted};
        --ace-pg-disabled: {$disabled};
        --ace-pg-radius: {$radius};
    }

    .ace-pagination {
        width: 100%;
        margin: 1.5rem 0;
        font-family: inherit;
        box-sizing: border-box;
    }

    .ace-pagination *, .ace-pagination *::before, .ace-pagination *::after {
        box-sizing: border-box;
    }

    .ace-pagination-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        padding: 0.75rem 1rem;
        background: var(--ace-pg-surface);
        border: 1px solid var(--ace-pg-border);
        border-radius: var(--ace-pg-radius);
    }

    .ace-pagination-info {
        font-size: 0.875rem;
        color: var(--ace-pg-text-muted);
        line-height: 1.5;
    }

    .ace-pagination-highlight {
        font-weight: 600;
        color: var(--ace-pg-text);
    }

    .ace-pagination-nav {
        display: flex;
        align-items: center;
    }

    .ace-pagination-list {
        display: flex;
        align-items: center;
        list-style: none;
        margin: 0;
        padding: 0;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .ace-pagination-item {
        display: inline-flex;
    }

    .ace-pagination-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2.25rem;
        height: 2.25rem;
        padding: 0 0.65rem;
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--ace-pg-text);
        background: var(--ace-pg-bg);
        border: 1px solid var(--ace-pg-border);
        border-radius: var(--ace-pg-radius);
        text-decoration: none;
        transition: all 0.2s ease-in-out;
        cursor: pointer;
        user-select: none;
    }

    a.ace-pagination-link:hover {
        color: var(--ace-pg-primary-text);
        background: var(--ace-pg-primary);
        border-color: var(--ace-pg-primary);
        transform: translateY(-1px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
    }

    a.ace-pagination-link:active {
        transform: translateY(0);
    }

    .ace-pagination-item.is-active .ace-pagination-link {
        color: var(--ace-pg-primary-text);
        background: var(--ace-pg-primary);
        border-color: var(--ace-pg-primary);
        font-weight: 700;
        cursor: default;
        box-shadow: 0 2px 4px rgba(99, 102, 241, 0.3);
    }

    .ace-pagination-item.is-disabled .ace-pagination-link {
        color: var(--ace-pg-disabled);
        background: transparent;
        border-color: transparent;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .ace-pagination-dots .ace-pagination-link {
        background: transparent;
        border-color: transparent;
        color: var(--ace-pg-disabled);
        cursor: default;
    }

    /* Dedicated Mobile View - Hidden on Desktop & Tablet */
    .ace-pagination-mobile {
        display: none;
    }

    .ace-pagination-mobile-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--ace-pg-text);
        background: var(--ace-pg-bg);
        border: 1px solid var(--ace-pg-border);
        border-radius: var(--ace-pg-radius);
        text-decoration: none;
        transition: background 0.2s, border-color 0.2s;
        min-height: 2.5rem;
    }

    a.ace-pagination-mobile-btn:hover {
        background: var(--ace-pg-primary);
        color: var(--ace-pg-primary-text);
        border-color: var(--ace-pg-primary);
    }

    .ace-pagination-mobile-btn.is-disabled {
        color: var(--ace-pg-disabled);
        opacity: 0.5;
        cursor: not-allowed;
        border-color: transparent;
        background: transparent;
    }

    .ace-pagination-mobile-status {
        font-size: 0.875rem;
        color: var(--ace-pg-text-muted);
    }

    .ace-pagination-mobile-status strong {
        color: var(--ace-pg-text);
    }

    /* Tablet Screen Adjustments (481px to 768px) */
    @media (max-width: 768px) {
        .ace-pagination-container {
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.75rem;
            padding: 1rem 0.75rem;
        }
        .ace-pagination-list {
            justify-content: center;
        }
        .ace-pagination-item .ace-pagination-link {
            min-width: 2.5rem;
            height: 2.5rem;
            font-size: 0.9rem;
        }
    }

    /* Mobile Screen Adjustments (<= 480px) */
    @media (max-width: 480px) {
        .ace-pagination-nav {
            display: none; /* Switch from cluttered numbered buttons to streamlined mobile controls */
        }
        .ace-pagination-mobile {
            display: flex;
            width: 100%;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
        }
        .ace-pagination-info {
            width: 100%;
            text-align: center;
            font-size: 0.8rem;
            order: 2;
        }
        .ace-pagination-mobile {
            order: 1;
        }
    }
</style>
HTML;
    }

    // =========================================================================
    // Static Configuration Methods
    // =========================================================================

    /**
     * Set global default theme.
     */
    public static function defaultTheme(string $theme): void
    {
        static::$defaultTheme = $theme;
    }

    /**
     * Configure paginator to use Bootstrap theme.
     */
    public static function useBootstrap(): void
    {
        static::$defaultTheme = 'bootstrap';
    }

    /**
     * Configure paginator to use Tailwind CSS theme.
     */
    public static function useTailwind(): void
    {
        static::$defaultTheme = 'tailwind';
    }

    /**
     * Configure paginator to use default Ace theme.
     */
    public static function useDefault(): void
    {
        static::$defaultTheme = 'default';
    }

    /**
     * Get the current default pagination theme.
     */
    public static function getDefaultTheme(): string
    {
        return static::$defaultTheme;
    }

    /**
     * Set the default pagination theme.
     */
    public static function setDefaultTheme(string $theme): void
    {
        static::$defaultTheme = $theme;
    }

    /**
     * Configure whether default CSS should be rendered.
     */
    public static function useStyles(bool $use = true): void
    {
        static::$useStyles = $use;
    }

    /**
     * Reset the styles rendered flag (useful for tests or multiple requests in CLI).
     */
    public static function resetStylesRendered(): void
    {
        static::$stylesRendered = false;
    }

    /**
     * Set a default custom view for rendering pagination.
     */
    public static function defaultView(string $view): void
    {
        static::$defaultView = $view;
    }

    /**
     * Set global default classes override.
     */
    public static function setGlobalClasses(array $classes): void
    {
        static::$globalClasses = $classes;
    }

    // =========================================================================
    // Interface Implementations
    // =========================================================================

    /**
     * Convert paginator directly to HTML string.
     */
    public function toHtml(): string
    {
        return $this->render();
    }

    /**
     * Stringable implementation - allows `echo $paginator` or `{{ $paginator }}`.
     */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Countable implementation - count of items on current page.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * IteratorAggregate implementation - allows `foreach ($paginator as $item)`.
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    /**
     * JsonSerializable implementation - serializes paginator metadata and data items.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the paginator instance to an array.
     */
    public function toArray(): array
    {
        return [
            'data'         => $this->items,
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage,
            'first_page_url' => $this->url(1),
            'last_page_url'  => $this->url($this->lastPage),
            'next_page_url'  => $this->nextPageUrl(),
            'prev_page_url'  => $this->previousPageUrl(),
            'next_page'    => $this->nextPage(),
            'prev_page'    => $this->previousPage(),
            'from'         => $this->firstItem(),
            'to'           => $this->lastItem(),
        ];
    }

    /**
     * ArrayAccess implementation: offsetExists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, [
            'data', 'items', 'total', 'per_page', 'current_page',
            'last_page', 'next_page', 'prev_page', 'from', 'to',
            'first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url'
        ], true) || isset($this->items[$offset]);
    }

    /**
     * ArrayAccess implementation: offsetGet.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'data', 'items'      => $this->items,
            'total'              => $this->total,
            'per_page'           => $this->perPage,
            'current_page'       => $this->currentPage,
            'last_page'          => $this->lastPage,
            'next_page'          => $this->nextPage(),
            'prev_page'          => $this->previousPage(),
            'from'               => $this->firstItem(),
            'to'                 => $this->lastItem(),
            'first_page_url'     => $this->url(1),
            'last_page_url'      => $this->url($this->lastPage),
            'next_page_url'      => $this->nextPageUrl(),
            'prev_page_url'      => $this->previousPageUrl(),
            default              => $this->items[$offset] ?? null,
        };
    }

    /**
     * ArrayAccess implementation: offsetSet.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * ArrayAccess implementation: offsetUnset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Magic property getter for common metadata.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'data', 'items'      => $this->items,
            'total'              => $this->total,
            'per_page', 'perPage' => $this->perPage,
            'current_page', 'currentPage' => $this->currentPage,
            'last_page', 'lastPage' => $this->lastPage,
            'next_page', 'nextPage' => $this->nextPage(),
            'prev_page', 'prevPage' => $this->previousPage(),
            'from'               => $this->firstItem(),
            'to'                 => $this->lastItem(),
            default              => null,
        };
    }
}
