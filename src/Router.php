<?php

namespace Ace;

use Exception;

class Router
{
    protected array $routes = [];
    protected array $namedRoutes = [];
    protected array $routeMiddlewares = [];
    public Request $request;
    public Response $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Register GET route
     */
    public function get(string $path, $callback): Route
    {
        $path = $this->prefixPath($path);
        $this->routes['get'][$path] = $callback;
        $mw = $this->getGroupMiddlewares();
        if (!empty($mw)) $this->routeMiddlewares["get:$path"] = $mw;
        return new Route($this, 'get', $path, $callback);
    }

    /**
     * Register POST route
     */
    public function post(string $path, $callback): Route
    {
        $path = $this->prefixPath($path);
        $this->routes['post'][$path] = $callback;
        $mw = $this->getGroupMiddlewares();
        if (!empty($mw)) $this->routeMiddlewares["post:$path"] = $mw;
        return new Route($this, 'post', $path, $callback);
    }

    /**
     * Register PUT route
     */
    public function put(string $path, $callback): Route
    {
        $path = $this->prefixPath($path);
        $this->routes['put'][$path] = $callback;
        $mw = $this->getGroupMiddlewares();
        if (!empty($mw)) $this->routeMiddlewares["put:$path"] = $mw;
        return new Route($this, 'put', $path, $callback);
    }

    /**
     * Register PATCH route
     */
    public function patch(string $path, $callback): Route
    {
        $path = $this->prefixPath($path);
        $this->routes['patch'][$path] = $callback;
        $mw = $this->getGroupMiddlewares();
        if (!empty($mw)) $this->routeMiddlewares["patch:$path"] = $mw;
        return new Route($this, 'patch', $path, $callback);
    }

    /**
     * Register DELETE route
     */
    public function delete(string $path, $callback): Route
    {
        $path = $this->prefixPath($path);
        $this->routes['delete'][$path] = $callback;
        $mw = $this->getGroupMiddlewares();
        if (!empty($mw)) $this->routeMiddlewares["delete:$path"] = $mw;
        return new Route($this, 'delete', $path, $callback);
    }

    /**
     * Register a route for multiple HTTP methods
     */
    public function match(array $methods, string $path, $callback): Route
    {
        $path = $this->prefixPath($path);
        $mw = $this->getGroupMiddlewares();
        foreach ($methods as $method) {
            $method = strtolower($method);
            $this->routes[$method][$path] = $callback;
            if (!empty($mw)) $this->routeMiddlewares["$method:$path"] = $mw;
        }
        return new Route($this, $methods[0], $path, $callback);
    }

    /**
     * Register OPTIONS route
     */
    public function options(string $path, $callback): Route
    {
        $path = $this->prefixPath($path);
        $this->routes['options'][$path] = $callback;
        $mw = $this->getGroupMiddlewares();
        if (!empty($mw)) $this->routeMiddlewares["options:$path"] = $mw;
        return new Route($this, 'options', $path, $callback);
    }

    /**
     * Explicitly add middleware to a specific route
     */
    public function addRouteMiddleware(string $method, string $path, array $middlewares): void
    {
        $key = strtolower($method) . ':' . $path;
        $this->routeMiddlewares[$key] = array_merge($this->routeMiddlewares[$key] ?? [], $middlewares);
    }

    /**
     * Route group prefix stack (supports nesting)
     */
    protected array $groupPrefixes = [];

    /**
     * Route group middleware stack (supports nesting)
     */
    protected array $groupMiddlewares = [];

    /**
     * Register routes within a group sharing a prefix and/or middlewares.
     *
     *   $router->group('/admin', function($router) {
     *       $router->get('/dashboard', [DashboardController::class, 'index']);
     *   }, [AdminMiddleware::class]);
     *
     *   // Nested groups
     *   $router->group('/api', function($router) {
     *       $router->group('/v1', function($router) {
     *           $router->get('/users', [UserController::class, 'index']);
     *       });
     *   });
     */
    public function group(string $prefix, callable $callback, array $middlewares = []): void
    {
        $this->groupPrefixes[] = $prefix;
        $this->groupMiddlewares[] = $middlewares;

        $callback($this);

        array_pop($this->groupPrefixes);
        array_pop($this->groupMiddlewares);
    }

    /**
     * Get the current group prefix (concatenated from nested groups)
     */
    protected function getGroupPrefix(): string
    {
        return implode('', $this->groupPrefixes);
    }

    /**
     * Get all active group middlewares (merged from nested groups)
     */
    public function getGroupMiddlewares(): array
    {
        return array_merge(...($this->groupMiddlewares ?: [[]]));
    }

