<?php

namespace Ace;

use Throwable;

/**
 * ConsoleShell — Interactive REPL (Tinker) for Ace Framework
 *
 * Allows real-time evaluation of PHP expressions, Model queries,
 * Service calls, Queue dispatching, and debugging with live results.
 *
 * Usage:
 *   php ace tinker
 */
class ConsoleShell
{
    protected Application $app;
    protected array $scopeVariables = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Start the interactive REPL loop.
     */
    public function start(): void
    {
        echo "\n💬 Ace Interactive Console (Tinker)\n";
        echo "--------------------------------------------------------\n";
        echo "  Type any PHP code or Model query to evaluate.\n";
        echo "  Examples:\n";
        echo "    >>> User::count();\n";
        echo "    >>> User::first();\n";
        echo "    >>> Queue::size();\n";
        echo "  Type 'exit' or 'quit' (or Ctrl+C) to return.\n";
        echo "--------------------------------------------------------\n\n";

        // Auto-register App\Models aliases if class_alias is supported
        $this->registerAliases();

        while (true) {
            $input = $this->prompt(">>> ");

            if ($input === false) {
                echo "\nGoodbye!\n\n";
                break;
            }

            $trimmed = trim($input);

            if ($trimmed === '') {
                continue;
            }

            if (in_array(strtolower($trimmed), ['exit', 'quit', 'exit()', 'quit()', 'q'], true)) {
                echo "Goodbye!\n\n";
                break;
            }

            if (in_array(strtolower($trimmed), ['clear', 'cls'], true)) {
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    system('cls');
                } else {
                    system('clear');
                }
                continue;
            }

            $this->evaluate($trimmed);
        }
    }

    /**
     * Read a line from standard input.
     */
    protected function prompt(string $prefix): string|false
    {
        if (function_exists('readline')) {
            $line = readline($prefix);
            if ($line !== false && trim($line) !== '') {
                readline_add_history($line);
            }
            return $line;
        }

        echo $prefix;
        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);

        return $line !== false ? rtrim($line, "\r\n") : false;
    }

    /**
     * Evaluate the user PHP code snippet.
     */
    public function evaluate(string $code): mixed
    {
        // Auto-append semicolon if missing
        $clean = rtrim($code, ';');

        // Check if expression should be returned
        $wrappedCode = "return ({$clean});";

        try {
            // Attempt to evaluate as expression
            ob_start();
            $result = @eval($wrappedCode);
            $output = ob_get_clean();

            // If eval failed or produced null without returning, try executing directly
            if ($result === false && !str_starts_with($clean, 'return')) {
                ob_start();
                $result = eval($code . (str_ends_with($code, ';') ? '' : ';'));
                $output = ob_get_clean();
            }

            if (!empty($output)) {
                echo $output . "\n";
            }

            if ($result !== null) {
                $this->formatOutput($result);
            }

            return $result;
        } catch (Throwable $e) {
            ob_end_clean();
            echo "❌ Error: " . $e->getMessage() . "\n";
            echo "   at " . $e->getFile() . ':' . $e->getLine() . "\n\n";
            return null;
        }
    }

    /**
     * Pretty print output for the REPL console.
     */
    protected function formatOutput(mixed $result): void
    {
        echo "=> ";

        if ($result instanceof Model) {
            $class = get_class($result);
            echo "{$class} " . json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
            return;
        }

        if (is_array($result)) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
            return;
        }

        if (is_bool($result)) {
            echo ($result ? 'true' : 'false') . "\n\n";
            return;
        }

        if (is_scalar($result)) {
            var_export($result);
            echo "\n\n";
            return;
        }

        if (is_object($result)) {
            $class = get_class($result);
            echo "Object({$class})\n\n";
            return;
        }

        var_dump($result);
        echo "\n";
    }

    /**
     * Pre-alias common App\Models into root namespace if models exist.
     */
    protected function registerAliases(): void
    {
        $modelsDir = Application::$app->rootDir . '/app/Models';
        if (!is_dir($modelsDir)) {
            return;
        }

        $files = scandir($modelsDir);
        foreach ($files as $file) {
            if (str_ends_with($file, '.php')) {
                $modelName = basename($file, '.php');
                $fqcn = "App\\Models\\{$modelName}";
                if (class_exists($fqcn) && !class_exists($modelName)) {
                    class_alias($fqcn, $modelName);
                }
            }
        }
    }
}
