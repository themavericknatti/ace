<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Job;
use Ace\Queue;
use Ace\QueueWorker;
use Ace\ConsoleShell;
use Ace\Application;

// =========================================================================
// Dummy Job Classes for Testing
// =========================================================================

class DummyEmailJob extends Job
{
    public static bool $executed = false;
    public static ?array $executedData = null;

    public function handle(): void
    {
        self::$executed = true;
        self::$executedData = $this->data;
    }
}

class DummyFailingJob extends Job
{
    public static bool $failedHookCalled = false;

    public function handle(): void
    {
        throw new \Exception("Simulated job failure");
    }

    public function failed(?\Throwable $e = null): void
    {
        self::$failedHookCalled = true;
    }
}

// =========================================================================
// Queue Test Suite
// =========================================================================

class QueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Force sync driver so tests don't need database tables
        $_ENV['QUEUE_DRIVER'] = 'sync';

        DummyEmailJob::$executed = false;
        DummyEmailJob::$executedData = null;
        DummyFailingJob::$failedHookCalled = false;
    }

    protected function tearDown(): void
    {
        unset($_ENV['QUEUE_DRIVER']);
        parent::tearDown();
    }

    // =====================================================================
    // Job Base Class Tests
    // =====================================================================

    public function test_job_base_class_stores_payload_data(): void
    {
        $job = new DummyEmailJob(['user_id' => 42, 'email' => 'test@example.com']);
        $this->assertEquals(42, $job->data['user_id']);
        $this->assertEquals('test@example.com', $job->data['email']);
        $this->assertEquals('default', $job->queue);
        $this->assertEquals(0, $job->attempts);
        $this->assertEquals(3, $job->maxAttempts);
        $this->assertNull($job->id);
    }

    public function test_job_handle_executes_business_logic(): void
    {
        $job = new DummyEmailJob(['action' => 'send']);
        $this->assertFalse(DummyEmailJob::$executed);

        $job->handle();

        $this->assertTrue(DummyEmailJob::$executed);
        $this->assertEquals(['action' => 'send'], DummyEmailJob::$executedData);
    }

    public function test_job_failed_hook_is_callable(): void
    {
        $job = new DummyFailingJob(['test' => true]);
        $exception = new \Exception("Test failure");

        $job->failed($exception);
        $this->assertTrue(DummyFailingJob::$failedHookCalled);
    }

    // =====================================================================
    // Sync Queue Tests (QUEUE_DRIVER=sync)
    // =====================================================================

    public function test_sync_push_executes_job_immediately(): void
    {
        $this->assertFalse(DummyEmailJob::$executed);

        $result = Queue::push(DummyEmailJob::class, ['user_id' => 99]);

        $this->assertTrue($result);
        $this->assertTrue(DummyEmailJob::$executed);
        $this->assertEquals(['user_id' => 99], DummyEmailJob::$executedData);
    }

    public function test_sync_dispatch_static_helper(): void
    {
        $result = DummyEmailJob::dispatch(['channel' => 'email']);

        $this->assertTrue($result);
        $this->assertTrue(DummyEmailJob::$executed);
        $this->assertEquals(['channel' => 'email'], DummyEmailJob::$executedData);
    }

    public function test_sync_dispatch_later_still_executes_immediately(): void
    {
        $result = DummyEmailJob::dispatchLater(60, ['delayed' => true]);

        $this->assertTrue($result);
        $this->assertTrue(DummyEmailJob::$executed);
        $this->assertEquals(['delayed' => true], DummyEmailJob::$executedData);
    }

    public function test_dispatch_global_helper_function(): void
    {
        $result = dispatch(DummyEmailJob::class, ['via' => 'helper']);

        $this->assertTrue($result);
        $this->assertTrue(DummyEmailJob::$executed);
        $this->assertEquals(['via' => 'helper'], DummyEmailJob::$executedData);
    }

    public function test_sync_push_with_nonexistent_class_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Job class 'NonExistentJobClass' not found");

        Queue::push('NonExistentJobClass', ['bad' => true]);
    }

    public function test_sync_failing_job_throws_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Simulated job failure");

        Queue::push(DummyFailingJob::class, ['will' => 'fail']);
    }

    // =====================================================================
    // Queue Worker Tests (sync mode — stop-when-empty)
    // =====================================================================

    public function test_queue_worker_exits_immediately_when_empty_and_stop_when_empty(): void
    {
        $_ENV['QUEUE_DRIVER'] = 'database';

        // Null out db so pop() returns null immediately (no table access)
        $originalDb = null;
        if (isset(Application::$app)) {
            $originalDb = Application::$app->db;
            Application::$app->db = null;
        }

        $outputBuffer = [];
        $worker = new QueueWorker();
        $processed = $worker->work('default', 1, 3, true, function ($msg) use (&$outputBuffer) {
            $outputBuffer[] = $msg;
        });

        $this->assertEquals(0, $processed);
        $this->assertNotEmpty($outputBuffer);

        if (isset(Application::$app)) {
            Application::$app->db = $originalDb;
        }
    }

    public function test_queue_worker_stop_method(): void
    {
        $worker = new QueueWorker();
        // The worker should have a stop method
        $worker->stop();

        // After stop, it should not process anything
        $processed = $worker->work('default', 0, 3, true, function () {});
        $this->assertEquals(0, $processed);
    }

    // =====================================================================
    // Queue Utility Method Tests (no-database safe)
    // =====================================================================

    public function test_queue_size_returns_zero_without_database(): void
    {
        $_ENV['QUEUE_DRIVER'] = 'database';
        // Save and null out the database
        $originalDb = null;
        if (isset(Application::$app)) {
            $originalDb = Application::$app->db;
            Application::$app->db = null;
        }

        $this->assertEquals(0, Queue::size('default'));
        $this->assertEquals([], Queue::failed());
        $this->assertFalse(Queue::delete(1));
        $this->assertFalse(Queue::release(1));
        $this->assertEquals(0, Queue::clear('default'));

        if (isset(Application::$app)) {
            Application::$app->db = $originalDb;
        }
    }

    // =====================================================================
    // Console Shell Tests
    // =====================================================================

    public function test_console_shell_evaluate_basic_expression(): void
    {
        if (!isset(Application::$app)) {
            $rootDir = dirname(__DIR__, 2);
            new Application($rootDir, []);
        }
        $shell = new ConsoleShell(Application::$app);

        ob_start();
        $result = $shell->evaluate('1 + 1');
        ob_end_clean();

        $this->assertEquals(2, $result);
    }

    public function test_console_shell_evaluate_string_expression(): void
    {
        if (!isset(Application::$app)) {
            $rootDir = dirname(__DIR__, 2);
            new Application($rootDir, []);
        }
        $shell = new ConsoleShell(Application::$app);

        ob_start();
        $result = $shell->evaluate('"hello" . " " . "world"');
        ob_end_clean();

        $this->assertEquals('hello world', $result);
    }

    public function test_console_shell_evaluate_array(): void
    {
        if (!isset(Application::$app)) {
            $rootDir = dirname(__DIR__, 2);
            new Application($rootDir, []);
        }
        $shell = new ConsoleShell(Application::$app);

        ob_start();
        $result = $shell->evaluate('[1, 2, 3]');
        ob_end_clean();

        $this->assertEquals([1, 2, 3], $result);
    }

    public function test_console_shell_catches_errors_gracefully(): void
    {
        if (!isset(Application::$app)) {
            $rootDir = dirname(__DIR__, 2);
            new Application($rootDir, []);
        }
        $shell = new ConsoleShell(Application::$app);

        ob_start();
        $result = $shell->evaluate('throw new \\Exception("test error")');
        $output = ob_get_clean();

        $this->assertNull($result);
        $this->assertStringContainsString('Error', $output);
    }
}
