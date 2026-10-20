<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Collection;

class CollectionTest extends TestCase
{
    public function test_basic_array_wrapping_and_counting(): void
    {
        $c = collect([10, 20, 30]);
        $this->assertCount(3, $c);
        $this->assertEquals([10, 20, 30], $c->all());
        $this->assertEquals(10, $c->first());
        $this->assertEquals(30, $c->last());
    }

    public function test_map_and_filter(): void
    {
        $c = collect([1, 2, 3, 4, 5, 6]);

        $evensSquared = $c->filter(fn($n) => $n % 2 === 0)
                          ->map(fn($n) => $n * $n)
                          ->values()
                          ->all();

        $this->assertEquals([4, 16, 36], $evensSquared);
    }

    public function test_pluck_and_unique(): void
    {
        $users = collect([
            ['id' => 1, 'role' => 'admin', 'name' => 'Alice'],
            ['id' => 2, 'role' => 'member', 'name' => 'Bob'],
            ['id' => 3, 'role' => 'admin', 'name' => 'Charlie'],
        ]);

        $roles = $users->pluck('role')->unique()->values()->all();
        $this->assertEquals(['admin', 'member'], $roles);
    }

    public function test_group_by(): void
    {
        $items = collect([
            ['type' => 'fruit', 'name' => 'apple'],
            ['type' => 'vegetable', 'name' => 'carrot'],
            ['type' => 'fruit', 'name' => 'banana'],
        ]);

        $grouped = $items->groupBy('type');
        $this->assertInstanceOf(Collection::class, $grouped);
        $this->assertCount(2, $grouped['fruit']);
        $this->assertCount(1, $grouped['vegetable']);
    }

    public function test_sort_by_and_aggregations(): void
    {
        $numbers = collect([5, 1, 9, 3, 7]);
        $this->assertEquals(25, $numbers->sum());
        $this->assertEquals(5, $numbers->avg());
        $this->assertEquals(1, $numbers->min());
        $this->assertEquals(9, $numbers->max());

        $sorted = $numbers->sortBy(fn($x) => $x)->values()->all();
        $this->assertEquals([1, 3, 5, 7, 9], $sorted);
    }

    public function test_array_access_and_json_serialization(): void
    {
        $c = collect(['name' => 'Ace', 'version' => 2]);
        $this->assertTrue(isset($c['name']));
        $this->assertEquals('Ace', $c['name']);

        $json = json_encode($c);
        $this->assertEquals('{"name":"Ace","version":2}', $json);
    }
}
