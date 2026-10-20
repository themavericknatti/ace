# Ace PHP MVC Framework — AI & Developer Guide

> **PURPOSE OF THIS FILE**: This document is the single source of truth for how the
> Ace Framework operates. It defines the standard architecture, data flow, naming
> conventions, file structure, and code patterns that MUST be followed by both
> human developers and AI/agentic coding assistants when building applications
> with this framework. **AI agents MUST read and follow this guide before generating
> any code**, even if the user's prompt does not explicitly mention it.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Directory Structure](#2-directory-structure)
3. [Standard Data Flow](#3-standard-data-flow)
4. [Layer Responsibilities](#4-layer-responsibilities)
5. [Routing](#5-routing)
6. [Controllers](#6-controllers)
7. [FormRequest & Validation](#7-formrequest--validation)
8. [Models](#8-models)
9. [Repositories](#9-repositories)
10. [Services](#10-services)
11. [Views & Templates](#11-views--templates)
12. [Response Patterns](#12-response-patterns)
13. [Middleware](#13-middleware)
14. [Events & Listeners](#14-events--listeners)
15. [Database & Migrations](#15-database--migrations)
16. [CLI Commands](#16-cli-commands)
17. [Naming Conventions](#17-naming-conventions)
18. [Enterprise Example: Full Feature](#18-enterprise-example-full-feature)
19. [API-First Development & Frontend Integration](#19-api-first-development--frontend-integration)
20. [Background Job Queue](#20-background-job-queue)
21. [ActiveRecord Relationships](#21-activerecord-relationships)
22. [Interactive Console (Tinker)](#22-interactive-console-tinker)
23. [Multi-Template Presentation Layer](#23-multi-template-presentation-layer)
24. [API Idempotency & Mutation Deduplication](#24-api-idempotency--mutation-deduplication)

---



## 1. Architecture Overview

Ace follows a **layered MVC + Service + Repository** architecture designed for
enterprise-scale PHP applications while remaining simple to understand:

```
Request → Route → Middleware → Controller → FormRequest (Validation)
                                    ↓
                               Service (Business Logic)
                                    ↓
                            Repository (Data Access)
                                    ↓
                               Model (Database)
                                    ↓
                          Response (View or JSON)
```

**Key Principles:**
- **Separation of Concerns**: Each layer has ONE job. Controllers don't query databases. Models don't contain business logic. Services don't render views.
- **Thin Controllers**: Controllers orchestrate — they call services, pass validated data, and return responses. No business logic inside controllers.
- **Fat Services**: All business logic, calculations, and multi-step workflows live in Service classes.
- **Repository Pattern**: All database queries and data access go through Repository classes, never directly in Controllers or Services.
- **Validated Input**: All user input is validated before it reaches any Service or Repository. Use FormRequest classes or `$this->validate()` in controllers.

---

## 2. Directory Structure

```
project-root/
├── app/                        # Application code (developer writes here)
│   ├── Controllers/            # HTTP Controllers
│   ├── Models/                 # ActiveRecord Models (database tables)
│   ├── Services/               # Business logic layer
│   ├── Repositories/           # Data access abstraction layer
│   ├── Requests/               # FormRequest validation classes
│   ├── Middlewares/            # Custom middleware classes
│   ├── Actions/                # Single-purpose action classes
│   ├── Events/                 # Event classes
│   └── Listeners/              # Event listener classes
├── config/
│   ├── app.php                 # Application configuration
│   ├── cors.php                # Cross-Origin Resource Sharing (React/Next/Nuxt)
│   ├── database.php            # Database configuration
│   └── settings.json           # Dynamic runtime settings
├── migrations/                 # Database schema migrations
├── seeds/                      # Database seeders
├── public/                     # Web server document root
│   ├── assets/                 # CSS, JS, images
│   ├── uploads/                # User-uploaded files
│   ├── .htaccess               # URL rewrite rules
│   └── index.php               # Single entry point (front controller)
├── routes/
│   ├── web.php                 # Web routes (HTML views)
│   └── api.php                 # API routes (/api prefix, CORS, API key auth)
├── src/                        # Core Ace Framework source (DO NOT MODIFY)
├── storage/                    # Logs, compiled views cache
├── tests/                      # PHPUnit test suites
│   ├── Feature/                # Integration/feature tests
│   └── Unit/                   # Unit tests
├── views/                      # Blade-like view templates
│   ├── layouts/                # Layout templates (main.php, admin.php, etc.)
│   ├── components/             # Reusable component partials
│   ├── errors/                 # Error pages (404.php, 500.php, 503.php)
│   └── [feature]/              # Feature-specific views (users/, products/, etc.)
├── .env                        # Environment variables (never commit)
├── .env.example                # Environment template
├── ace                         # CLI command runner
├── composer.json               # Dependencies & PSR-4 autoloading
└── GUIDE.md                    # THIS FILE — Framework guide
```

**PSR-4 Autoloading Namespaces:**
- `App\` → `app/` (Controllers, Models, Services, Repositories, Requests, etc.)
- `Ace\` → `src/` (Core framework — do not modify)

---

## 3. Standard Data Flow

### Web Request (HTML Form Submission)
```
1. Browser sends POST /users
2. Router matches route → UserController@store
3. Middleware runs (CSRF, Auth, etc.)
4. Controller calls FormRequest OR $this->validate() to validate input
5. Controller passes validated data to UserService
6. UserService executes business logic, calls UserRepository
7. UserRepository uses User Model to persist data
8. Controller redirects with flash message OR renders view
```

### API Request (JSON)
```
1. Client sends POST /api/users with JSON body
2. Router matches route → UserController@store
3. Middleware runs (Auth, RateLimit, etc.)
4. Controller validates input
5. Controller passes to Service → Repository → Model
6. Controller returns JSON response via $this->json() or $this->success()
```

### Read/Display Flow
```
1. GET /users → UserController@index
2. Controller calls UserService or UserRepository to fetch data
3. Repository returns paginated Paginator instance
4. Controller passes data to view: return $this->render('users/index', ['users' => $users])
5. View renders with @foreach, @paginate, {{ }} directives
```

---

## 4. Layer Responsibilities

| Layer | Location | Responsibility | Does NOT Do |
|-------|----------|---------------|-------------|
| **Route** | `routes/web.php` | Maps URL → Controller method | Logic, validation, queries |
| **Middleware** | `app/Middlewares/` | Auth checks, CSRF, rate limiting | Business logic, rendering |
| **Controller** | `app/Controllers/` | Orchestrates request → response | Direct DB queries, complex logic |
| **FormRequest** | `app/Requests/` | Validates & authorizes input | Business logic, DB operations |
| **Service** | `app/Services/` | Business logic, workflows, calculations | Direct DB queries, rendering |
| **Repository** | `app/Repositories/` | Data access, queries, CRUD | Business logic, validation |
| **Model** | `app/Models/` | Table mapping, relationships, rules | Business logic, rendering |
| **View** | `views/` | HTML rendering, template directives | Logic, DB queries, data mutation |

---

## 5. Routing

Routes are defined in `routes/web.php`. The `$router` variable is available automatically.

```php
use App\Controllers\UserController;
use App\Controllers\ProductController;

// Basic routes
$router->get('/', [HomeController::class, 'index'])->name('home');
$router->get('/about', [HomeController::class, 'about'])->name('about');

// RESTful routes with parameters
$router->get('/users', [UserController::class, 'index'])->name('users.index');
$router->get('/users/{id}', [UserController::class, 'show'])->name('users.show');
$router->post('/users', [UserController::class, 'store'])->name('users.store');
$router->put('/users/{id}', [UserController::class, 'update'])->name('users.update');
$router->delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');

// Middleware on routes
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard')
    ->middleware([AuthMiddleware::class]);

// Route groups with shared middleware
$router->group(['middleware' => [AuthMiddleware::class]], function ($router) {
    $router->get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    $router->put('/profile', [ProfileController::class, 'update'])->name('profile.update');
});
```

**URL Generation in Views:**
```php
route('users.show', $user->id)  // → /users/42
route('users.index')             // → /users
asset('css/style.css')           // → /assets/css/style.css
url('/custom/path')              // → http://domain.com/custom/path
```

---

## 6. Controllers

Controllers live in `app/Controllers/` and extend `Ace\Controller`.

**Generate:** `php ace make:controller User`

**Standard Controller Pattern:**
```php
<?php

namespace App\Controllers;

use Ace\Controller;
use Ace\Request;
use App\Services\UserService;
use App\Requests\StoreUserRequest;
use App\Requests\UpdateUserRequest;

class UserController extends Controller
{
    private UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
    }

    // GET /users — List all users (paginated)
    public function index(Request $request): string
    {
        $users = $this->userService->getAllPaginated(
            perPage: $request->getInt('per_page', 15)
        );

        return $this->render('users/index', [
            'users' => $users,
            'title' => 'All Users',
        ]);
    }

    // GET /users/{id} — Show single user
    public function show(Request $request, string $id): string
    {
        $user = $this->userService->findById($id);

        return $this->render('users/show', [
            'user'  => $user,
            'title' => $user->name,
        ]);
    }

    // GET /users/create — Show creation form
    public function create(Request $request): string
    {
        return $this->render('users/create', [
            'title' => 'Create User',
        ]);
    }

    // POST /users — Store new user
    public function store(Request $request): void
    {
        // Option A: Inline validation
        $data = $this->validate([
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'role'     => 'required|in:admin,user,editor',
        ]);

        // Option B: FormRequest (preferred for complex forms)
        // $formRequest = new StoreUserRequest();
        // $data = $formRequest->validate();

        $this->userService->createUser($data);

        $this->redirect('/users', 302, [
            'success' => 'User created successfully!'
        ]);
    }

    // GET /users/{id}/edit — Show edit form
    public function edit(Request $request, string $id): string
    {
        $user = $this->userService->findById($id);

        return $this->render('users/edit', [
            'user'  => $user,
            'title' => 'Edit ' . $user->name,
        ]);
    }

    // PUT /users/{id} — Update existing user
    public function update(Request $request, string $id): void
    {
        $data = $this->validate([
            'name'  => 'required|min:2|max:100',
            'email' => "required|email|unique:users,email,{$id}",
            'role'  => 'required|in:admin,user,editor',
        ]);

        $this->userService->updateUser($id, $data);

        $this->redirect("/users/{$id}", 302, [
            'success' => 'User updated successfully!'
        ]);
    }

    // DELETE /users/{id} — Delete user
    public function destroy(Request $request, string $id): void
    {
        $this->userService->deleteUser($id);

        $this->redirect('/users', 302, [
            'success' => 'User deleted.'
        ]);
    }
}
```

**Controller Methods Available:**
| Method | Purpose |
|--------|---------|
| `$this->render($view, $params)` | Render a Blade-like view template |
| `$this->validate($rules)` | Validate input, auto-redirect on failure |
| `$this->json($data, $code)` | Return JSON response |
| `$this->success($msg, $data)` | Return `{status: "success", ...}` JSON |
| `$this->error($msg, $code)` | Return `{status: "error", ...}` JSON |
| `$this->redirect($url, $code, $flash)` | Redirect with optional flash data |
| `$this->back($code, $flash)` | Redirect back to previous page |

---

## 7. FormRequest & Validation

### Standalone Validator (in Controllers)
```php
$data = $this->validate([
    'name'     => 'required|min:2|max:100',
    'email'    => 'required|email|unique:users,email',
    'age'      => 'numeric|min:18',
    'role'     => 'required|in:admin,user',
    'password' => 'required|min:8|confirmed',
    'website'  => 'url',
    'bio'      => 'max:500',
]);
```

### FormRequest Classes (Recommended for Enterprise)

**Generate:** `php ace make:request StoreUser`

```php
<?php
// app/Requests/StoreUserRequest.php

namespace App\Requests;

use Ace\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check if user is admin, etc.
        return !Application::isGuest();
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'role'     => 'required|in:admin,user,editor',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered.',
            'password.min' => 'Password must be at least 8 characters.',
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'Email Address',
            'name'  => 'Full Name',
        ];
    }
}
```

**Using in Controller:**
```php
public function store(Request $request): void
{
    $formRequest = new StoreUserRequest();
    $data = $formRequest->validate(); // Auto-redirects back on failure

    $this->userService->createUser($data);
    $this->redirect('/users', 302, ['success' => 'User created!']);
}
```

### Available Validation Rules

| Rule | Syntax | Description |
|------|--------|-------------|
| `required` | `'required'` | Field must not be empty |
| `email` | `'email'` | Must be valid email |
| `min` | `'min:3'` | Minimum length (string) or value (numeric) |
| `max` | `'max:255'` | Maximum length or value |
| `numeric` | `'numeric'` | Must be a number |
| `integer` | `'integer'` | Must be an integer |
| `boolean` | `'boolean'` | Must be true/false/0/1 |
| `url` | `'url'` | Must be valid URL |
| `date` | `'date'` | Must be parsable date |
| `in` | `'in:a,b,c'` | Value must be one of listed options |
| `not_in` | `'not_in:x,y'` | Value must NOT be listed |
| `confirmed` | `'confirmed'` | Must match `{field}_confirmation` |
| `same` | `'same:other_field'` | Must match another field |
| `different` | `'different:other'` | Must differ from another field |
| `regex` | `'regex:/^[A-Z]+$/'` | Must match regex pattern |
| `alpha` | `'alpha'` | Letters only |
| `alpha_num` | `'alpha_num'` | Letters and numbers only |
| `unique` | `'unique:table,column,except_id'` | Must be unique in database table |
| `exists` | `'exists:table,column'` | Must exist in database table |

Rules can be combined with pipes: `'required|email|max:255|unique:users,email'`

---

## 8. Models

Models live in `app/Models/` and extend `Ace\Model`. Each model maps to a database table.

**Generate:** `php ace make:model Product`

```php
<?php

namespace App\Models;

use Ace\Model;

class Product extends Model
{
    public static function tableName(): string
    {
        return 'products'; // Database table name
    }

    public function primaryKey(): string
    {
        return 'id';
    }

    // Mass-assignment protection
    protected array $fillable = [
        'name', 'description', 'price', 'category_id', 'status'
    ];

    // Fields hidden from serialization (toArray/JSON)
    protected array $hidden = [];

    // Fields that skip XSS sanitization (e.g. rich text)
    protected array $rawFields = ['description'];

    // Auto timestamps (created_at, updated_at)
    public bool $timestamps = true;

    // Enable soft deletes (uses deleted_at column)
    protected bool $softDelete = true;

    // Validation rules for the model
    public function rules(): array
    {
        return [
            'name'        => [self::RULE_REQUIRED, [self::RULE_MAX, 'max' => 255]],
            'price'       => [self::RULE_REQUIRED, self::RULE_NUMERIC],
            'category_id' => [self::RULE_REQUIRED, [self::RULE_EXISTS, 'table' => 'categories', 'column' => 'id']],
            'status'      => [self::RULE_REQUIRED, [self::RULE_IN, 'values' => ['active', 'draft', 'archived']]],
        ];
    }

    // Relationships
    public function category(): ?Model
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function reviews(): array
    {
        return $this->hasMany(Review::class, 'product_id');
    }
}
```

**Key Model Methods:**
| Method | Purpose |
|--------|---------|
| `Product::find(['status' => 'active'])` | Find records by criteria |
| `Product::findOne(['id' => 5])` | Find single record |
| `Product::paginate(15)` | Paginate all records |
| `Product::count(['status' => 'active'])` | Count matching records |
| `Product::query()->where(...)->get()` | Fluent QueryBuilder |
| `$product->save()` | Insert or update record |
| `$product->delete()` | Delete (or soft-delete) |
| `$product->loadData($array)` | Mass-assign attributes |
| `$product->validate()` | Run model validation rules |
| `$product->toArray()` | Convert to array (respects $hidden) |

---

## 9. Repositories

Repositories abstract data access. They contain all database queries so Services never talk to the database directly.

**Generate:** `php ace make:repository User`

```php
<?php

namespace App\Repositories;

use Ace\Repository;
use Ace\Paginator;
use App\Models\User;

class UserRepository extends Repository
{
    protected string $modelClass = User::class;

    // Custom query methods specific to this entity
    public function findByEmail(string $email): ?User
    {
        return $this->findBy('email', $email);
    }

    public function findActiveUsers(int $perPage = 15): Paginator
    {
        return $this->query()
            ->where('status', 'active')
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage);
    }

    public function findByRole(string $role): array
    {
        return $this->allWhere(['role' => $role], 'name', 'ASC');
    }
}
```

**Inherited Repository Methods:**
| Method | Purpose |
|--------|---------|
| `$repo->all()` | Get all records |
| `$repo->find($id)` | Find by primary key |
| `$repo->findOrFail($id)` | Find or throw exception |
| `$repo->findBy($col, $val)` | Find by column |
| `$repo->allWhere($criteria)` | Get all matching criteria |
| `$repo->paginate($perPage)` | Paginated results |
| `$repo->create($data)` | Insert new record |
| `$repo->update($id, $data)` | Update by ID |
| `$repo->delete($id)` | Delete by ID |
| `$repo->count($criteria)` | Count records |
| `$repo->exists($criteria)` | Check existence |
| `$repo->query()` | Get fluent QueryBuilder |

---

## 10. Services

Services contain business logic. They coordinate between repositories, perform calculations, validate business rules, and handle multi-step workflows.

**Generate:** `php ace make:service Order`

```php
<?php

namespace App\Services;

use Ace\Service;
use Ace\Hash;
use App\Repositories\UserRepository;

class UserService extends Service
{
    private UserRepository $userRepo;

    public function __construct()
    {
        $this->userRepo = new UserRepository();
    }

    public function getAllPaginated(int $perPage = 15): \Ace\Paginator
    {
        return $this->userRepo->findActiveUsers($perPage);
    }

    public function findById(string $id): \App\Models\User
    {
        return $this->userRepo->findOrFail($id);
    }

    public function createUser(array $data): \App\Models\User
    {
        // Business logic: hash password, set defaults
        $data['password'] = Hash::make($data['password']);
        $data['status'] = 'active';

        return $this->transaction(function () use ($data) {
            $user = $this->userRepo->create($data);

            // Dispatch event for side effects
            $this->dispatch('user.registered', $user);

            return $user;
        });
    }

    public function updateUser(string $id, array $data): bool
    {
        // Business logic: don't update password if not provided
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        return $this->userRepo->update($id, $data);
    }

    public function deleteUser(string $id): bool
    {
        return $this->userRepo->delete($id);
    }
}
```

**Service Base Methods:**
| Method | Purpose |
|--------|---------|
| `$this->transaction($callback)` | Run callback inside DB transaction |
| `$this->dispatch($event, ...$args)` | Fire application event |
| `$this->log($message, $level)` | Log information |

---

## 11. Views & Templates

Views live in `views/` and use a **Blade-like template syntax** compiled to PHP.

### Template Directives Reference

| Directive | Purpose | Example |
|-----------|---------|---------|
| `{{ $var }}` | Escaped output | `{{ $user->name }}` |
| `{!! $html !!}` | Raw unescaped output | `{!! $content !!}` |
| `@extends('layout')` | Set parent layout | `@extends('layouts/main')` |
| `@section('name')` | Start section block | `@section('content')` |
| `@endsection` | End section block | |
| `@yield('name')` | Output section in layout | `@yield('content')` |
| `@include('partial')` | Include sub-view | `@include('components/card')` |
| `@if(cond)` | Conditional start | `@if($user->isAdmin())` |
| `@elseif(cond)` | Else-if | `@elseif($user->isEditor())` |
| `@else` | Else | `@else` |
| `@endif` | End conditional | |
| `@foreach($arr as $item)` | Loop | `@foreach($users as $user)` |
| `@endforeach` | End loop | |
| `@auth` | If user is logged in | |
| `@endauth` | End auth block | |
| `@guest` | If user is guest | |
| `@endguest` | End guest block | |
| `@csrf` | CSRF hidden input field | `@csrf` |
| `@method('PUT')` | HTTP method spoofing | `@method('DELETE')` |
| `@error('field')` | Validation error block | `@error('email') {{ $message }} @enderror` |
| `@isset($var)` | If variable is set | `@isset($subtitle)` |
| `@empty($var)` | If variable is empty | `@empty($items)` |
| `@toasts` | Render toast notifications | `@toasts` |
| `@paginate($var)` | Render pagination links | `@paginate($users)` |
| `@url(expr)` | Safe URL output | `@url(route('home'))` |
| `@tailwind` | Tailwind CSS CDN (Zero npm) | `@tailwind` |
| `@bootstrap` | Bootstrap 5.3 CSS + JS Bundle (Zero npm) | `@bootstrap` |
| `@bootstrapCss` | Bootstrap 5.3 CSS only | `@bootstrapCss` |
| `@bootstrapJs` | Bootstrap 5.3 JS only | `@bootstrapJs` |
| `@css` | Load configured framework from .env | `@css` or `@css('tailwind')` |
| `@component('name')` | Start component | `@component('components/modal')` |
| `@endcomponent` | End component | |
| `@slot('name')` | Named slot content | `@slot('footer')` |
| `@endslot` | End slot | |
| `@@` | Escape literal @ | Renders as `@` |

### Zero-NPM Custom CSS Frameworks (Tailwind & Bootstrap)

Ace requires **no Node.js, npm, or build step** for frontend styling:
- **Tailwind CSS**: Use `@tailwind` or `@extends('layouts/tailwind')`. Works instantly via the official Tailwind Play CDN.
- **Bootstrap 5**: Use `@bootstrap` or `@extends('layouts/bootstrap')`. Works instantly via the official Bootstrap 5 CDN.
- **Auto-Configured**: Set `CSS_FRAMEWORK=tailwind` or `CSS_FRAMEWORK=bootstrap` in `.env`.
- **Automatic Pagination Sync**: When `@tailwind` or `@bootstrap` is used, `@paginate` automatically switches to match that framework's design!
- **Generate Layouts**: `php ace make:layout admin --framework=tailwind`

### View Example

```html
@extends('layouts/main')

@section('title', 'Users')

@section('content')
<div class="container">
    <h1>Users</h1>

    @if($users->total() > 0)
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->role }}</td>
                    <td>
                        <a href="@url(route('users.show', $user->id))">View</a>
                        <a href="@url(route('users.edit', $user->id))">Edit</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @paginate($users)
    @else
        <p>No users found.</p>
    @endif
</div>
@endsection
```

### Form Example with Validation Errors

```html
<form action="@url(route('users.store'))" method="POST">
    @csrf

    <div class="form-group">
        <label>Name</label>
        <input type="text" name="name" value="{{ old('name') }}" />
        @error('name')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" value="{{ old('email') }}" />
        @error('email')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" />
        @error('password')
            <span class="error">{{ $message }}</span>
        @enderror
    </div>

    <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="password_confirmation" />
    </div>

    <button type="submit">Create User</button>
</form>
```

---

## 12. Response Patterns

### Web Response (HTML)
```php
// Render view
return $this->render('users/index', ['users' => $users]);

// Redirect with flash
$this->redirect('/users', 302, ['success' => 'User created!']);

// Redirect back
$this->back(302, ['error' => 'Something went wrong.']);
```

### API/JSON Response
```php
// Generic JSON
$this->json(['users' => $data], 200);

// Standardized success
$this->success('User created', ['id' => $user->id], 201);

// Standardized error
$this->error('Validation failed', 422, $errors);

// Using JsonResource for API transformation
return (new UserResource($user))->toResponse($request);
return UserResource::collectionResponse($users);
```

---

## 13. Middleware

Middleware intercepts requests before they reach controllers.

**Generate:** `php ace make:middleware Admin`

```php
<?php

namespace App\Middlewares;

use Ace\Middleware;
use Ace\Application;

class AdminMiddleware extends Middleware
{
    protected function run(): void
    {
        $user = Application::currentUser();
        if (!$user || $user->role !== 'admin') {
            redirect('/login', 302, ['error' => 'Admin access required.']);
        }
    }
}
```

**Built-in Middleware:**
- `AuthMiddleware` — Redirects guests to login
- `GuestMiddleware` — Redirects authenticated users away
- `CsrfMiddleware` — Validates CSRF tokens (automatically bypassed for `/api/*` routes)
- `CorsMiddleware` — Handles CORS headers and OPTIONS preflights for frontend clients
- `ApiKeyMiddleware` — Validates API keys from `Authorization: Bearer` or `X-Api-Key` headers
- `RateLimitMiddleware` — Throttles requests
- `SecurityHeadersMiddleware` — Sets security HTTP headers

---

## 14. Events & Listeners

```php
// Dispatch an event
Event::dispatch('user.registered', $user);
Event::dispatch('order.completed', $order, $payment);

// Register listeners (in config/app.php or bootstrap)
Event::listen('user.registered', [SendWelcomeEmail::class, 'handle']);
Event::listen('user.registered', [CreateDefaultProfile::class, 'handle']);
```

---

## 15. Database & Migrations

**Generate migration:** `php ace make:migration create_products_table`

```php
<?php

use Ace\Migration;

class m_20261016_create_products_table extends Migration
{
    public function up(): string
    {
        return "CREATE TABLE products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            category_id INT,
            status ENUM('active', 'draft', 'archived') DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    public function down(): string
    {
        return "DROP TABLE IF EXISTS products;";
    }
}
```

**CLI Commands:**
```bash
php ace migrate              # Run pending migrations
php ace migrate:rollback     # Rollback last batch
php ace migrate:status       # Show migration status
```

---

## 16. CLI Commands

| Command | Description |
|---------|-------------|
| `php ace serve` | Start development server |
| `php ace test` | Run PHPUnit test suite |
| `php ace make:controller <Name>` | Generate Controller |
| `php ace make:model <Name>` | Generate Model |
| `php ace make:service <Name>` | Generate Service |
| `php ace make:repository <Name>` | Generate Repository |
| `php ace make:request <Name>` | Generate FormRequest |
| `php ace make:apikey <Name>` | Generate secure API key for frontend (React/Next/Nuxt) |
| `php ace apikey:list` | List all registered API keys |
| `php ace apikey:revoke <prefix>` | Deactivate an API key by prefix |
| `php ace make:middleware <Name>` | Generate Middleware |
| `php ace make:action <Name>` | Generate Action |
| `php ace make:event <Name>` | Generate Event |
| `php ace make:listener <Name>` | Generate Listener |
| `php ace make:migration <name>` | Generate Migration |
| `php ace make:seeder <Name>` | Generate Seeder |
| `php ace migrate` | Run pending migrations |
| `php ace migrate:rollback` | Rollback last batch |
| `php ace migrate:status` | Show migration status |
| `php ace db:seed` | Run database seeders |
| `php ace view:clear` | Clear compiled view cache |
| `php ace down` | Enable maintenance mode |
| `php ace up` | Disable maintenance mode |

---

## 17. Naming Conventions

| Item | Convention | Example |
|------|-----------|---------|
| **Controller** | PascalCase + `Controller` | `UserController`, `ProductController` |
| **Model** | PascalCase, singular | `User`, `Product`, `OrderItem` |
| **Service** | PascalCase + `Service` | `UserService`, `PaymentService` |
| **Repository** | PascalCase + `Repository` | `UserRepository`, `OrderRepository` |
| **FormRequest** | PascalCase + `Request` | `StoreUserRequest`, `UpdateProductRequest` |
| **Middleware** | PascalCase + `Middleware` | `AuthMiddleware`, `AdminMiddleware` |
| **Migration** | snake_case description | `create_users_table`, `add_role_to_users` |
| **Database Table** | snake_case, plural | `users`, `products`, `order_items` |
| **Database Column** | snake_case | `first_name`, `created_at`, `category_id` |
| **View File** | lowercase/kebab | `views/users/index.php`, `views/users/edit.php` |
| **Route Name** | dot notation | `users.index`, `users.show`, `admin.dashboard` |
| **Event** | PascalCase, past tense | `UserRegistered`, `OrderCompleted` |
| **Listener** | PascalCase, action | `SendWelcomeEmail`, `NotifyAdmin` |

---

## 18. Enterprise Example: Full Feature

Building a complete "Product Management" feature end-to-end:

### Step 1: Migration
```bash
php ace make:migration create_products_table
```

### Step 2: Model
```bash
php ace make:model Product
```

### Step 3: Repository
```bash
php ace make:repository Product
```

### Step 4: Service
```bash
php ace make:service Product
```

### Step 5: FormRequests
```bash
php ace make:request StoreProduct
php ace make:request UpdateProduct
```

### Step 6: Controller
```bash
php ace make:controller Product
```

### Step 7: Routes (`routes/web.php`)
```php
$router->get('/products', [ProductController::class, 'index'])->name('products.index');
$router->get('/products/create', [ProductController::class, 'create'])->name('products.create');
$router->post('/products', [ProductController::class, 'store'])->name('products.store');
$router->get('/products/{id}', [ProductController::class, 'show'])->name('products.show');
$router->get('/products/{id}/edit', [ProductController::class, 'edit'])->name('products.edit');
$router->put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
$router->delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');
```

### Step 8: Views
```
views/
  products/
    index.php    — List all products with pagination
    show.php     — Display single product details
    create.php   — Product creation form
    edit.php     — Product edit form
```

### Data Flow Summary for `POST /products`:
```
1. Browser submits form → POST /products
2. Router → ProductController@store
3. StoreProductRequest validates input (auto-redirect on failure)
4. Controller passes validated data → ProductService@createProduct
5. ProductService applies business logic → ProductRepository@create
6. ProductRepository uses Product Model → INSERT INTO products
7. Controller redirects to /products with success flash message
```

---

## 19. API-First Development & Frontend Integration

Ace provides first-class support for **API-first project development**, enabling frontend frameworks like **React, Next.js, Nuxt.js, and Vue** to communicate securely with the Ace backend via **API keys**.

### Architecture of API-First in Ace

```
Frontend (React / Next.js / Nuxt.js)
  │
  │  HTTP Request + Headers:
  │  Authorization: Bearer <api_key>   (or X-Api-Key: <api_key>)
  │
  ▼
public/index.php
  │
  ▼
routes/api.php  (auto-prefixed with /api)
  │
  ├── CorsMiddleware          (Handles Access-Control headers & OPTIONS preflight)
  ├── ApiKeyMiddleware        (Authenticates API key from database, checks expiry/status)
  │                           (Bypasses CSRF verification automatically for /api/*)
  ▼
Controller (orchestrates request)
  │
  ├── FormRequest / validate()  (Returns 422 JSON on validation failure)
  ├── Service                   (Domain business logic)
  ├── Repository                (Data access abstraction)
  └── Model                     (Database mapping)
  │
  ▼
Response ($this->success(), $this->error(), or $this->json())
```

### 1. Generating API Keys

Use the Ace CLI to generate secure, cryptographically random 64-character API keys:

```bash
# Generate key for React app
php ace make:apikey "React Frontend"

# Generate key with specific permissions and 90-day expiry
php ace make:apikey "Next.js App" --expires=90 --permissions=events:read,tickets:create

# List all registered keys
php ace apikey:list

# Revoke an API key by prefix
php ace apikey:revoke a1b2c3d4
```

> **Security**: The raw key is displayed **once** in the CLI upon generation. Only a SHA-256 hash is stored in the database.

### 2. Defining API Routes (`routes/api.php`)

All routes in `routes/api.php` are automatically:
- Prefixed with `/api`
- Protected with CORS headers
- CSRF-exempt
- Stateless (no sessions)

```php
<?php

/** @var \Ace\Router $router */

use App\Controllers\Api\EventController;
use App\Controllers\Api\TicketController;

// Public health check (unauthenticated)
$router->get('/health', fn() => json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]));

// Authenticated API group (protected by ApiKeyMiddleware)
$router->group('', function($router) {
    // Events endpoints: /api/events
    $router->get('/events', [EventController::class, 'index']);
    $router->get('/events/{id}', [EventController::class, 'show']);
    $router->post('/events', [EventController::class, 'store']);
    $router->put('/events/{id}', [EventController::class, 'update']);
    $router->delete('/events/{id}', [EventController::class, 'destroy']);

    // Tickets endpoints: /api/tickets
    $router->post('/tickets/book', [TicketController::class, 'book']);
}, [\Ace\Middlewares\ApiKeyMiddleware::class]);

// Versioned API group: /api/v1/*
$router->group('/v1', function($router) {
    $router->get('/events', [EventController::class, 'index']);
}, [\Ace\Middlewares\ApiKeyMiddleware::class]);
```

### 3. Connecting Frontend Frameworks

#### A. React (Vite / CRA)

In your React app `.env`:
```env
VITE_API_URL=http://localhost:8000/api
VITE_API_KEY=your_generated_64_character_api_key
```

API Client utility (`src/api/client.js`):
```javascript
const API_URL = import.meta.env.VITE_API_URL;
const API_KEY = import.meta.env.VITE_API_KEY;

export async function apiClient(endpoint, options = {}) {
  const { headers, ...customConfig } = options;

  const config = {
    method: options.method || 'GET',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${API_KEY}`,
      // Or alternatively: 'X-Api-Key': API_KEY,
      ...headers,
    },
    ...customConfig,
  };

  const response = await fetch(`${API_URL}${endpoint}`, config);
  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'API request failed');
  }

  return data;
}

// Example usage in a component:
// const events = await apiClient('/events');
// const newEvent = await apiClient('/events', { method: 'POST', body: JSON.stringify(formData) });
```

#### B. Next.js (App Router)

In your Next.js `.env.local`:
```env
ACE_API_URL=http://localhost:8000/api
ACE_API_KEY=your_generated_64_character_api_key
```

Server Component (`app/events/page.tsx`):
```typescript
async function getEvents() {
  const res = await fetch(`${process.env.ACE_API_URL}/events`, {
    headers: {
      'Authorization': `Bearer ${process.env.ACE_API_KEY}`,
      'Accept': 'application/json',
    },
    next: { revalidate: 60 },
  });

  if (!res.ok) throw new Error('Failed to fetch events');
  const result = await res.json();
  return result.data;
}

export default async function EventsPage() {
  const events = await getEvents();
  return (
    <div>
      {events.map((event: any) => (
        <div key={event.id}>
          <h2>{event.title}</h2>
          <p>{event.description}</p>
        </div>
      ))}
    </div>
  );
}
```

#### C. Nuxt.js 3

In your `nuxt.config.ts`:
```typescript
export default defineNuxtConfig({
  runtimeConfig: {
    public: {
      apiUrl: process.env.ACE_API_URL || 'http://localhost:8000/api',
      apiKey: process.env.ACE_API_KEY,
    }
  }
});
```

Composable (`composables/useAceApi.ts`):
```typescript
export const useAceApi = () => {
  const config = useRuntimeConfig();

  const fetchApi = async <T>(endpoint: string, options = {}) => {
    return await $fetch<T>(`${config.public.apiUrl}${endpoint}`, {
      headers: {
        'Authorization': `Bearer ${config.public.apiKey}`,
        'Content-Type': 'application/json',
      },
      ...options,
    });
  };

  return { fetchApi };
};
```

### 4. CORS Configuration (`config/cors.php`)

Configure allowed origins for local development and production frontends in `.env`:

```env
# Allow local React, Next.js, and Nuxt development ports:
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:5173,http://localhost:8080
CORS_ALLOWED_METHODS=GET,POST,PUT,PATCH,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Api-Key,X-Requested-With,Accept
CORS_MAX_AGE=86400
CORS_ALLOW_CREDENTIALS=false
```

### 5. Accessing API Key in Controllers

When `ApiKeyMiddleware` authenticates a request, the key information is available directly on `$request`:

```php
namespace App\Controllers\Api;

use Ace\Controller;
use Ace\Request;

class EventController extends Controller
{
    public function index(Request $request): void
    {
        // Access authenticated API key information:
        $keyName = $request->apiKeyName();          // e.g. "React Frontend"
        $keyRecord = $request->apiKey();            // Full record array
        
        // Permission checking:
        if (!$request->hasPermission('events:read')) {
            $this->error('Forbidden. Missing events:read permission.', 403);
            return;
        }

        $events = $this->eventService->getAll();
        $this->success('Events retrieved', $events);
    }
}
```

### 6. Standardized API Response Format

Always return consistent JSON from API controllers:

```json
// Success Response: $this->success('Events retrieved', $data)
{
  "status": "success",
  "message": "Events retrieved",
  "data": [ ... ]
}

// Error Response: $this->error('Event not found', 404)
{
  "status": "error",
  "message": "Event not found",
  "errors": []
}

// Validation Failure Response (Automatic 422 from FormRequest / $this->validate())
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."],
    "price": ["This field must be a number."]
  }
}
```

---

> **AI AGENTS**: When generating code for an Ace Framework project, ALWAYS follow
> this layered architecture. Generate Services, Repositories, FormRequests, and
> proper Views — even if the user only asks for "a CRUD page". The framework is
> designed for enterprise scale and consistency.

---

## 20. Background Job Queue

Ace includes a **database-backed asynchronous job queue** for offloading
long-running tasks (emails, image processing, reports) to background workers.
No Redis or external daemons required — pure PHP + PDO.

### Creating a Job

```bash
php ace make:job SendWelcomeEmail
```

This generates `app/Jobs/SendWelcomeEmailJob.php`:

```php
<?php
namespace App\Jobs;

use Ace\Job;

class SendWelcomeEmailJob extends Job
{
    public function handle(): void
    {
        $userId = $this->data['user_id'];
        // Send welcome email...
    }

    // Optional: called if job fails after all retries
    public function failed(?\Throwable $e = null): void
    {
        // Log or notify about the failure
    }
}
```

### Dispatching Jobs

```php
use App\Jobs\SendWelcomeEmailJob;
use Ace\Queue;

// Static dispatch (recommended)
SendWelcomeEmailJob::dispatch(['user_id' => 42]);

// Delayed dispatch (60 seconds)
SendWelcomeEmailJob::dispatchLater(60, ['user_id' => 42]);

// Direct Queue::push()
Queue::push(SendWelcomeEmailJob::class, ['user_id' => 42]);

// Global helper
dispatch(SendWelcomeEmailJob::class, ['user_id' => 42]);
```

### Running the Queue Worker

```bash
# Default: processes 'default' queue
php ace queue:work

# With options
php ace queue:work --queue=emails --sleep=5 --tries=5 --stop-when-empty
```

### Queue Management Commands

| Command                       | Description                                      |
|-------------------------------|--------------------------------------------------|
| `php ace make:job <Name>`     | Generate a new background Job class              |
| `php ace queue:work`          | Start processing queued jobs                     |
| `php ace queue:failed`        | List all permanently failed jobs                 |
| `php ace queue:retry <id>`    | Retry a specific failed job                      |
| `php ace queue:retry all`     | Retry all failed jobs                            |
| `php ace queue:clear [queue]` | Delete all pending jobs from a queue             |

### Migration

Run `php ace migrate` — the `m2026_01_01_000003_create_jobs_table.php`
migration creates both `jobs` and `failed_jobs` tables automatically.

### Sync Mode

Set `QUEUE_DRIVER=sync` in `.env` to execute jobs immediately (useful for
development and testing). When no database is configured, jobs also run synchronously.

---

## 21. ActiveRecord Relationships

Ace models support four relationship types via fluent **Relation** objects
that wrap the QueryBuilder for chainable, lazy-loaded queries.

### Defining Relationships

```php
use Ace\Model;
use Ace\Relations\HasOne;
use Ace\Relations\HasMany;
use Ace\Relations\BelongsTo;
use Ace\Relations\BelongsToMany;

class User extends Model
{
    // One-to-One: User has one Profile
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
        // Convention: profiles.user_id → users.id
    }

    // One-to-Many: User has many Posts
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
        // Convention: posts.user_id → users.id
    }

    // Many-to-Many: User has many Roles via pivot table
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
        // Pivot: role_user.user_id, role_user.role_id
    }
}

class Post extends Model
{
    // Inverse: Post belongs to a User
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
        // Convention: posts.user_id → users.id
    }
}
```

### Usage

```php
// Property access — executes query and caches result
$user->profile;  // Profile|null
$user->posts;    // array of Post
$user->roles;    // array of Role

// Method call — returns Relation for chaining
$user->posts()->where('status', 'published')->orderBy('id', 'DESC')->get();
$user->roles()->where('name', 'admin')->first();

// Many-to-Many pivot operations
$user->roles()->attach($roleId);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
```

### Convention Defaults

| Relationship    | Foreign Key Convention        | Local Key |
|-----------------|-------------------------------|-----------|
| `hasOne`        | `{model}_id` on related table | `id`      |
| `hasMany`       | `{model}_id` on related table | `id`      |
| `belongsTo`     | `{related}_id` on this table  | `id`      |
| `belongsToMany` | `{model}_id` on pivot table   | `id`      |

All keys are customizable via method parameters.

---

## 22. Interactive Console (Tinker)

Ace includes an interactive **REPL** (Read-Eval-Print Loop) console for
live debugging, database queries, and quick prototyping — similar to Laravel's Tinker.

### Starting the Console

```bash
php ace tinker
```

### Example Session

```
💬 Ace Interactive Console (Tinker)
--------------------------------------------------------
>>> 1 + 1
=> 2

>>> "Hello " . "World"
=> 'Hello World'

>>> User::count()
=> 42

>>> User::first()
=> App\Models\User {"id":1,"name":"John","email":"john@example.com"}

>>> Queue::size()
=> 3

>>> exit
Goodbye!
```

### Features

- **Auto-aliases**: All `app/Models/*.php` classes are aliased to root namespace
  (`User` instead of `App\Models\User`).
- **Model pretty-printing**: Model instances are displayed as class name + JSON.
- **Error handling**: Exceptions are caught gracefully with file/line info.
- **Commands**: `exit`/`quit`/`q` to leave, `clear`/`cls` to clear screen.
- **Full framework boot**: Database, config, helpers, and all services are available.

---

## 23. Multi-Template Presentation Layer

The **Multi-Template Layer** allows a single Ace application to render multiple visual designs (e.g. `minimal`, `premium`, `creative`) using the exact same underlying controllers, services, repositories, database models, carts, and authentication state.

Switching templates changes **only the presentation**; no session data, authentication credentials, customer shopping cart, or backend business logic is touched or reset.

### Directory Structure

Templates integrate cleanly with the existing `views/` directory:

```text
views/
├── templates/
│   ├── minimal/
│   │   ├── layouts/
│   │   │   └── main.php
│   │   └── shop/
│   │       └── index.php
│   ├── premium/
│   │   ├── layouts/
│   │   │   └── main.php
│   │   └── shop/
│   │       └── index.php
│   └── creative/
│       ├── layouts/
│       │   └── main.php
│       └── shop/
│           └── index.php
├── layouts/
│   └── main.php          (Shared fallback layout)
├── shop/
│   └── index.php         (Shared fallback view)
└── home.php
```

### View Resolution Order

When `TEMPLATES_ENABLED=true`:
1. **Active Template View**: `views/templates/{active}/{view}.php`
2. **Fallback Template View**: `views/templates/{fallback}/{view}.php` (if distinct from active)
3. **Shared / Root View**: `views/{view}.php`

If a template only customizes certain pages (e.g. storefront), all other views (admin panels, auth pages, errors) automatically fall back to standard root views without duplicating code.

### Configuration (`config/templates.php`)

```php
return [
    'enabled' => env('TEMPLATES_ENABLED', true),
    'default' => 'minimal',
    'strategy' => 'session', // 'session', 'visit', 'persistent', 'random', or 'fixed'
    'available' => [
        'minimal',
        'premium',
        'creative',
    ],
    'fallback' => 'minimal',
    'allow_overrides' => true,
    'allow_customer_selection' => false,
];
```

### Supported Selection Policies

| Policy | Strategy Identifier | Description |
|---|---|---|
| **Policy A** | `session` | Assigned once per new session; stable across requests for that session. |
| **Policy B** | `visit` | Assigned per visit; uses a 30-minute inactivity cookie to track navigation. |
| **Policy C** | `persistent` | Retained across sessions via 1-year first-party cookie or user account preference. |
| **Policy D** | `random` | Random selection per request (useful for testing layouts; preserves all data). |
| **Policy E** | `fixed` | Always uses the configured `default` template. |

### Controller & View API

Controllers don't need to know which template is active:

```php
class ProductController extends Controller
{
    public function index(): string
    {
        $products = $this->productService->getAll();
        $cart = $this->cartService->getCart();

        // Standard render call — automatically routed through active template!
        return view('shop.products.index', [
            'products' => $products,
            'cart' => $cart,
        ]);
    }

    // Optional: Explicit template override for a specific action/landing page
    public function specialLanding(): string
    {
        return $this->render('promo', ['data' => $data], 'creative');
    }
}
```

### Blade Directives & Helpers

- `@activeTemplate` — outputs the current active template identifier (e.g. `minimal`).
- `@template('creative') ... @endtemplate` — conditional block rendered only under a specific template.
- `@templateAsset('css/theme.css')` — loads template-specific asset or falls back to shared asset.
- `active_template()` — returns active template string.
- `template('premium')` — switches or inspects `TemplateManager`.

### CLI Generator

Generate a new visual template scaffold with one command:

```bash
php ace make:template modern
```

---

## 24. API Idempotency & Mutation Deduplication

Ace implements **Stripe-standard Idempotency-Key support** for mutation endpoints (`POST`, `PUT`, `PATCH`, `DELETE`). This prevents duplicate transactions, accidental double-orders, and payment collisions caused by network retries or client double-submits.

### How It Works

1. Client sends an HTTP mutation with the `Idempotency-Key` or `X-Idempotency-Key` header (typically a UUIDv4):
   ```http
   POST /api/orders HTTP/1.1
   Host: example.com
   Authorization: Bearer ace_key_...
   Idempotency-Key: 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
   Content-Type: application/json

   {"plan_id": 12, "amount": 99.00}
   ```
2. **First Request**: The `IdempotencyMiddleware` locks the key as `pending`, hashes the request method, URI, and JSON payload, runs your controller/service, captures the HTTP response (status + body), and stores it for 24 hours.
3. **Subsequent Retries**: If the same key arrives with the exact same payload, the controller is **not re-executed**. The cached response is replayed immediately with the header `Idempotent-Replayed: true`.
4. **Concurrent Requests**: If two threads send the same key simultaneously, the second receives `409 Conflict` (`Retry-After: 2`) until the first completes.
5. **Payload Mismatch Protection**: If an attacker or client re-uses an existing key with a different payload (e.g. altered amount), Ace rejects it with `422 Unprocessable Entity`.

### Route Registration

Apply `IdempotencyMiddleware` to sensitive mutation endpoints in `routes/api.php` or `routes/web.php`:

```php
use Ace\Middlewares\ApiKeyMiddleware;
use Ace\Middlewares\IdempotencyMiddleware;

// Require idempotency on payment/order routes
$router->post('/api/orders', [OrderController::class, 'store'], [
    ApiKeyMiddleware::class,
    IdempotencyMiddleware::class,
]);
```

### CLI Maintenance

Purge expired idempotency keys from the database (ideal for recurring cron jobs):

```bash
php ace idempotency:purge
```




## 25. Multi-Gateway Payments & Webhook Idempotency

Ace ships with a unified multi-gateway payment system supporting **Paystack**, **Flutterwave**, **Stripe**, and **Cryptocurrency** (NOWPayments / Coinbase Commerce) out of the box — all with built-in idempotency protection to prevent duplicate charges or double-crediting accounts.

### Configuration

All gateway credentials live in `config/payment.php`. Set the following `.env` keys:

```bash
# Default driver
PAYMENT_DEFAULT_DRIVER=paystack

# Paystack
PAYSTACK_PUBLIC_KEY=pk_test_xxx
PAYSTACK_SECRET_KEY=sk_test_xxx

# Flutterwave
FLW_PUBLIC_KEY=FLWPUBK_TEST-xxx
FLW_SECRET_KEY=FLWSECK_TEST-xxx
FLW_SECRET_HASH=your_webhook_secret_hash

# Stripe
STRIPE_PUBLIC_KEY=pk_test_xxx
STRIPE_SECRET_KEY=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Cryptocurrency (NOWPayments / Coinbase)
CRYPTO_PROVIDER=nowpayments   # or "coinbase"
CRYPTO_API_KEY=xxx
CRYPTO_IPN_SECRET=xxx
```

### Quick Start — Using the `payment()` Helper

```php
// Initialize a Paystack payment (default driver)
$result = payment()->initialize(
    email: 'customer@example.com',
    amount: 5000.00,
    callbackUrl: url('/payment/callback'),
);
// Redirect: $result['checkout_url']

// Verify a transaction
$verification = payment()->verify($reference);
if ($verification['status'] === 'success') {
    // Credit the user's account
}
```

### Switching Gateways

```php
// Use Stripe
$result = payment('stripe')->initialize('user@app.com', 29.99, url('/callback'));

// Use Flutterwave
$result = payment('flutterwave')->initialize('user@app.com', 5000, url('/callback'));

// Use Crypto (NOWPayments)
$result = payment('crypto')->initialize('user@app.com', 100.0, url('/callback'), null, [
    'pay_currency' => 'BTC',
    'price_currency' => 'USD',
]);
```

### Static Facade Syntax

```php
use Ace\Payment;

$result = Payment::driver('stripe')->initialize('user@app.com', 29.99, url('/callback'));
$status = Payment::verify($reference);
```

### Idempotent Payment Initialization

Prevents duplicate charges when the client retries (network timeout, browser refresh, etc.):

```php
// Same idempotency key = same checkout session returned, never a double charge
$result = payment()->idempotentInitialize(
    idempotencyKey: $request->header('Idempotency-Key'),
    email: 'customer@example.com',
    amount: 5000.00,
    callbackUrl: url('/payment/callback'),
);

if ($result['_idempotent_replay'] ?? false) {
    // This was a cached replay, not a new transaction
}
```

### Idempotent Webhook Processing

Prevents double-crediting accounts when payment gateways retry webhooks:

```php
// In your webhook controller:
public function handlePaystackWebhook()
{
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

    if (!payment('paystack')->verifyWebhookSignature($payload, $signature)) {
        return response()->json(['error' => 'Invalid signature'], 403);
    }

    $event = json_decode($payload, true);
    $eventId = $event['data']['reference'];

    $result = payment()->idempotentWebhook($eventId, function() use ($event) {
        // This closure only executes ONCE per unique event
        $verification = payment()->verify($event['data']['reference']);
        if ($verification['status'] === 'success') {
            // Credit user balance, issue ticket, fulfill order...
            return ['credited' => true, 'amount' => $verification['amount']];
        }
        return ['credited' => false];
    });

    // $result['replayed'] === true means this webhook was already processed
    return response()->json(['ok' => true]);
}
```

### Paystack Transfers / Payouts

```php
$paystack = payment('paystack');

// Create a transfer recipient
$recipient = $paystack->createTransferRecipient(
    name: 'John Doe',
    accountNumber: '0123456789',
    bankCode: '058', // GTBank
);

// Initiate a payout
$transfer = $paystack->initiateTransfer(
    amount: 50000.00,
    recipientCode: $recipient['recipient_code'],
    reason: 'Event host payout',
);
```

### Custom Gateway Drivers

Register your own payment gateway at runtime:

```php
payment()->extend('momo', function() {
    return new MoMoGateway();
});

$result = payment('momo')->initialize('user@app.com', 500, url('/callback'));
```

### Webhook Signature Verification by Provider

| Gateway      | Header                       | Algorithm     |
|--------------|------------------------------|---------------|
| Paystack     | `X-Paystack-Signature`       | HMAC-SHA512   |
| Flutterwave  | `verif-hash`                 | Secret Hash   |
| Stripe       | `Stripe-Signature`           | HMAC-SHA256   |
| NOWPayments  | `x-nowpayments-sig`          | HMAC-SHA512   |
| Coinbase     | `X-CC-Webhook-Signature`     | HMAC-SHA256   |

