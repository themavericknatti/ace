<?php

namespace Ace;

class Route
{
    public string $method;
    public string $path;
    public $callback;
    protected Router $router;

    public function __construct(Router $router, string $method, string $path, $callback)
    {
        $this->router = $router;
        $this->method = $method;
        $this->path = $path;
        $this->callback = $callback;
    }

    /**
     * Set a custom name for this route.
     *
     * @param string $name
     * @return $this
     */
    public function name(string $name): self
    {
        $this->router->registerName($name, $this->path);
        return $this;
    }

    /**
     * Attach middleware(s) to this route.
     *
     * @param mixed ...$middlewares
     * @return $this
     */
    public function middleware(mixed ...$middlewares): self
    {
        $flattened = [];
        foreach ($middlewares as $mw) {
            if (is_array($mw)) {
                $flattened = array_merge($flattened, $mw);
            } else {
                $flattened[] = $mw;
            }
        }
        $this->router->addRouteMiddleware($this->method, $this->path, $flattened);
        return $this;
    }
}
