<?php

namespace Ace;

use PDO;
use Exception;
use Ace\Exceptions\ModelNotFoundException;

class QueryBuilder
{
    protected string $modelClass;
    protected string $table;
    protected string $select = '*';
    protected array $joins = [];
    protected array $wheres = [];
    protected array $orWheres = [];
    protected array $params = [];
    protected ?string $orderBy = null;
    protected ?int $limit = null;
    protected ?int $offset = null;

    protected static array $allowedOperators = [
        '=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'IS', 'IS NOT'
    ];

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
        $this->table = $modelClass::tableName();
    }

    /**
     * Set specific columns to select.
     * Supports 'id', 'table.column', 'table.*', etc.
     */
    public function select(string ...$columns): self
    {
        if (empty($columns)) {
            $this->select = '*';
            return $this;
        }

        $cleaned = array_map([$this, 'wrapColumn'], $columns);
        $this->select = implode(', ', $cleaned);
        return $this;
    }

    /**
     * Add an INNER, LEFT, or RIGHT JOIN clause.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $cleanOp = in_array(strtoupper(trim($operator)), ['=', '!=', '<>', '<', '<=', '>', '>='], true)
            ? strtoupper(trim($operator))
            : '=';

        $firstCol = $this->wrapColumn($first);
        $secondCol = $this->wrapColumn($second);

        $joinType = strtoupper(trim($type));
        if (!in_array($joinType, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            $joinType = 'INNER';
        }

        $this->joins[] = "{$joinType} JOIN `{$cleanTable}` ON {$firstCol} {$cleanOp} {$secondCol}";
        return $this;
    }

    /**
     * Add a LEFT JOIN clause.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * Add a RIGHT JOIN clause.
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * Add a WHERE clause. Supports both:
     * - where('status', 'active')
     * - where('age', '>', 21)
     * - where('age', 21, '>') (backward-compatible)
     */
    public function where(string $column, mixed $arg2, mixed $arg3 = null): self
    {
        [$operator, $value] = $this->resolveWhereArgs(func_num_args(), $arg2, $arg3);

        $cleanColumn = preg_replace('/[^a-zA-Z0-9_.]/', '', $column);
        $paramName = 'p_' . count($this->params) . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $cleanColumn);

        $colRef = $this->wrapColumn($cleanColumn);
        $this->wheres[] = "{$colRef} {$operator} :{$paramName}";
        $this->params[$paramName] = $value;

        return $this;
    }

    /**
     * Add an OR WHERE clause.
     */
    public function orWhere(string $column, mixed $arg2, mixed $arg3 = null): self
    {
        [$operator, $value] = $this->resolveWhereArgs(func_num_args(), $arg2, $arg3);

        $cleanColumn = preg_replace('/[^a-zA-Z0-9_.]/', '', $column);
        $paramName = 'p_' . count($this->params) . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $cleanColumn);

        $colRef = $this->wrapColumn($cleanColumn);
        $this->orWheres[] = "{$colRef} {$operator} :{$paramName}";
        $this->params[$paramName] = $value;

        return $this;
    }

    /**
     * Add a WHERE IN clause.
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            $this->wheres[] = '0 = 1';
            return $this;
        }

        $cleanColumn = preg_replace('/[^a-zA-Z0-9_.]/', '', $column);
        $placeholders = [];
        $prefix = 'in_' . count($this->params) . '_';
        foreach (array_values($values) as $i => $val) {
            $paramName = $prefix . $i;
            $placeholders[] = ":$paramName";
            $this->params[$paramName] = $val;
        }

        $colRef = $this->wrapColumn($cleanColumn);
        $this->wheres[] = "$colRef IN (" . implode(', ', $placeholders) . ")";
        return $this;
    }

    /**
     * Add a WHERE column IS NULL clause.
     */
    public function whereNull(string $column): self
    {
        $colRef = $this->wrapColumn($column);
        $this->wheres[] = "$colRef IS NULL";
        return $this;
    }

    /**
     * Add a WHERE column IS NOT NULL clause.
     */
    public function whereNotNull(string $column): self
    {
        $colRef = $this->wrapColumn($column);
        $this->wheres[] = "$colRef IS NOT NULL";
        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause.
     */
    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $colRef = $this->wrapColumn($column);
        $paramMin = 'btwn_' . count($this->params) . '_min';
        $paramMax = 'btwn_' . count($this->params) . '_max';

        $this->wheres[] = "$colRef BETWEEN :$paramMin AND :$paramMax";
        $this->params[$paramMin] = $min;
        $this->params[$paramMax] = $max;
        return $this;
    }

    /**
     * Add a raw WHERE clause.
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = $sql;
        foreach ($bindings as $key => $val) {
            $paramName = is_string($key) ? ltrim($key, ':') : 'raw_' . count($this->params) . '_' . $key;
            $this->params[$paramName] = $val;
        }
        return $this;
    }

    /**
     * Set query limit.

     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Alias for limit.
     */
    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    /**
     * Set query offset.
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Alias for offset.
     */
    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    /**
     * Set ORDER BY clause.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
        $colRef = $this->wrapColumn($column);
        $this->orderBy = "{$colRef} {$direction}";
        return $this;
    }

    /**
     * Build the raw SELECT SQL statement.
     */
    public function toSql(): string
    {
        $sql = "SELECT {$this->select} FROM `{$this->table}`";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $whereClause = $this->compileWheres();
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * Get the bound query parameters.
     */
    public function getBindings(): array
    {
        return $this->params;
    }

    /**
     * Compile and execute select statement, returning array of Model instances.
     * Hydrates attributes with forceFill to preserve all columns.
     */
    public function get(): array
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return [];
        }

        $statement = $db->prepare($this->toSql());
        foreach ($this->params as $key => $value) {
            $statement->bindValue(":$key", $value);
        }

        $statement->execute();
        $records = $statement->fetchAll(PDO::FETCH_ASSOC);

        $models = [];
        foreach ($records as $record) {
            $model = new $this->modelClass();
            $model->forceFill($record);
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Paginate the query results across multiple pages.
     *
     * @param int $perPage Number of items per page
     * @param int|null $page Current page number (null to auto-detect from request)
     * @param string $pageName Query parameter name for page number
     * @return Paginator
     */
    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page'): Paginator
    {
        if ($page === null) {
            $req = Application::$app->request ?? null;
            $rawPage = $req ? $req->get($pageName, 1) : ($_GET[$pageName] ?? 1);
            $page = is_numeric($rawPage) ? (int)$rawPage : 1;
        }

        $page = max(1, $page);

        // Calculate total count before applying limit/offset
        $total = $this->count();

        // Apply limit and offset for this page
        $this->limit($perPage)->offset(($page - 1) * $perPage);

        $items = $this->get();

        return new Paginator($items, $total, $perPage, $page, [
            'pageName' => $pageName,
        ]);
    }

    /**
     * Get first matching record or null.
     */
    public function first(): ?Model
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * Get the first record matching query or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(): Model
    {
        $record = $this->first();
        if (!$record) {
            throw new ModelNotFoundException($this->modelClass);
        }
        return $record;
    }

    /**
     * Find a record by primary key, or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(mixed $id): Model
    {
        $tempModel = new $this->modelClass();
        $primaryKey = $tempModel->primaryKey();

        $record = $this->where($primaryKey, $id)->first();
        if (!$record) {
            throw new ModelNotFoundException($this->modelClass, $id);
        }
        return $record;
    }

    /**
     * Check if any matching records exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get total count matching query criteria.
     */
    public function count(): int
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return 0;
        }

        $sql = "SELECT COUNT(*) FROM `{$this->table}`";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $whereClause = $this->compileWheres();
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        $statement = $db->prepare($sql);
        foreach ($this->params as $key => $value) {
            $statement->bindValue(":$key", $value);
        }

        $statement->execute();
        return (int)$statement->fetchColumn();
    }

    /**
     * Update matching records with the given attributes.
     *
     * @param array $values Column => Value pairs
     * @return int Number of affected rows
     */
    public function update(array $values): int
    {
        $db = Application::$app->db ?? null;
        if (!$db || empty($values)) {
            return 0;
        }

        $setParts = [];
        foreach ($values as $col => $val) {
            $cleanCol = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
            $paramName = 'upd_' . count($this->params) . '_' . $cleanCol;
            $setParts[] = "`$cleanCol` = :$paramName";
            $this->params[$paramName] = $val;
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts);

        $whereClause = $this->compileWheres();
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        $statement = $db->prepare($sql);
        foreach ($this->params as $key => $value) {
            $statement->bindValue(":$key", $value);
        }

        $statement->execute();
        return $statement->rowCount();
    }

    /**
     * Delete matching records.
     *
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return 0;
        }

        $sql = "DELETE FROM `{$this->table}`";

        $whereClause = $this->compileWheres();
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        $statement = $db->prepare($sql);
        foreach ($this->params as $key => $value) {
            $statement->bindValue(":$key", $value);
        }

        $statement->execute();
        return $statement->rowCount();
    }

    // =========================================================================
    // Internal Helpers
    // =========================================================================

    /**
     * Wrap column or table.column with backticks.
     */
    public function wrapColumn(string $column): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_.*]/', '', trim($column));
        if ($clean === '*') {
            return '*';
        }
        if (str_contains($clean, '.')) {
            [$tbl, $col] = explode('.', $clean, 2);
            return $col === '*' ? "`$tbl`.*" : "`$tbl`.`$col`";
        }
        return "`$clean`";
    }

    /**
     * Compile WHERE and OR WHERE conditions into SQL string.
     */
    protected function compileWheres(): string
    {
        $whereParts = [];
        if (!empty($this->wheres)) {
            $whereParts[] = '(' . implode(' AND ', $this->wheres) . ')';
        }
        foreach ($this->orWheres as $orWhere) {
            $whereParts[] = $orWhere;
        }

        return implode(' OR ', $whereParts);
    }

    /**
     * Flexibly parse 2-argument or 3-argument WHERE calls.
     */
    protected function resolveWhereArgs(int $numArgs, mixed $arg2, mixed $arg3): array
    {
        if ($numArgs === 2 || $arg3 === null) {
            return ['=', $arg2];
        }

        // 3 arguments provided:
        // Case A: where('age', '>', 21) => arg2 is operator, arg3 is value
        if (is_string($arg2) && in_array(strtoupper(trim($arg2)), self::$allowedOperators, true)) {
            return [strtoupper(trim($arg2)), $arg3];
        }

        // Case B: where('age', 21, '>') (backward-compatibility with older Ace calls)
        if (is_string($arg3) && in_array(strtoupper(trim($arg3)), self::$allowedOperators, true)) {
            return [strtoupper(trim($arg3)), $arg2];
        }

        return ['=', $arg2];
    }
}