    /**
     * Apply the current group prefix to a path
     */
    protected function prefixPath(string $path): string
    {
        $prefix = $this->getGroupPrefix();
        if ($prefix === '') {
            return $path;
        }
        return rtrim($prefix, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Register an explicit name for a route path.
     */
    public function registerName(string $name, string $path): void
    {
        $this->namedRoutes[$name] = $path;
    }

    /**
     * Get the route path associated with an explicit name.
     */
    public function getPathByName(string $name): ?string
    {
        return $this->namedRoutes[$name] ?? null;
    }

    public function resolveRouteName(string $name): ?string
    {
        // 1. Check explicit named routes
        if (isset($this->namedRoutes[$name])) {
            return $this->namedRoutes[$name];
        }

        // 2. Check auto-naming convention in registered routes
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $path => $callback) {
                // Convention A: with parameter name (e.g. /blog/{id} -> blog.id)
                $autoNameWithParam = str_replace(['{', '}', '?'], '', $path);
                $autoNameWithParam = trim($autoNameWithParam, '/');
                $autoNameWithParam = str_replace('/', '.', $autoNameWithParam);

                if ($autoNameWithParam === $name) {
                    return $path;
                }

                // Convention B: without parameter name (e.g. /blog/{id} -> blog)
                $cleanPath = preg_replace('/\{[a-zA-Z0-9_]+\??\}/', '', $path);
                $cleanPath = preg_replace('/\/+/', '/', $cleanPath);
                $autoNameWithoutParam = trim($cleanPath, '/');
                $autoNameWithoutParam = str_replace('/', '.', $autoNameWithoutParam);

                if ($autoNameWithoutParam === $name) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the request URI and method to a registered route callback
     */
    public function resolve(): mixed
    {
        $path = $this->request->getPath();
        $method = $this->request->method();

        // Handle CORS OPTIONS preflight automatically for external API callers
        if ($method === 'options') {
            if (!headers_sent()) {
                header("Access-Control-Allow-Origin: *");
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
                header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Evently-Key");
                header("Access-Control-Max-Age: 86400");
            }
            http_response_code(204);
            exit;
        }

        // Exact match
        $callback = $this->routes[$method][$path] ?? false;

        if ($callback === false) {
            // Check dynamic parameter matches (e.g. /users/{id} or /users/{id?})
            foreach ($this->routes[$method] ?? [] as $route => $routeCallback) {
                // Convert optional params {param?} to optional regex group
                $pattern = preg_replace('#/\{([a-zA-Z0-9_]+)\?\}#', '(?:/([^/]+))?', $route);
                // Convert required params {param} to required regex group
                $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $pattern);
                $pattern = "@^" . $pattern . "$@";

                if (preg_match($pattern, $path, $matches)) {
                    array_shift($matches); // Remove the full match
                    // Replace empty optional matches with null
                    $matches = array_map(fn($m) => $m === '' ? null : $m, $matches);
                    $this->executeRouteMiddlewares($method, $route);
                    return $this->executeCallback($routeCallback, $matches);
                }
            }

            throw new Exception("Page not found", 404);
        }

        $this->executeRouteMiddlewares($method, $path);
        return $this->executeCallback($callback);
    }

    /**
     * Execute any middlewares registered specifically for this route
     */
    protected function executeRouteMiddlewares(string $method, string $path): void
    {
        $key = strtolower($method) . ':' . $path;
        $middlewares = $this->routeMiddlewares[$key] ?? [];

        foreach ($middlewares as $mw) {
            if (is_string($mw)) {
                $instance = Application::$app->container->resolve($mw);
                if ($instance instanceof Middleware) {
                    $instance->execute('');
                } elseif (is_callable($instance)) {
                    $instance($this->request, $this->response);
                }
            } elseif ($mw instanceof Middleware) {
                $mw->execute('');
            } elseif (is_callable($mw)) {
                $mw($this->request, $this->response);
            }
        }
    }

    /**
     * Execute route callback (Closure, String View, or Controller Action)
     */
    protected function executeCallback($callback, array $params = []): mixed
    {
        if (is_string($callback)) {
            return $this->renderView($callback);
        }

        if (is_array($callback)) {
            // callback is [Controller::class, 'action']
            $controllerClass = $callback[0];
            $action = $callback[1];

            // Instantiation via Container
            $controller = Application::$app->container->resolve($controllerClass);
            Application::$app->controller = $controller;
            $controller->action = $action;

            // Resolve and execute controller middlewares
            foreach ($controller->getMiddlewares() as $middleware) {
                $middleware->execute($action);
            }

            // Call action
            $result = call_user_func_array([$controller, $action], array_merge([$this->request], $params));

            if ($result instanceof JsonResource) {
                return $result->toResponse($this->request);
            }

            return $result;
        }

        if (is_callable($callback)) {
            $result = call_user_func_array($callback, array_merge([$this->request], $params));

            if ($result instanceof JsonResource) {
                return $result->toResponse($this->request);
            }

            return $result;
        }

        throw new Exception("Route callback is not valid", 500);
    }

    /**
     * Render layout and view templates
     */
    public function renderView(string $view, array $params = []): string
    {
        return Application::$app->view->render($view, $params);
    }
}

