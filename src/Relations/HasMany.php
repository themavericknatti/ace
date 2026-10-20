<?php

namespace Ace\Relations;

use Ace\Model;

/**
 * HasMany — One-to-Many Relationship
 *
 * Example:
 *   // User model:
 *   public function posts(): HasMany {
 *       return $this->hasMany(Post::class);
 *   }
 *
 * Usage:
 *   $user->posts; // array of Post instances
 *   $user->posts()->where('status', 'active')->orderBy('id', 'DESC')->get();
 */
class HasMany extends Relation
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
            // Unsaved model: match nothing
            $this->query->whereRaw('0 = 1');
        }
    }

    public function getResults(): array
    {
        return $this->query->get();
    }
}
