<?php

namespace Ace\Relations;

use Ace\Application;
use Ace\Model;
use PDO;

/**
 * BelongsToMany — Many-to-Many Relationship via Pivot Table
 *
 * Example:
 *   // User model:
 *   public function roles(): BelongsToMany {
 *       return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
 *   }
 *
 * Usage:
 *   $user->roles; // array of Role instances
 *   $user->roles()->where('name', 'admin')->get();
 *   $user->roles()->attach($roleId);
 *   $user->roles()->detach($roleId);
 *   $user->roles()->sync([1, 2, 3]);
 */
class BelongsToMany extends Relation
{
    protected string $pivotTable;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    protected string $parentKey;
    protected string $relatedKey;

    public function __construct(
        Model $parent,
        string $relatedClass,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ) {
        $this->parentKey = $parentKey ?? $parent->primaryKey();
        $relatedInstance = new $relatedClass();
        $this->relatedKey = $relatedKey ?? $relatedInstance->primaryKey();

        $parentShort = strtolower((new \ReflectionClass($parent))->getShortName());
        $relatedShort = strtolower((new \ReflectionClass($relatedClass))->getShortName());

        $this->foreignPivotKey = $foreignPivotKey ?? $parentShort . '_id';
        $this->relatedPivotKey = $relatedPivotKey ?? $relatedShort . '_id';

        if ($pivotTable === null) {
            // Default convention: alphabetical order of model singular names separated by underscore
            $names = [$parentShort, $relatedShort];
            sort($names);
            $this->pivotTable = implode('_', $names);
        } else {
            $this->pivotTable = $pivotTable;
        }

        parent::__construct($parent, $relatedClass);
    }

    protected function addConstraints(): void
    {
        $parentVal = $this->parent->attributes[$this->parentKey] ?? $this->parent->{$this->parentKey} ?? null;
        $relatedTable = $this->relatedClass::tableName();

        // Join the pivot table
        $this->query->join(
            $this->pivotTable,
            "{$this->pivotTable}.{$this->relatedPivotKey}",
            '=',
            "{$relatedTable}.{$this->relatedKey}"
        );

        // Select related table columns
        $this->query->select("{$relatedTable}.*");

        if ($parentVal !== null) {
            $this->query->where("{$this->pivotTable}.{$this->foreignPivotKey}", $parentVal);
        } else {
            $this->query->whereRaw('0 = 1');
        }
    }

    public function getResults(): array
    {
        return $this->query->get();
    }

    /**
     * Attach a related ID to the pivot table.
     */
    public function attach(int|string $id, array $attributes = []): bool
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return false;
        }

        $parentVal = $this->parent->attributes[$this->parentKey] ?? $this->parent->{$this->parentKey} ?? null;
        if ($parentVal === null) {
            return false;
        }

        $record = array_merge($attributes, [
            $this->foreignPivotKey => $parentVal,
            $this->relatedPivotKey => $id,
        ]);

        $columns = array_keys($record);
        $fields = implode(', ', array_map(fn($col) => "`$col`", $columns));
        $placeholders = implode(', ', array_map(fn($col) => ":$col", $columns));

        $stmt = $db->prepare("INSERT INTO `{$this->pivotTable}` ($fields) VALUES ($placeholders)");
        return $stmt->execute($record);
    }

    /**
     * Detach related ID(s) from the pivot table.
     */
    public function detach(int|string|array|null $ids = null): int
    {
        $db = Application::$app->db ?? null;
        if (!$db) {
            return 0;
        }

        $parentVal = $this->parent->attributes[$this->parentKey] ?? $this->parent->{$this->parentKey} ?? null;
        if ($parentVal === null) {
            return 0;
        }

        if ($ids === null) {
            // Detach all
            $stmt = $db->prepare("DELETE FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = :pid");
            $stmt->execute(['pid' => $parentVal]);
            return $stmt->rowCount();
        }

        $idList = is_array($ids) ? $ids : [$ids];
        if (empty($idList)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $stmt = $db->prepare("DELETE FROM `{$this->pivotTable}` WHERE `{$this->foreignPivotKey}` = ? AND `{$this->relatedPivotKey}` IN ($placeholders)");
        $stmt->execute(array_merge([$parentVal], $idList));
        return $stmt->rowCount();
    }

    /**
     * Synchronize pivot records with a given list of IDs.
     */
    public function sync(array $ids): array
    {
        $this->detach();
        $attached = [];
        foreach ($ids as $id) {
            if ($this->attach($id)) {
                $attached[] = $id;
            }
        }
        return ['attached' => $attached];
    }
}
