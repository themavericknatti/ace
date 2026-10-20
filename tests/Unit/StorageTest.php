<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Storage;
use Ace\Storage\LocalStorageDriver;
use Ace\Storage\StorageManager;

class StorageTest extends TestCase
{
    protected string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/ace_storage_test_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->testDir);
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->cleanupDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    public function test_local_storage_driver_put_and_get(): void
    {
        $driver = new LocalStorageDriver($this->testDir, 'https://cdn.example.com');

        $this->assertTrue($driver->put('test.txt', 'Hello World'));
        $this->assertTrue($driver->exists('test.txt'));
        $this->assertFalse($driver->missing('test.txt'));
        $this->assertEquals('Hello World', $driver->get('test.txt'));
        $this->assertEquals(11, $driver->size('test.txt'));
        $this->assertGreaterThan(0, $driver->lastModified('test.txt'));

        // Nested file creation
        $this->assertTrue($driver->put('nested/subfolder/file.json', '{"key":"value"}'));
        $this->assertTrue($driver->exists('nested/subfolder/file.json'));
        $this->assertEquals('{"key":"value"}', $driver->get('nested/subfolder/file.json'));

        // Append and Prepend
        $this->assertTrue($driver->append('test.txt', ' Appended'));
        $this->assertEquals('Hello World Appended', $driver->get('test.txt'));

        $this->assertTrue($driver->prepend('test.txt', 'Start: '));
        $this->assertEquals('Start: Hello World Appended', $driver->get('test.txt'));
    }

    public function test_copy_move_and_delete(): void
    {
        $driver = new LocalStorageDriver($this->testDir);

        $driver->put('original.txt', 'Source Data');
        $this->assertTrue($driver->copy('original.txt', 'copied.txt'));
        $this->assertTrue($driver->exists('copied.txt'));
        $this->assertEquals('Source Data', $driver->get('copied.txt'));

        $this->assertTrue($driver->move('copied.txt', 'moved.txt'));
        $this->assertFalse($driver->exists('copied.txt'));
        $this->assertTrue($driver->exists('moved.txt'));

        $this->assertTrue($driver->delete('moved.txt'));
        $this->assertFalse($driver->exists('moved.txt'));

        // Multiple file delete
        $driver->put('f1.txt', '1');
        $driver->put('f2.txt', '2');
        $this->assertTrue($driver->delete(['f1.txt', 'f2.txt']));
        $this->assertFalse($driver->exists('f1.txt'));
        $this->assertFalse($driver->exists('f2.txt'));
    }

    public function test_directory_and_listing_methods(): void
    {
        $driver = new LocalStorageDriver($this->testDir, '/storage');

        $driver->put('docs/doc1.txt', '1');
        $driver->put('docs/doc2.txt', '2');
        $driver->put('docs/sub/doc3.txt', '3');
        $driver->put('root.txt', '0');

        $rootFiles = $driver->files('');
        $this->assertContains('root.txt', $rootFiles);

        $docFiles = $driver->files('docs');
        $this->assertContains('docs/doc1.txt', $docFiles);
        $this->assertContains('docs/doc2.txt', $docFiles);
        $this->assertNotContains('docs/sub/doc3.txt', $docFiles); // shallow files() excludes nested subfolder files

        $allFiles = $driver->allFiles('docs');
        $this->assertContains('docs/doc1.txt', $allFiles);
        $this->assertContains('docs/sub/doc3.txt', $allFiles); // recursive allFiles() includes nested files

        $this->assertEquals('/storage/docs/doc1.txt', $driver->url('docs/doc1.txt'));

        // Delete directory
        $this->assertTrue($driver->deleteDirectory('docs'));
        $this->assertFalse($driver->exists('docs/doc1.txt'));
    }

    public function test_storage_manager_and_extend(): void
    {
        $manager = new StorageManager();

        // Test custom driver extension
        $manager->extend('memory', function () {
            return new LocalStorageDriver($this->testDir);
        });

        $disk = $manager->disk('memory');
        $this->assertInstanceOf(LocalStorageDriver::class, $disk);
    }

    public function test_global_storage_helper(): void
    {
        $this->assertTrue(function_exists('storage'));
        $disk = storage('local');
        $this->assertInstanceOf(\Ace\Storage\StorageDriverInterface::class, $disk);
    }
}
