<?php

namespace Ace;

use Ace\Exceptions\ModelNotFoundException;

abstract class Repository
{
    /**
     * The Model class managed by this repository (e.g. User::class).
     */
    protected string $modelClass;

    public function __construct(?string $modelClass = null)
    {
        if ($modelClass !== null) {
            $this->modelClass = $modelClass;
        }
    }

    /**
     * Get the Model class name.
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Instantiate a fresh Model instance.
     */
    public function newModel(): Model
    {
        $class = $this->modelClass;
        return new $class();
    }

    /**
     * Get a new QueryBuilder instance for the managed Model.
     */
    public function query(): QueryBuilder
    {
        $class = $this->modelClass;
        return $class::query();
    }

    /**
     * Retrieve all records.
     */
    public function all(array $columns = ['*']): array
    {
        $query = $this->query();
        if ($columns !== ['*']) {
            $query->select(...$columns);
        }
        return $query->get();
    }

    /**
     * Find a record by its primary key or return null.
     */
    public function find(mixed $id): ?Model
    {
        $temp = $this->newModel();
        return $this->query()->where($temp->primaryKey(), $id)->first();
    }

    /**
     * Find a record by its primary key or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(mixed $id): Model
    {
        $record = $this->find($id);
        if (!$record) {
            throw new ModelNotFoundException($this->modelClass, $id);
        }
        return $record;
    }

    /**
     * Find a single record matching specific column and value.
     */
    public function findBy(string $column, mixed $value): ?Model
    {
        return $this->query()->where($column, $value)->first();
    }

    /**
     * Retrieve all records matching criteria.
     *
     * @param array $criteria Associative array of column => value pairs
     * @param string|null $orderBy Column to sort by
     * @param string $direction ASC or DESC
     * @return array
     */
    public function allWhere(array $criteria, ?string $orderBy = null, string $direction = 'ASC'): array
    {
        $query = $this->query();
        foreach ($criteria as $col => $val) {
            $query->where($col, $val);
        }
        if ($orderBy) {
            $query->orderBy($orderBy, $direction);
        }
        return $query->get();
    }

    /**
     * Paginate matching records returning a responsive Paginator instance.
     */
    public function paginate(int $perPage = 15, ?int $page = null, array $criteria = []): Paginator
    {
        $query = $this->query();
        foreach ($criteria as $col => $val) {
            $query->where($col, $val);
        }
        return $query->paginate($perPage, $page);
    }

    /**
     * Create a new record and persist it.
     */
    public function create(array $attributes): Model
    {
        $model = $this->newModel();
        $model->loadData($attributes);
        $model->save();
        return $model;
    }

    /**
     * Update an existing record by ID.
     */
    public function update(mixed $id, array $attributes): bool
    {
        $record = $this->find($id);
        if (!$record) {
            return false;
        }
        $record->loadData($attributes);
        return $record->save();
    }

    /**
     * Delete a record by ID.
     */
    public function delete(mixed $id): bool
    {
        $record = $this->find($id);
        if (!$record) {
            return false;
        }
        return $record->delete();
    }

    /**
     * Count total matching records.
     */
    public function count(array $criteria = []): int
    {
        $query = $this->query();
        foreach ($criteria as $col => $val) {
            $query->where($col, $val);
        }
        return $query->count();
    }

    /**
     * Check if any matching records exist.
     */
    public function exists(array $criteria = []): bool
    {
        return $this->count($criteria) > 0;
    }
}
