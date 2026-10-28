<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Router;
use Ace\Request;
use Ace\Response;
use Ace\Controller;
use Ace\FormRequest;
use Ace\Application;
use Ace\Container;

// ─── Test FormRequest Subclass ───────────────────────────────

class StubFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required',
        ];
    }
}

// ─── Test Controller (must extend Ace\Controller) ────────────

class StubFormRequestController extends Controller
{
    /**
     * Action type-hinting a FormRequest subclass.
     */
    public function store(StubFormRequest $request): string
    {
        $validated = $request->validated();
        return 'stored:' . ($validated['name'] ?? 'none');
    }

    /**
     * Action type-hinting the base Request.
     */
    public function index(Request $request): string
    {
        return 'index:' . $request->getPath();
    }
}

class FormRequestInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        // Bootstrap a minimal Application with container
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test-store';
        $_POST = ['name' => 'John'];

        $app = new Application(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    public function test_controller_action_receives_form_request_subclass(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test-store';
        $_POST = ['name' => 'Jane'];

        $request = new Request();
        $response = new Response();
        $router = new Router($request, $response);

        $router->post('/test-store', [StubFormRequestController::class, 'store']);

        $result = $router->resolve();

        $this->assertEquals('stored:Jane', $result);
    }

    public function test_controller_action_receives_base_request(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test-index';

        $request = new Request();
        $response = new Response();
        $router = new Router($request, $response);

        $router->get('/test-index', [StubFormRequestController::class, 'index']);

        $result = $router->resolve();

        $this->assertEquals('index:/test-index', $result);
    }

    public function test_closure_receives_form_request_subclass(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/closure-store';
        $_POST = ['name' => 'ClosureTest'];

        $request = new Request();
        $response = new Response();
        $router = new Router($request, $response);

        $router->post('/closure-store', function (StubFormRequest $request) {
            $data = $request->validated();
            return 'closure:' . $data['name'];
        });

        $result = $router->resolve();

        $this->assertEquals('closure:ClosureTest', $result);
    }

    public function test_closure_receives_base_request(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/closure-index';

        $request = new Request();
        $response = new Response();
        $router = new Router($request, $response);

        $router->get('/closure-index', function (Request $request) {
            return 'closure-base:' . $request->getPath();
        });

        $result = $router->resolve();

        $this->assertEquals('closure-base:/closure-index', $result);
    }

    public function test_form_request_validated_returns_only_valid_fields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/validated-fields';
        $_POST = ['name' => 'ValidName', 'extra_field' => 'should_not_appear'];

        $request = new Request();
        $response = new Response();
        $router = new Router($request, $response);

        $router->post('/validated-fields', function (StubFormRequest $request) {
            $data = $request->validated();
            return json_encode($data);
        });

        $result = $router->resolve();
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('name', $decoded);
        $this->assertEquals('ValidName', $decoded['name']);
    }

    public function test_form_request_proxy_methods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/proxy-test';
        $_POST = ['name' => 'ProxyUser', 'email' => 'proxy@test.com'];

        $request = new Request();
        $formRequest = new StubFormRequest($request);

        // Test all()
        $all = $formRequest->all();
        $this->assertArrayHasKey('name', $all);
        $this->assertArrayHasKey('email', $all);

        // Test has()
        $this->assertTrue($formRequest->has('name'));
        $this->assertFalse($formRequest->has('nonexistent'));

        // Test get() / input()
        $this->assertEquals('ProxyUser', $formRequest->get('name'));
        $this->assertEquals('proxy@test.com', $formRequest->input('email'));

        // Test only()
        $subset = $formRequest->only('name');
        $this->assertArrayHasKey('name', $subset);
        $this->assertArrayNotHasKey('email', $subset);

        // Test except()
        $filtered = $formRequest->except('email');
        $this->assertArrayHasKey('name', $filtered);
        $this->assertArrayNotHasKey('email', $filtered);

        // Test method()
        $this->assertEquals('post', $formRequest->method());

        // Test getPath()
        $this->assertEquals('/proxy-test', $formRequest->getPath());

        // Test magic getter
        $this->assertEquals('ProxyUser', $formRequest->name);

        // Test ArrayAccess
        $this->assertTrue(isset($formRequest['name']));
        $this->assertEquals('ProxyUser', $formRequest['name']);

        // Test getRequest()
        $this->assertSame($request, $formRequest->getRequest());
    }

    public function test_form_request_safe_alias(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/safe-test';
        $_POST = ['name' => 'SafeUser'];

        $request = new Request();
        $formRequest = new StubFormRequest($request);

        // safe() should be an alias for validated()
        $safeData = $formRequest->safe();
        $this->assertIsArray($safeData);
        $this->assertEquals('SafeUser', $safeData['name']);

        // safe('name') should return a single key
        $this->assertEquals('SafeUser', $formRequest->safe('name'));
        $this->assertNull($formRequest->safe('nonexistent'));
        $this->assertEquals('fallback', $formRequest->safe('nonexistent', 'fallback'));
    }
}
