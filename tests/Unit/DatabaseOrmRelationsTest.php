<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\Model;
use Ace\Database;
use Ace\Application;
use Ace\Relations\HasMany;
use Ace\Relations\HasOne;
use Ace\Relations\BelongsTo;
use Ace\Relations\BelongsToMany;
use PDO;

// =============================================================================
// Test Models for Relationships & Queries
// =============================================================================

class OrmUser extends Model
{
    public static function tableName(): string { return 'orm_users'; }
    public function primaryKey(): string { return 'id'; }
    public function rules(): array { return []; }

    protected array $fillable = ['name', 'email'];

    public function profile(): HasOne
    {
        return $this->hasOne(OrmProfile::class, 'user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(OrmPost::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(OrmRole::class, 'orm_role_user', 'user_id', 'role_id');
    }
}

class OrmProfile extends Model
{
    public static function tableName(): string { return 'orm_profiles'; }
    public function primaryKey(): string { return 'id'; }
    public function rules(): array { return []; }

    protected array $fillable = ['user_id', 'bio', 'website'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(OrmUser::class, 'user_id');
    }
}

class OrmPost extends Model
{
    public static function tableName(): string { return 'orm_posts'; }
    public function primaryKey(): string { return 'id'; }
    public function rules(): array { return []; }

    protected array $fillable = ['user_id', 'title', 'content', 'status', 'views'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(OrmUser::class, 'user_id');
    }
}

class OrmRole extends Model
{
    public static function tableName(): string { return 'orm_roles'; }
    public function primaryKey(): string { return 'id'; }
    public function rules(): array { return []; }

    protected array $fillable = ['name'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(OrmUser::class, 'orm_role_user', 'role_id', 'user_id');
    }
}

// =============================================================================
// Full ORM, QueryBuilder & Relationships Test Suite
// =============================================================================

class DatabaseOrmRelationsTest extends TestCase
{
    protected ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Use in-memory SQLite database for deterministic tests
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create in-memory SQLite tables
        $this->pdo->exec("
            CREATE TABLE orm_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            );

            CREATE TABLE orm_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                bio TEXT NULL,
                website TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            );

