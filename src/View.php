<?php

namespace Ace;

use Exception;

class View
{
    private string $viewsDir;
    private string $cacheDir;
    private ?TemplateManager $templates = null;
    private array $sections = [];
    private ?string $currentSection = null;
    private ?string $layout = null;
    private array $componentStack = [];

    public function __construct(string $viewsDir, string $cacheDir, ?TemplateManager $templates = null)
    {
        $this->viewsDir = $viewsDir;
        $this->cacheDir = $cacheDir;
        $this->templates = $templates;
    }

    /**
     * Resolve view file path with multi-template layer support.
     * 
     * Lookup Order:
     * 1. Active template specific view: views/templates/{active}/{view}.php
     * 2. Fallback template view: views/templates/{fallback}/{view}.php (if different from active)
     * 3. Root/Shared view: views/{view}.php
     */
    public function resolveViewFile(string $view): ?string
    {
        // Sanitize view name against path traversal
        $cleanView = str_replace(['..', "\0"], '', $view);
        $cleanView = ltrim($cleanView, '/\\');

        if ($this->templates && $this->templates->isEnabled()) {
            $activeTemplate = $this->templates->resolve();

            // 1. Check active template directory
            $templateView = $this->viewsDir . '/templates/' . $activeTemplate . '/' . $cleanView . '.php';
            if (file_exists($templateView)) {
                return $templateView;
            }

            // 2. Check fallback template directory (if different)
            $fallback = $this->templates->getFallback();
            if ($fallback !== $activeTemplate) {
                $fallbackView = $this->viewsDir . '/templates/' . $fallback . '/' . $cleanView . '.php';
                if (file_exists($fallbackView)) {
                    return $fallbackView;
                }
            }
        }

        // 3. Fallback to standard views directory
        $standardView = $this->viewsDir . '/' . $cleanView . '.php';
        if (file_exists($standardView)) {
            return $standardView;
        }

        return null;
    }

    /**
     * Render view template parsing Blade-like syntax and compiling standard PHP
     * 
     * @param string $view View name (e.g. 'home', 'errors/404')
     * @param array $params Parameter keys and values to extract
     * @return string Final compiled HTML output
     */
    public function render(string $view, array $params = []): string
    {
        $isDev = ($_ENV['APP_ENV'] ?? 'development') === 'development';
        // Run garbage collection probabilistically (1% chance) only in development
        if ($isDev && rand(1, 100) === 1) {
            $this->garbageCollectCache();
        }

        // Reset state between renders
        $this->sections = [];
        $this->currentSection = null;
        $this->layout = null;

        $viewFile = $this->resolveViewFile($view);
        if (!$viewFile) {
            throw new Exception("View template '$view' not found.", 404);
        }

        // Compile view template to cached PHP file
        $compiledViewFile = $this->compile($viewFile);

        // Extract params to local variables (wrapped so both array and object access work)
        extract($this->wrapParams($params));

        // Buffer and execute compiled view
        ob_start();
        try {
            include $compiledViewFile;
            $viewContent = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }

        // If layout was set via `@extends('layout')` directive inside the view file
        if ($this->layout) {
            $layoutFile = $this->resolveViewFile($this->layout);
            if (!$layoutFile) {
                throw new Exception("Layout '{$this->layout}' specified in '$view' not found.", 500);
            }

            // Compile the layout template
            $compiledLayoutFile = $this->compile($layoutFile);

            // If view has no sections, treat whole viewContent as default 'content' section
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $viewContent;
            }

            // Buffer and render layout
            ob_start();
            try {
                include $compiledLayoutFile;
                $layoutContent = ob_get_clean();
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                throw $e;
            }

            return $layoutContent;
        }

