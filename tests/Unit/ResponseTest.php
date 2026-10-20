<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Response;

class ResponseTest extends TestCase
{
    public function test_json_method_outputs_valid_json_string(): void
    {
        $response = new Response();
        $payload = ['status' => 'success', 'data' => ['id' => 1]];

        ob_start();
        $response->json($payload, 200);
        $output = ob_get_clean();

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertEquals('success', $decoded['status']);
        $this->assertEquals(1, $decoded['data']['id']);
    }

    public function test_no_content_sets_204(): void
    {
        $response = new Response();
        $response->noContent();
        $this->assertEquals(204, http_response_code());
    }

    public function test_download_throws_exception_if_file_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);

        $response = new Response();
        $response->download('non_existent_file_path_12345.pdf');
    }
}
