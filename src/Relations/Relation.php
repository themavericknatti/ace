<?php

namespace Ace\Relations;

use Ace\Model;
use Ace\QueryBuilder;
use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use ArrayIterator;

/**
 * Relation — Base Class for ActiveRecord Relationships
 *
 * Wraps QueryBuilder and provides fluent query chaining, e.g.:
 *   $user->posts()->where('status', 'published')->get()
 *
 * Also implements IteratorAggregate, Countable, and ArrayAccess so
 * direct calls like foreach ($user->posts() as $post) or count($user->posts())
 * or json_encode($user->posts()) work seamlessly.
 */
abstract class Relation implements IteratorAggregate, Countable, ArrayAccess, JsonSerializable
{
    protected Model $parent;
    protected string $relatedClass;
    protected QueryBuilder $query;

    public function __construct(Model $parent, string $relatedClass)
    {
        $this->parent = $parent;
        $this->relatedClass = $relatedClass;
        $this->query = $relatedClass::query();
        $this->addConstraints();
    }

    /**
     * Set the base constraints on the relation query.
     */
    abstract protected function addConstraints(): void;

    /**
     * Get the results of the relationship.
     */
    abstract public function getResults(): mixed;

    /**
     * Get the underlying QueryBuilder instance.
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Forward calls dynamically to the underlying QueryBuilder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->query->{$method}(...$parameters);

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }

    // =========================================================================
    // Interfaces (Countable, IteratorAggregate, ArrayAccess, JsonSerializable)
    // =========================================================================

    public function getIterator(): ArrayIterator
    {
        $results = $this->getResults();
        $array = is_array($results) ? $results : ($results ? [$results] : []);
        return new ArrayIterator($array);
    }

    public function count(): int
    {
        $results = $this->getResults();
        return is_array($results) ? count($results) : ($results ? 1 : 0);
    }

    public function jsonSerialize(): mixed
    {
        $results = $this->getResults();
        if (is_array($results)) {
            return array_map(fn($m) => $m instanceof Model ? $m->toArray() : $m, $results);
        }
        return $results instanceof Model ? $results->toArray() : $results;
    }

    public function offsetExists(mixed $offset): bool
    {
        $results = $this->getResults();
        return is_array($results) && isset($results[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $results = $this->getResults();
        return is_array($results) ? ($results[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Read-only relation results
    }

    public function offsetUnset(mixed $offset): void
    {
        // Read-only relation results
    }
}
