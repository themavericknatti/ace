<?php

namespace Ace;

use ReflectionClass;
use ReflectionException;
use Exception;

class Container
{
    /**
     * The registered bindings.
     */
    protected array $bindings = [];

    /**
     * The resolved singleton instances.
     */
    protected array $instances = [];

    /**
     * Stack of class names currently being resolved (to detect circular references).
     */
    protected array $resolving = [];

    /**
     * Register a binding with the container.
     *
     * @param string $abstract The class, interface, or name to bind
     * @param mixed $concrete The closure or concrete class name. If null, uses $abstract.
     * @param bool $shared Whether the binding is a singleton
     */
    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
    }

    /**
     * Register a singleton binding with the container.
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register an existing instance as a singleton.
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Resolve the given type from the container.
     *
     * @throws Exception if resolution fails or a circular dependency is detected
     */
    public function resolve(string $abstract, array $parameters = []): mixed
    {
        // 1. If we have a resolved singleton instance, return it immediately
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Detect circular dependency
        if (isset($this->resolving[$abstract])) {
            throw new Exception("Circular dependency detected while resolving [{$abstract}].");
        }

        $this->resolving[$abstract] = true;

        try {
            $binding = $this->bindings[$abstract] ?? null;

            if ($binding) {
                $concrete = $binding['concrete'];
                $shared = $binding['shared'];

                // If concrete is a Closure, call it passing the container
                if ($concrete instanceof \Closure) {
                    $object = $concrete($this, $parameters);
                } else {
                    // Concrete is a string class name, resolve it recursively
                    $object = $this->build($concrete, $parameters);
                }

                // If it is a singleton, store the instance
                if ($shared) {
                    $this->instances[$abstract] = $object;
                }
            } else {
                // If not registered, attempt to build the class directly (auto-wiring)
                $object = $this->build($abstract, $parameters);
            }
        } finally {
            unset($this->resolving[$abstract]);
        }

        return $object;
    }

    /**
     * Build an instance of the class, resolving constructor dependencies.
     *
     * @throws Exception if build fails
     */
    protected function build(string $concrete, array $parameters = []): mixed
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new Exception("Target class [{$concrete}] does not exist.", 0, $e);
        }

        // If the class is not instantiable (e.g. abstract or interface)
        if (!$reflector->isInstantiable()) {
            throw new Exception("Target class [{$concrete}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        // If there is no constructor, we can just instantiate
        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = $constructor->getParameters();

        // Resolve dependencies recursively
        $instances = [];
        foreach ($dependencies as $parameter) {
            $name = $parameter->getName();
            
            // Check if we passed the parameter override parameter
            if (array_key_exists($name, $parameters)) {
                $instances[] = $parameters[$name];
                continue;
            }

            $type = $parameter->getType();

            // If parameter has no type hint or is a primitive type (string, int, etc.)
            if ($type === null || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $instances[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    $instances[] = null;
                } else {
                    throw new Exception("Unable to resolve primitive parameter [\${$name}] in class [{$concrete}].");
                }
                continue;
            }

            // It has a class type hint
            $className = $type->getName();
            
            try {
                $instances[] = $this->resolve($className);
            } catch (Exception $e) {
                if ($parameter->isDefaultValueAvailable()) {
                    $instances[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    $instances[] = null;
                } else {
                    throw $e;
                }
            }
        }

        return $reflector->newInstanceArgs($instances);
    }
}