            CREATE TABLE orm_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT NULL,
                status TEXT NOT NULL DEFAULT 'published',
                views INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            );

            CREATE TABLE orm_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            );

            CREATE TABLE orm_role_user (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL
            );
        ");

        if (!isset(Application::$app)) {
            $rootDir = dirname(__DIR__, 2);
            new Application($rootDir, ['db' => ['database' => 'test']]);
        }

        // Mock Database component with translation for SQLite compatibility
        $db = $this->createMock(Database::class);
        $db->pdo = $this->pdo;
        $db->method('prepare')->willReturnCallback(function ($sql) {
            $cleanSql = str_replace(
                ['`', 'TIMESTAMP', 'LONGTEXT'],
                ['', 'DATETIME', 'TEXT'],
                $sql
            );
            return $this->pdo->prepare($cleanSql);
        });

        $db->method('getTableColumns')->willReturnCallback(function ($table) {
            $stmt = $this->pdo->prepare("PRAGMA table_info({$table})");
            $stmt->execute();
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_column($cols, 'name');
        });

        Application::$app->db = $db;
    }

    // =========================================================================
    // 1. Model CRUD & Hydration
    // =========================================================================

    public function test_model_insert_and_find(): void
    {
        $user = new OrmUser();
        $user->name = 'Jane Doe';
        $user->email = 'jane@example.com';
        $saved = $user->save();

        $this->assertTrue($saved);
        $this->assertNotEmpty($user->id);

        $fetched = OrmUser::findOne(['id' => $user->id]);
        $this->assertNotNull($fetched);
        $this->assertEquals('Jane Doe', $fetched->name);
        $this->assertEquals('jane@example.com', $fetched->email);
    }

    public function test_model_update_existing_record(): void
    {
        $user = new OrmUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->save();

        $user->name = 'Alice Smith';
        $user->save();

        $fetched = OrmUser::findOne(['id' => $user->id]);
        $this->assertEquals('Alice Smith', $fetched->name);
    }

    public function test_model_delete_record(): void
    {
        $user = new OrmUser();
        $user->name = 'Bob';
        $user->email = 'bob@example.com';
        $user->save();

        $id = $user->id;
        $deleted = $user->delete();
        $this->assertTrue($deleted);

        $fetched = OrmUser::findOne(['id' => $id]);
        $this->assertNull($fetched);
    }

    // =========================================================================
    // 2. QueryBuilder Fetching & Filters
    // =========================================================================

    public function test_query_builder_where_and_fetch(): void
    {
        // Seed test posts
        $this->pdo->exec("
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (1, 'Post 1', 'published', 150);
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (1, 'Post 2', 'draft', 10);
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (2, 'Post 3', 'published', 300);
        ");

        // Fetch published posts with views > 100
        $posts = OrmPost::query()
            ->where('status', 'published')
            ->where('views', '>', 100)
            ->orderBy('views', 'DESC')
            ->get();

        $this->assertCount(2, $posts);
        $this->assertEquals('Post 3', $posts[0]->title);
        $this->assertEquals('Post 1', $posts[1]->title);

        // Fetch first matching
        $first = OrmPost::query()->where('views', '>=', 300)->first();
        $this->assertNotNull($first);
        $this->assertEquals('Post 3', $first->title);
    }

    public function test_query_builder_where_in_and_count(): void
    {
        $this->pdo->exec("
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (1, 'P1', 'published', 50);
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (1, 'P2', 'published', 80);
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (2, 'P3', 'published', 90);
        ");

        $count = OrmPost::query()->whereIn('id', [1, 2])->count();
        $this->assertEquals(2, $count);
    }

    // =========================================================================
    // 3. HasOne Relationship
    // =========================================================================

    public function test_has_one_relationship_fetch(): void
    {
        $this->pdo->exec("
            INSERT INTO orm_users (id, name, email) VALUES (10, 'Mark', 'mark@example.com');
            INSERT INTO orm_profiles (user_id, bio, website) VALUES (10, 'Developer & Designer', 'https://mark.dev');
        ");

        $user = OrmUser::findOne(['id' => 10]);
        $this->assertNotNull($user);

        // Method syntax: $user->profile()->first()
        $profile = $user->profile()->first();
        $this->assertInstanceOf(OrmProfile::class, $profile);
        $this->assertEquals('Developer & Designer', $profile->bio);

        // Dynamic property syntax: $user->profile
        $this->assertEquals('Developer & Designer', $user->profile->bio);
        $this->assertEquals('https://mark.dev', $user->profile->website);
    }

    // =========================================================================
    // 4. BelongsTo Relationship
    // =========================================================================

    public function test_belongs_to_relationship_fetch(): void
    {
        $this->pdo->exec("
            INSERT INTO orm_users (id, name, email) VALUES (20, 'Sarah', 'sarah@example.com');
            INSERT INTO orm_posts (id, user_id, title, status, views) VALUES (5, 20, 'Sarah Story', 'published', 250);
        ");

        $post = OrmPost::findOne(['id' => 5]);
        $this->assertNotNull($post);

        // Method syntax: $post->user()->first()
        $author = $post->user()->first();
        $this->assertInstanceOf(OrmUser::class, $author);
        $this->assertEquals('Sarah', $author->name);

        // Dynamic property syntax: $post->user
        $this->assertEquals('Sarah', $post->user->name);
        $this->assertEquals('sarah@example.com', $post->user->email);
    }

    // =========================================================================
    // 5. HasMany Relationship
    // =========================================================================

    public function test_has_many_relationship_fetch(): void
    {
        $this->pdo->exec("
            INSERT INTO orm_users (id, name, email) VALUES (30, 'David', 'david@example.com');
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (30, 'David Post 1', 'published', 10);
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (30, 'David Post 2', 'draft', 5);
            INSERT INTO orm_posts (user_id, title, status, views) VALUES (30, 'David Post 3', 'published', 20);
        ");

        $user = OrmUser::findOne(['id' => 30]);
        $this->assertNotNull($user);

        // Query chained on relationship
        $published = $user->posts()->where('status', 'published')->orderBy('views', 'DESC')->get();
        $this->assertCount(2, $published);
        $this->assertEquals('David Post 3', $published[0]->title);
        $this->assertEquals('David Post 1', $published[1]->title);

        // Dynamic property syntax: $user->posts
        $allPosts = $user->posts;
        $this->assertCount(3, $allPosts);
    }

    // =========================================================================
    // 6. BelongsToMany (Many-to-Many via Pivot)
    // =========================================================================

    public function test_belongs_to_many_relationship_fetch_and_sync(): void
    {
        $this->pdo->exec("
            INSERT INTO orm_users (id, name, email) VALUES (40, 'Admin User', 'admin@example.com');
            INSERT INTO orm_roles (id, name) VALUES (1, 'Admin');
            INSERT INTO orm_roles (id, name) VALUES (2, 'Editor');
            INSERT INTO orm_roles (id, name) VALUES (3, 'Viewer');

            INSERT INTO orm_role_user (user_id, role_id) VALUES (40, 1);
            INSERT INTO orm_role_user (user_id, role_id) VALUES (40, 2);
        ");

        $user = OrmUser::findOne(['id' => 40]);
        $this->assertNotNull($user);

        // Fetch related roles
        $roles = $user->roles()->get();
        $this->assertCount(2, $roles);
        $roleNames = array_map(fn($r) => $r->name, $roles);
        $this->assertContains('Admin', $roleNames);
        $this->assertContains('Editor', $roleNames);

        // Dynamic property syntax
        $this->assertCount(2, $user->roles);
    }
}
