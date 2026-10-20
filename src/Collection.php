<?php

namespace Ace;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * A fluent wrapper around arrays providing chainable collection methods.
 *
 * Usage:
 *   $collection = collect([1, 2, 3, 4, 5]);
 *   $collection->map(fn($n) => $n * 2)->filter(fn($n) => $n > 4)->toArray();
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param array $items The underlying items
     */
    public function __construct(protected array $items = [])
    {
    }

    /**
     * Create a new collection instance.
     */
    public static function make(array $items = []): static
    {
        return new static($items);
    }

    /**
     * Get all items in the collection.
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Alias for all().
     */
    public function toArray(): array
    {
        return array_map(function ($value) {
            if ($value instanceof self) {
                return $value->toArray();
            }
            if ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            }
            if (is_object($value) && method_exists($value, 'toArray')) {
                return $value->toArray();
            }
            return $value;
        }, $this->items);
    }

    /**
     * Get the first item, optionally matching a callback.
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : reset($this->items);
        }

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * Get the last item, optionally matching a callback.
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : end($this->items);
        }

        return $this->reverse()->first($callback, $default);
    }

    /**
     * Apply a callback to each item and return a new collection.
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $values = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $values));
    }

    /**
     * Filter items using a callback. If no callback, removes falsy values.
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }

        return new static(array_filter($this->items));
    }

    /**
     * Reduce the collection to a single value.
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Run a callback on each item (does not modify the collection).
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Pluck a single field from each item.
     */
    public function pluck(string $field, ?string $keyField = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $value = $this->dataGet($item, $field);
            if ($keyField !== null) {
                $key = $this->dataGet($item, $keyField);
                $results[$key] = $value;
            } else {
                $results[] = $value;
            }
        }

        return new static($results);
    }

    /**
     * Check if the collection contains a value or passes a truth test.
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (is_callable($key) && !is_string($key)) {
            foreach ($this->items as $k => $item) {
                if ($key($item, $k)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($key, $this->items, true);
    }

    /**
     * Get unique items.
     */
    public function unique(?string $key = null): static
    {
        if ($key === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $seen = [];
        return $this->filter(function ($item) use ($key, &$seen) {
            $val = $this->dataGet($item, $key);
            if (in_array($val, $seen, true)) {
                return false;
            }
            $seen[] = $val;
            return true;
        });
    }

    /**
     * Sort items by a callback or key.
     */
    public function sortBy(string|callable $keyOrCallback, bool $descending = false): static
    {
        $items = $this->items;

        if (is_callable($keyOrCallback)) {
            usort($items, function ($a, $b) use ($keyOrCallback, $descending) {
                $result = $keyOrCallback($a) <=> $keyOrCallback($b);
                return $descending ? -$result : $result;
            });
        } else {
            usort($items, function ($a, $b) use ($keyOrCallback, $descending) {
                $result = $this->dataGet($a, $keyOrCallback) <=> $this->dataGet($b, $keyOrCallback);
                return $descending ? -$result : $result;
            });
        }

        return new static($items);
    }

    /**
     * Sort items in descending order.
     */
    public function sortByDesc(string|callable $keyOrCallback): static
    {
        return $this->sortBy($keyOrCallback, true);
    }

    /**
     * Group items by a key or callback.
     */
    public function groupBy(string|callable $groupBy): static
    {
        $results = [];

        foreach ($this->items as $key => $item) {
            $groupKey = is_callable($groupBy)
                ? $groupBy($item, $key)
                : $this->dataGet($item, $groupBy);

            $results[$groupKey][] = $item;
        }

        return new static(array_map(fn($group) => new static($group), $results));
    }

    /**
     * Chunk the collection into smaller collections.
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        $chunks = array_chunk($this->items, $size, true);

        return new static(array_map(fn($chunk) => new static($chunk), $chunks));
    }

    /**
     * Flatten a multi-dimensional collection into a single level.
     */
    public function flatten(int $depth = INF): static
    {
        return new static($this->flattenArray($this->items, $depth));
    }

    /**
     * Get a slice of the collection.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Take the first or last N items.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * Skip the first N items.
     */
    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    /**
     * Get the values (reindex numerically).
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get the keys.
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Merge another array or collection into this one.
     */
    public function merge(array|self $items): static
    {
        return new static(array_merge($this->items, $items instanceof self ? $items->all() : $items));
    }

    /**
     * Reverse the collection order.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /**
     * Get the sum of items, optionally by a key or callback.
     */
    public function sum(string|callable|null $callback = null): int|float
    {
        if ($callback === null) {
            return array_sum($this->items);
        }

        $total = 0;
        foreach ($this->items as $item) {
            $total += is_callable($callback)
                ? $callback($item)
                : $this->dataGet($item, $callback);
        }

        return $total;
    }

    /**
     * Get the average value, optionally by a key or callback.
     */
    public function avg(string|callable|null $callback = null): int|float|null
    {
        if ($this->isEmpty()) {
            return null;
        }

        return $this->sum($callback) / $this->count();
    }

    /**
     * Get the min value, optionally by a key.
     */
    public function min(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return min($this->items);
        }

        return $this->map(fn($item) => is_callable($callback) ? $callback($item) : $this->dataGet($item, $callback))->filter()->all()
            ? min($this->map(fn($item) => is_callable($callback) ? $callback($item) : $this->dataGet($item, $callback))->all())
            : null;
    }

    /**
     * Get the max value, optionally by a key.
     */
    public function max(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return max($this->items);
        }

        return $this->map(fn($item) => is_callable($callback) ? $callback($item) : $this->dataGet($item, $callback))->filter()->all()
            ? max($this->map(fn($item) => is_callable($callback) ? $callback($item) : $this->dataGet($item, $callback))->all())
            : null;
    }

    /**
     * Determine if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Determine if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Convert the collection to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Implode items by a glue string.
     */
    public function implode(string $glue, ?string $field = null): string
    {
        if ($field !== null) {
            return implode($glue, $this->pluck($field)->all());
        }

        return implode($glue, $this->items);
    }

    /**
     * Tap into the collection for debugging without modifying it.
     */
    public function tap(callable $callback): static
    {
        $callback(new static($this->items));
        return $this;
    }

    /**
     * Pipe the collection through a callback and return the result.
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Transform each item with a callback (modifies in-place semantics, returns new collection).
     */
    public function transform(callable $callback): static
    {
        $this->items = $this->map($callback)->all();
        return $this;
    }

    /**
     * Create a collection of key-value pairs from two arrays.
     */
    public static function combine(array $keys, array $values): static
    {
        return new static(array_combine($keys, $values));
    }

    // =========================================================================
    // Interface implementations
    // =========================================================================

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Get a value from a nested data structure using dot notation.
     */
    protected function dataGet(mixed $target, string $key): mixed
    {
        if (is_array($target)) {
            return $target[$key] ?? null;
        }

        if (is_object($target)) {
            return $target->{$key} ?? null;
        }

        return null;
    }

    /**
     * Recursively flatten an array.
     */
    protected function flattenArray(array $array, float $depth): array
    {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item) && !$item instanceof self) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, $item instanceof self ? $item->all() : $item);
            } else {
                $result = array_merge(
                    $result,
                    $this->flattenArray($item instanceof self ? $item->all() : $item, $depth - 1)
                );
            }
        }

        return $result;
    }
}
