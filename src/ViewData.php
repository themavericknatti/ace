<?php

namespace Ace;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;
use Traversable;

/**
 * Class ViewData
 * 
 * Flexible data wrapper that permits both array access ($data['key'])
 * and object property access ($data->key) interchangeably for views.
 */
class ViewData implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    protected array|object $data;

    public function __construct(array|object $data = [])
    {
        $this->data = $data;
    }

    /**
     * Wrap data in a ViewData instance if it is an array or a standard object.
     */
    public static function wrap(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value;
        }

        // Never wrap Throwable / Exception objects or resources
        if ($value instanceof \Throwable) {
            return $value;
        }

        if (is_array($value)) {
            return new static($value);
        }

        if (is_object($value) && !($value instanceof ArrayAccess) && !($value instanceof Model)) {
            return new static($value);
        }

        return $value;
    }

    /**
     * Delegate method calls to the underlying object if applicable.
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (is_object($this->data) && method_exists($this->data, $method)) {
            return $this->data->{$method}(...$arguments);
        }
        throw new \BadMethodCallException("Method {$method} does not exist on ViewData or wrapped data.");
    }

    /**
     * Unwrap back to raw data.
     */
    public function unwrap(): mixed
    {
        return $this->data;
    }

    /**
     * Convert to array representation recursively.
     */
    public function toArray(): array
    {
        if ($this->data instanceof Model) {
            return method_exists($this->data, 'toArray') ? $this->data->toArray() : (array) $this->data;
        }

        if (is_object($this->data)) {
            return get_object_vars($this->data);
        }

        $result = [];
        foreach ($this->data as $key => $val) {
            if ($val instanceof self) {
                $result[$key] = $val->toArray();
            } else {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    // =========================================================================
    // ArrayAccess Implementation
    // =========================================================================

    public function offsetExists(mixed $offset): bool
    {
        if (is_array($this->data)) {
            return array_key_exists($offset, $this->data);
        }

        if (is_object($this->data)) {
            if ($this->data instanceof ArrayAccess) {
                return isset($this->data[$offset]);
            }
            $key = (string) $offset;
            return property_exists($this->data, $key) || isset($this->data->{$key});
        }

        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $value = null;

        if (is_array($this->data)) {
            $value = $this->data[$offset] ?? null;
        } elseif (is_object($this->data)) {
            if ($this->data instanceof ArrayAccess) {
                $value = $this->data[$offset] ?? null;
            } else {
                $key = (string) $offset;
                $value = $this->data->{$key} ?? null;
            }
        }

        return static::wrap($value);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            if (is_array($this->data)) {
                $this->data[] = $value;
            }
        } else {
            if (is_array($this->data)) {
                $this->data[$offset] = $value;
            } elseif (is_object($this->data)) {
                if ($this->data instanceof ArrayAccess) {
                    $this->data[$offset] = $value;
                } else {
                    $key = (string) $offset;
                    $this->data->{$key} = $value;
                }
            }
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_array($this->data)) {
            unset($this->data[$offset]);
        } elseif (is_object($this->data)) {
            if ($this->data instanceof ArrayAccess) {
                unset($this->data[$offset]);
            } else {
                $key = (string) $offset;
                unset($this->data->{$key});
            }
        }
    }

    // =========================================================================
    // Magic Property Access ($data->key)
    // =========================================================================

    public function __get(string $name): mixed
    {
        return $this->offsetGet($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->offsetSet($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    public function __unset(string $name): void
    {
        $this->offsetUnset($name);
    }

    // =========================================================================
    // Countable Implementation
    // =========================================================================

    public function count(): int
    {
        if (is_array($this->data)) {
            return count($this->data);
        }
        if ($this->data instanceof Countable) {
            return count($this->data);
        }
        if (is_object($this->data)) {
            return count(get_object_vars($this->data));
        }
        return 0;
    }

    // =========================================================================
    // IteratorAggregate Implementation
    // =========================================================================

    public function getIterator(): ArrayIterator
    {
        $items = [];
        if (is_array($this->data) || $this->data instanceof Traversable) {
            foreach ($this->data as $key => $val) {
                $items[$key] = static::wrap($val);
            }
        } elseif (is_object($this->data)) {
            foreach (get_object_vars($this->data) as $key => $val) {
                $items[$key] = static::wrap($val);
            }
        }
        return new ArrayIterator($items);
    }

    // =========================================================================
    // JsonSerializable Implementation & String Conversion
    // =========================================================================

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize());
    }
}
