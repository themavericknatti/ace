<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Application;
use Ace\Router;
use Ace\Request;
use Ace\Response;

class RouterTest extends TestCase
{
    protected Router $router;

    protected function setUp(): void
    {
        $request = new Request();
        $response = new Response();
        $this->router = new Router($request, $response);
    }

    public function test_route_registration_for_all_verbs(): void
    {
        $this->router->get('/test-get', fn() => 'get');
        $this->router->post('/test-post', fn() => 'post');
        $this->router->put('/test-put', fn() => 'put');
        $this->router->patch('/test-patch', fn() => 'patch');
        $this->router->delete('/test-delete', fn() => 'delete');
        $this->router->match(['GET', 'POST'], '/test-match', fn() => 'match');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test-get';
        $this->assertEquals('get', $this->router->resolve());

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test-post';
        $this->assertEquals('post', $this->router->resolve());

        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/test-put';
        $this->assertEquals('put', $this->router->resolve());

        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_SERVER['REQUEST_URI'] = '/test-patch';
        $this->assertEquals('patch', $this->router->resolve());

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['REQUEST_URI'] = '/test-delete';
        $this->assertEquals('delete', $this->router->resolve());
    }

    public function test_dynamic_parameters(): void
    {
        $this->router->get('/users/{id}', function($req, $id) {
            return "User: {$id}";
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users/42';
        $this->assertEquals('User: 42', $this->router->resolve());
    }

    public function test_optional_parameters(): void
    {
        $this->router->get('/archive/{year?}', function($req, $year = null) {
            return $year ? "Year: {$year}" : "All Years";
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/archive/2026';
        $this->assertEquals('Year: 2026', $this->router->resolve());

        $_SERVER['REQUEST_URI'] = '/archive';
        $this->assertEquals('All Years', $this->router->resolve());
    }

    public function test_route_groups_and_nested_groups(): void
    {
        $this->router->group('/api', function($router) {
            $router->get('/health', fn() => 'ok');
            $router->group('/v1', function($router) {
                $router->get('/status', fn() => 'v1-active');
            });
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/health';
        $this->assertEquals('ok', $this->router->resolve());

        $_SERVER['REQUEST_URI'] = '/api/v1/status';
        $this->assertEquals('v1-active', $this->router->resolve());
    }

    public function test_route_middleware_execution(): void
    {
        $ran = false;
        $middleware = function($req, $res) use (&$ran) {
            $ran = true;
        };

        $this->router->get('/guarded', fn() => 'guarded-content')->middleware($middleware);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/guarded';
        $result = $this->router->resolve();

        $this->assertEquals('guarded-content', $result);
        $this->assertTrue($ran);
    }
}