        return $viewContent;
    }

    /**
     * Set layout template (called via compiled @extends directive)
     */
    public function layout(string $layout): void
    {
        $this->layout = $layout;
    }

    /**
     * Start recording output buffer for a named section (called via compiled @section directive)
     */
    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /**
     * Set a section's content directly (called via compiled inline 2-argument @section directive)
     */
    public function setSection(string $name, string $content): void
    {
        $this->sections[$name] = $content;
    }

    /**
     * Close output buffer and record content for current section (called via compiled @endsection directive)
     */
    public function endSection(): void
    {
        if (!$this->currentSection) {
            return;
        }
        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }

    /**
     * Output a compiled section (called via compiled @yield directive)
     */
    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Include a sub-view (called via compiled @include directive)
     */
    public function include(string $view, array $params = []): string
    {
        $viewFile = $this->resolveViewFile($view);
        if (!$viewFile) {
            throw new Exception("Included view template '$view' not found.", 404);
        }

        $compiledViewFile = $this->compile($viewFile);
        return $this->renderSubView($compiledViewFile, $params);
    }

    /**
     * Get the attached TemplateManager instance
     */
    public function getTemplates(): ?TemplateManager
    {
        return $this->templates;
    }

    /**
     * Set or replace the TemplateManager instance
     */
    public function setTemplates(?TemplateManager $templates): self
    {
        $this->templates = $templates;
        return $this;
    }


    /**
     * Render compiled sub-view file inside an isolated scope
     */
    private function renderSubView(string $compiledFile, array $params): string
    {
        extract($this->wrapParams($params));
        ob_start();
        try {
            include $compiledFile;
            return ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Wrap parameter values in ViewData so arrays and objects can be accessed using both -> and []
     */
    private function wrapParams(array $params): array
    {
        $wrapped = [];
        foreach ($params as $key => $value) {
            $wrapped[$key] = ViewData::wrap($value);
        }
        return $wrapped;
    }

    /**
     * Start a component layout section (called via compiled @component directive)
     */
    public function startComponent(string $view, array $params = []): void
    {
        $this->componentStack[] = [
            'view' => $view,
            'params' => $params,
            'slots' => []
        ];
        ob_start();
    }

    /**
     * End a component layout, gathering all slot contents and rendering the component view
     */
    public function endComponent(): string
    {
        if (empty($this->componentStack)) {
            return '';
        }

        $component = array_pop($this->componentStack);
        $slotContent = ob_get_clean();

        $params = $component['params'];
        $params['slot'] = $slotContent;

        foreach ($component['slots'] as $name => $content) {
            $params[$name] = $content;
        }

        return $this->include($component['view'], $params);
    }

    /**
     * Start recording output buffer for a named slot (called via compiled @slot directive)
     */
    public function startSlot(string $name): void
    {
        ob_start();
        if (!empty($this->componentStack)) {
            $lastIndex = count($this->componentStack) - 1;
            $this->componentStack[$lastIndex]['active_slot'] = $name;
        }
    }

    /**
     * End recording output buffer for current slot (called via compiled @endslot directive)
     */
    public function endSlot(): void
    {
        if (empty($this->componentStack)) {
            ob_end_clean();
            return;
        }

        $lastIndex = count($this->componentStack) - 1;
        $activeSlot = $this->componentStack[$lastIndex]['active_slot'] ?? null;

        if ($activeSlot) {
            $this->componentStack[$lastIndex]['slots'][$activeSlot] = ob_get_clean();
            unset($this->componentStack[$lastIndex]['active_slot']);
        } else {
            ob_end_clean();
        }
    }

    /**
     * Output a URL safely — keeps & intact for query strings,
     * but blocks dangerous schemes (javascript:, vbscript:, data:)
     */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        // Block dangerous URI schemes
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (in_array($scheme, ['javascript', 'vbscript', 'data'])) {
            return '';
        }
        // Sanitize: remove illegal URL chars but keep & intact
        return filter_var($url, FILTER_SANITIZE_URL);
    }

    /**
     * Render toast notifications for session flash messages.
     * Self-contained: includes its own CSS and JS inline.
     * Supports: success, error, warning, info flash keys.
     */
    public static function renderToasts(): string
    {
        $session = Application::$app->session;
        $flashKeys = [
            'success' => '✅',
            'error'   => '❌',
            'warning' => '⚠️',
            'info'    => 'ℹ️',
        ];

        // Check if any toast messages exist
        $hasToasts = false;
        foreach ($flashKeys as $key => $emoji) {
            if ($session->getFlash($key)) {
                $hasToasts = true;
                break;
            }
        }

        if (!$hasToasts) {
            return '';
        }

        // Build toast HTML
        $toastItems = '';
        foreach ($flashKeys as $key => $emoji) {
            $message = $session->getFlash($key);
            if ($message) {
                $safeMessage = htmlspecialchars((string) $message);
                $title = ucfirst($key);
                $toastItems .= <<<HTML
                <div class="ace-toast ace-toast-{$key}" onclick="this.classList.add('ace-toast-fade-out'); setTimeout(() => this.remove(), 300)">
                    <div class="ace-toast-icon">{$emoji}</div>
                    <div class="ace-toast-content">
                        <div class="ace-toast-title">{$title}</div>
                        <div class="ace-toast-message">{$safeMessage}</div>
                    </div>
                    <button class="ace-toast-close" onclick="event.stopPropagation(); let p=this.parentElement; p.classList.add('ace-toast-fade-out'); setTimeout(()=>p.remove(),300)">&times;</button>
                </div>
HTML;
            }
        }

        return <<<HTML
        <style>
            .ace-toast-container{position:fixed;top:2rem;right:2rem;z-index:9999;display:flex;flex-direction:column;gap:.75rem;max-width:400px;width:calc(100vw - 4rem);pointer-events:none;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
            .ace-toast{pointer-events:auto;background:rgba(18,24,36,.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.08);border-radius:1rem;padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;box-shadow:0 10px 25px -5px rgba(0,0,0,.3),0 8px 10px -6px rgba(0,0,0,.3);cursor:pointer;position:relative;overflow:hidden;color:#f1f5f9;animation:ace-toast-in .35s cubic-bezier(.16,1,.3,1) forwards;transition:transform .2s ease,opacity .2s ease,box-shadow .2s ease}
            .ace-toast:hover{transform:translateY(-2px);box-shadow:0 20px 25px -5px rgba(0,0,0,.4),0 10px 10px -5px rgba(0,0,0,.4);border-color:rgba(255,255,255,.15)}
            .ace-toast-success{border-left:4px solid #10b981}.ace-toast-error{border-left:4px solid #ef4444}.ace-toast-warning{border-left:4px solid #f59e0b}.ace-toast-info{border-left:4px solid #10b981}
            .ace-toast-icon{font-size:1.5rem;flex-shrink:0}.ace-toast-content{flex-grow:1}
            .ace-toast-title{font-weight:600;font-size:.95rem;margin-bottom:.15rem;color:#fff;text-align:left}
            .ace-toast-message{font-size:.875rem;color:#94a3b8;line-height:1.4;text-align:left}
            .ace-toast-close{background:none;border:none;color:#64748b;font-size:1.25rem;cursor:pointer;padding:.25rem;line-height:1;transition:color .2s ease}.ace-toast-close:hover{color:#fff}
            .ace-toast::after{content:"";position:absolute;bottom:0;left:0;height:3px;width:100%;transform-origin:left;animation:ace-toast-progress 5s linear forwards}
            .ace-toast-success::after{background:#10b981}.ace-toast-error::after{background:#ef4444}.ace-toast-warning::after{background:#f59e0b}.ace-toast-info::after{background:#10b981}
            @keyframes ace-toast-in{from{opacity:0;transform:translateX(100px)}to{opacity:1;transform:translateX(0)}}
            .ace-toast-fade-out{animation:ace-toast-out .3s cubic-bezier(.16,1,.3,1) forwards!important}
            @keyframes ace-toast-out{from{opacity:1;transform:scale(1) translateY(0)}to{opacity:0;transform:scale(.9) translateY(-10px)}}
            @keyframes ace-toast-progress{from{transform:scaleX(1)}to{transform:scaleX(0)}}
            @media(max-width:640px){.ace-toast-container{top:1rem;right:1rem;width:calc(100vw - 2rem)}}
        </style>
        <div class="ace-toast-container" id="ace-toast-container">
            {$toastItems}
        </div>
        <script>
            document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".ace-toast").forEach(function(t){setTimeout(function(){if(t&&t.parentNode){t.classList.add("ace-toast-fade-out");setTimeout(function(){t.remove()},300)}},5000)})});
        </script>
HTML;
    }

    /**
     * Render the configured or specified CSS framework CDN tags.
     * Supports 'tailwind', 'bootstrap', or 'none'.
     * Automatically adjusts Paginator theme to match!
     */
    public static function renderCssFramework(?string $framework = null): string
    {
        if ($framework === null) {
            $framework = strtolower((string)($_ENV['CSS_FRAMEWORK'] ?? (isset(Application::$app) ? (Application::$app->config['css_framework'] ?? 'tailwind') : 'tailwind')));
        }

        return match (strtolower(trim($framework))) {
            'tailwind', 'tailwindcss' => static::renderTailwind(),
            'bootstrap', 'bootstrap5', 'bs' => static::renderBootstrap(),
            default => '',
        };
    }

    /**
     * Render Tailwind CSS Play CDN script tag and set Paginator to Tailwind.
     */
    public static function renderTailwind(): string
    {
        if (class_exists(Paginator::class)) {
            Paginator::useTailwind();
        }
        return '<script src="https://cdn.tailwindcss.com"></script>';
    }

    /**
     * Render Bootstrap 5.3 CSS and JS Bundle CDN tags and set Paginator to Bootstrap.
     */
    public static function renderBootstrap(): string
    {
        if (class_exists(Paginator::class)) {
            Paginator::useBootstrap();
        }
        return '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
             . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    }

    /**
     * Render Bootstrap 5.3 CSS-only tag.
     */
    public static function renderBootstrapCss(): string
    {
        if (class_exists(Paginator::class)) {
            Paginator::useBootstrap();
        }
        return '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    }

    /**
     * Render Bootstrap 5.3 JS-only Bundle tag.
     */
    public static function renderBootstrapJs(): string
    {
        return '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    }

    /**
     * Compile template file if modified, returning path to cached compiled script
     */
    private function compile(string $filePath): string
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $cacheFile = $this->cacheDir . '/' . md5($filePath) . '.php';

        $shouldCompile = !file_exists($cacheFile);

        // Check file modification times in ALL environments (dev and production)
        // so that template edits and deployments recompile immediately without stale delays
        if (!$shouldCompile) {
            clearstatcache(true, $filePath);
            clearstatcache(true, $cacheFile);
            $shouldCompile = filemtime($filePath) > filemtime($cacheFile);
        }

        if ($shouldCompile) {
            $content = file_get_contents($filePath);
            $sourceComment = "<?php // Source: " . str_replace('\\', '/', $filePath) . " ?>\n";
            $compiledContent = $sourceComment . $this->compileString($content);
            file_put_contents($cacheFile, $compiledContent);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($cacheFile, true);
            }
        }

        return $cacheFile;
    }

    /**
     * Clear all compiled view cache files.
     *
     * @return int Number of cache files deleted
     */
    public function clearCache(): int
    {
        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $count = 0;
        $files = glob($this->cacheDir . '/*.php');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (function_exists('opcache_invalidate')) {
                        @opcache_invalidate($file, true);
                    }
                    if (@unlink($file)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Garbage collect compiled views whose source templates no longer exist
     */
    public function garbageCollectCache(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $files = glob($this->cacheDir . '/*.php');
        foreach ($files as $file) {
            if (file_exists($file)) {
                $handle = @fopen($file, 'r');
                if ($handle) {
                    $firstLine = fgets($handle);
                    fclose($handle);
                    if (preg_match('/\/\/ Source: (.+?) \?>/', $firstLine, $matches)) {
                        $sourcePath = $matches[1];
                        if (!file_exists($sourcePath)) {
                            @unlink($file);
                        }
                    }
                }
            }
        }
    }

    /**
     * Compiles template string syntax into standard executable PHP
     */
    public function compileString(string $content): string
    {
        // Escape @@ and @{{
        $content = str_replace('@@', '___AT_ESCAPE___', $content);
        $content = str_replace('@{{', '___CURLY_ESCAPE___', $content);

        // 1. Convert {{ $var }} to htmlspecialchars escaped echo output
        $content = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '<?php echo htmlspecialchars((string)($1 ?? ""), ENT_QUOTES, "UTF-8"); ?>', $content);

        // 2. Convert {!! $var !!} or {{{ $var }}} to raw unescaped echo output
        $content = preg_replace('/\{!!\s*(.+?)\s*!!\}/', '<?php echo $1; ?>', $content);

        // 2b. Convert @url($var) to XSS-safe URL output (keeps & intact, blocks dangerous schemes)
        // Uses balanced parentheses regex to support nested calls like @url(route('home'))
        $content = preg_replace('/@url\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/', '<?php echo \Ace\View::safeUrl((string)($1 ?? "")); ?>', $content);

        // 3. Convert @extends('layout') or @layout('layout') -> $this->layout('layout')
        $content = preg_replace('/@(extends|layout)\(\s*(["\'])(.*?)\2\s*\)/', '<?php $this->layout(\'$3\'); ?>', $content);

        // 4a. Convert inline 2-argument @section('name', 'value') -> $this->setSection('name', 'value')
        $content = preg_replace('/@section\(\s*(["\'])(.*?)\1\s*,\s*(["\'])(.*?)\3\s*\)/', '<?php $this->setSection(\'$2\', \'$4\'); ?>', $content);

        // 4b. Convert 1-argument block @section('name') -> $this->startSection('name')
        $content = preg_replace('/@section\(\s*(["\'])(.*?)\1\s*\)/', '<?php $this->startSection(\'$2\'); ?>', $content);

        // 5. Convert @endsection -> $this->endSection()
        $content = str_replace('@endsection', '<?php $this->endSection(); ?>', $content);

        // 6a. Convert 2-argument @yield('name', 'default') -> echo $this->yieldSection('name', 'default')
        $content = preg_replace('/@yield\(\s*(["\'])(.*?)\1\s*,\s*(["\'])(.*?)\3\s*\)/', '<?php echo $this->yieldSection(\'$2\', \'$4\'); ?>', $content);

        // 6b. Convert 1-argument @yield('name') -> echo $this->yieldSection('name')
        $content = preg_replace('/@yield\(\s*(["\'])(.*?)\1\s*\)/', '<?php echo $this->yieldSection(\'$2\'); ?>', $content);

        // 7. Compiling conditional control structures (balanced parens regex for nested calls)
        $content = preg_replace('/@if\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/', '<?php if($1): ?>', $content);
        $content = preg_replace('/@elseif\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/', '<?php elseif($1): ?>', $content);
        $content = str_replace('@endif', '<?php endif; ?>', $content);
        $content = preg_replace('/@else(?!if)/', '<?php else: ?>', $content);

        // 8. Compiling loops (balanced parens regex for nested calls)
        $content = preg_replace('/@foreach\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/', '<?php foreach($1): ?>', $content);
        $content = str_replace('@endforeach', '<?php endforeach; ?>', $content);

        // 9. Compiling Auth tags (longer @endauth/@endguest first to prevent substring match)
        $content = str_replace('@endauth', '<?php endif; ?>', $content);
        $content = str_replace('@endguest', '<?php endif; ?>', $content);
        $content = str_replace('@auth', '<?php if(!\Ace\Application::isGuest()): ?>', $content);
        $content = str_replace('@guest', '<?php if(\Ace\Application::isGuest()): ?>', $content);

        // 9b. Compiling CSRF tag
        $content = str_replace('@csrf', '<?php echo csrf_field(); ?>', $content);

        // 9b2. Compiling Method spoofing tag (@method('PUT'), @method('DELETE'), etc.)
        $content = preg_replace('/@method\(\s*(["\'])(.*?)\1\s*\)/', '<?php echo method_field(\'$2\'); ?>', $content);

        // 9c. Compiling Isset tags
        $content = preg_replace('/@isset\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/', '<?php if(isset($1)): ?>', $content);
        $content = str_replace('@endisset', '<?php endif; ?>', $content);

        // 9d. Compiling Empty tags
        $content = preg_replace('/@empty\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/', '<?php if(empty($1)): ?>', $content);
        $content = str_replace('@endempty', '<?php endif; ?>', $content);

        // 9e. Compiling Session tags
        $content = preg_replace_callback(
            '/@session\(\s*(["\'])(.*?)\1\s*\)/s',
            function ($matches) {
                return "<?php if(\$value = \Ace\Application::\$app->session->getFlash('{$matches[2]}')): ?>";
            },
            $content
        );
        $content = str_replace('@endsession', '<?php endif; ?>', $content);

        // 9f. Compiling Error tags for Model validation feedback
        // Scans all template variables for any \Ace\Model instance with the given field error
        $content = preg_replace_callback(
            '/@error\(\s*(["\'])(.*?)\1\s*\)/s',
            function ($matches) {
                $field = $matches[2];
                return "<?php \$_ace_err = null; "
                     . "foreach (get_defined_vars() as \$_v) { "
                     . "if (\$_v instanceof \\Ace\\Model && \$_v->hasError('{$field}')) { \$_ace_err = \$_v; break; } } "
                     . "if (\$_ace_err): \$message = \$_ace_err->getFirstError('{$field}'); ?>";
            },
            $content
        );
        $content = str_replace('@enderror', '<?php endif; ?>', $content);

        // 9g. Compiling Toasts tag
        $content = str_replace('@toasts', '<?php echo \Ace\View::renderToasts(); ?>', $content);

        // 9h. Compiling Paginate tag (@paginate($items), @paginate($items, ['theme' => 'tailwind']))
        $content = preg_replace_callback(
            '/@paginate\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/',
            function ($matches) {
                $args = trim($matches[1] ?? '');
                return "<?php echo paginate_links({$args}); ?>";
            },
            $content
        );

        // 9i. Compiling CSS Framework tags (Zero npm, loaded via official CDN)
        $content = str_replace('@tailwind', '<?php echo \Ace\View::renderTailwind(); ?>', $content);
        $content = str_replace('@bootstrapCss', '<?php echo \Ace\View::renderBootstrapCss(); ?>', $content);
        $content = str_replace('@bootstrapJs', '<?php echo \Ace\View::renderBootstrapJs(); ?>', $content);
        $content = str_replace('@bootstrap', '<?php echo \Ace\View::renderBootstrap(); ?>', $content);
        $content = preg_replace('/@css\(\s*(["\'])(.*?)\1\s*\)/', '<?php echo \Ace\View::renderCssFramework(\'$2\'); ?>', $content);
        $content = str_replace('@css', '<?php echo \Ace\View::renderCssFramework(); ?>', $content);

        // 9j. Compiling Multi-Template directives
        $content = str_replace('@activeTemplate', '<?php echo active_template(); ?>', $content);
        $content = preg_replace('/@templateAsset\(\s*(["\'])(.*?)\1\s*\)/', '<?php echo template_asset(\'$2\'); ?>', $content);
        $content = preg_replace('/@template\(\s*(["\'])(.*?)\1\s*\)/', '<?php if(active_template() === \'$2\'): ?>', $content);
        $content = str_replace('@endtemplate', '<?php endif; ?>', $content);


        // 10. Compiling Includes (balanced parens regex to support nested expressions in params)
        $content = preg_replace_callback(
            '/@include\(\s*(["\'])(.*?)\1\s*(?:,\s*((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*))?\s*\)/s',
            function ($matches) {
                $view = $matches[2];
                $extraParams = trim($matches[3] ?? '');
                if ($extraParams) {
                    return "<?php echo \$this->include('{$view}', array_merge(get_defined_vars(), {$extraParams})); ?>";
                }
                return "<?php echo \$this->include('{$view}', get_defined_vars()); ?>";
            },
            $content
        );

        // 11. Compiling Components and Slots (balanced parens regex to support (object) casts etc.)
        $content = preg_replace_callback(
            '/@component\(\s*(["\'])(.*?)\1\s*(?:,\s*((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*))?\s*\)/s',
            function ($matches) {
                $view = $matches[2];
                $extraParams = trim($matches[3] ?? '');
                if ($extraParams) {
                    return "<?php \$this->startComponent('{$view}', array_merge(get_defined_vars(), {$extraParams})); ?>";
                }
                return "<?php \$this->startComponent('{$view}', get_defined_vars()); ?>";
            },
            $content
        );
        $content = str_replace('@endcomponent', '<?php echo $this->endComponent(); ?>', $content);

        $content = preg_replace('/@slot\(\s*(["\'])(.*?)\1\s*\)/s', '<?php $this->startSlot(\'$2\'); ?>', $content);
        $content = str_replace('@endslot', '<?php $this->endSlot(); ?>', $content);

        // Restore escaped @@ and @{{
        $content = str_replace('___AT_ESCAPE___', '@', $content);
        $content = str_replace('___CURLY_ESCAPE___', '{{', $content);

        return $content;
    }
}

