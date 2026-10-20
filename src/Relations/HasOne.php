<?php

namespace Ace\Relations;

use Ace\Model;

/**
 * HasOne — One-to-One Relationship
 *
 * Example:
 *   // User model:
 *   public function profile(): HasOne {
 *       return $this->hasOne(Profile::class);
 *   }
 *
 * Usage:
 *   $user->profile; // Profile instance or null
 *   $user->profile()->first();
 */
class HasOne extends Relation
{
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(Model $parent, string $relatedClass, ?string $foreignKey = null, ?string $localKey = null)
    {
        $this->localKey = $localKey ?? $parent->primaryKey();

        if ($foreignKey === null) {
            $shortName = strtolower((new \ReflectionClass($parent))->getShortName());
            $this->foreignKey = $shortName . '_id';
        } else {
            $this->foreignKey = $foreignKey;
        }

        parent::__construct($parent, $relatedClass);
    }

    protected function addConstraints(): void
    {
        $localVal = $this->parent->attributes[$this->localKey] ?? $this->parent->{$this->localKey} ?? null;
        if ($localVal !== null) {
            $this->query->where($this->foreignKey, $localVal);
        } else {
            $this->query->whereRaw('0 = 1');
        }
    }

    public function getResults(): ?Model
    {
        return $this->query->first();
    }
}
