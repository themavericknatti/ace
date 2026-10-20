<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Ace\Application;

class MaintenanceModeTest extends TestCase
{
    protected string $downFile;

    protected function setUp(): void
    {
        $this->downFile = dirname(__DIR__, 2) . '/storage/framework/down';
        if (file_exists($this->downFile)) {
            @unlink($this->downFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->downFile)) {
            @unlink($this->downFile);
        }
    }

    public function test_maintenance_mode_file_creation_and_removal(): void
    {
        $dir = dirname($this->downFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            'time' => time(),
            'message' => 'Custom maintenance message',
            'retry' => 120,
            'secret' => 'super-secret-bypass',
        ];

        file_put_contents($this->downFile, json_encode($payload));
        $this->assertFileExists($this->downFile);

        $read = json_decode(file_get_contents($this->downFile), true);
        $this->assertEquals('Custom maintenance message', $read['message']);
        $this->assertEquals('super-secret-bypass', $read['secret']);

        @unlink($this->downFile);
        $this->assertFileDoesNotExist($this->downFile);
    }
}
