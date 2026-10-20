<?php

namespace Ace\Relations;

use Ace\Model;

/**
 * BelongsTo — Inverse One-to-One or Many-to-One Relationship
 *
 * Example:
 *   // Post model:
 *   public function user(): BelongsTo {
 *       return $this->belongsTo(User::class);
 *   }
 *
 * Usage:
 *   $post->user; // User instance or null
 *   $post->user()->first();
 */
class BelongsTo extends Relation
{
    protected string $foreignKey;
    protected string $ownerKey;

    public function __construct(Model $parent, string $relatedClass, ?string $foreignKey = null, ?string $ownerKey = null)
    {
        $relatedInstance = new $relatedClass();
        $this->ownerKey = $ownerKey ?? $relatedInstance->primaryKey();

        if ($foreignKey === null) {
            $shortName = strtolower((new \ReflectionClass($relatedClass))->getShortName());
            $this->foreignKey = $shortName . '_id';
        } else {
            $this->foreignKey = $foreignKey;
        }

        parent::__construct($parent, $relatedClass);
    }

    protected function addConstraints(): void
    {
        $foreignVal = $this->parent->attributes[$this->foreignKey] ?? $this->parent->{$this->foreignKey} ?? null;
        if ($foreignVal !== null) {
            $this->query->where($this->ownerKey, $foreignVal);
        } else {
            $this->query->whereRaw('0 = 1');
        }
    }

    public function getResults(): ?Model
    {
        return $this->query->first();
    }
}
