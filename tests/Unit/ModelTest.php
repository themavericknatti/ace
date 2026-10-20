<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Model;

class TestUserModel extends Model
{
    public static function tableName(): string { return 'users'; }
    public function primaryKey(): string { return 'id'; }
    public function rules(): array { return []; }

    protected array $fillable = ['name', 'email', 'password'];
    protected array $hidden = ['password'];
}

class TestSoftModel extends Model
{
    public static function tableName(): string { return 'posts'; }
    public function primaryKey(): string { return 'id'; }
    public function rules(): array { return []; }

    protected bool $softDelete = true;
}

class ModelTest extends TestCase
{
    public function test_attributes_and_hidden_serialization(): void
    {
        $user = new TestUserModel();
        $user->loadData([
            'id' => 10,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'supersecret',
        ]);

        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);

        $array = $user->toArray();
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('email', $array);

        // Make visible test
        $visibleArray = $user->makeVisible('password')->toArray();
        $this->assertArrayHasKey('password', $visibleArray);
    }

    public function test_soft_deletes_flag_and_trashed_state(): void
    {
        $post = new TestSoftModel();
        $post->loadData([
            'id' => 1,
            'title' => 'Sample Post',
            'deleted_at' => null
        ]);

        $this->assertFalse($post->isTrashed());

        $post->deleted_at = '2026-10-05 12:00:00';
        $this->assertTrue($post->isTrashed());
    }

    public function test_timestamps_default_state(): void
    {
        $user = new TestUserModel();
        $this->assertTrue($user->timestamps);
        $this->assertEquals('created_at', TestUserModel::CREATED_AT);
        $this->assertEquals('updated_at', TestUserModel::UPDATED_AT);
    }

    public function test_force_fill_bypasses_mass_assignment(): void
    {
        $user = new TestUserModel();
        $user->forceFill([
            'id' => 999,
            'unfillable_db_column' => 'system_generated',
            'created_at' => '2026-01-01 00:00:00'
        ]);

        $this->assertEquals(999, $user->id);
        $this->assertEquals('system_generated', $user->unfillable_db_column);
        $this->assertEquals('2026-01-01 00:00:00', $user->created_at);
    }

    public function test_relationship_helpers_return_null_or_empty_when_foreign_key_missing(): void
    {
        // Null out db so QueryBuilder returns empty results without hitting real tables
        $originalDb = null;
        if (isset(\Ace\Application::$app)) {
            $originalDb = \Ace\Application::$app->db;
            \Ace\Application::$app->db = null;
        }

        $user = new TestUserModel();
        // With no primary key value, relations add "0 = 1" constraint — results are empty/null
        $this->assertNull($user->hasOne(TestSoftModel::class)->getResults());
        $this->assertEquals([], $user->hasMany(TestSoftModel::class)->getResults());
        $this->assertNull($user->belongsTo(TestSoftModel::class)->getResults());

        // Relationships return fluent Relation objects for method queries
        $this->assertInstanceOf(\Ace\Relations\HasOne::class, $user->hasOne(TestSoftModel::class));
        $this->assertInstanceOf(\Ace\Relations\HasMany::class, $user->hasMany(TestSoftModel::class));
        $this->assertInstanceOf(\Ace\Relations\BelongsTo::class, $user->belongsTo(TestSoftModel::class));

        if (isset(\Ace\Application::$app)) {
            \Ace\Application::$app->db = $originalDb;
        }
    }
}


