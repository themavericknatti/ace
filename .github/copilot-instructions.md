# Ace PHP MVC Framework — AI Coding Instructions
#
# This file instructs AI assistants (GitHub Copilot, Cursor, Antigravity,
# ChatGPT, Claude, etc.) on how to generate code for this project.
# AI agents MUST read GUIDE.md for the full architecture reference.

## Framework Identity
- Name: Ace PHP MVC Framework
- Namespace: Core = `Ace\`, App = `App\`
- PHP Version: >= 8.2
- Pattern: MVC + Service Layer + Repository Pattern

## MANDATORY Architecture Rules
1. **NEVER put business logic in Controllers.** Controllers only orchestrate.
2. **NEVER put database queries in Controllers or Services.** Only Repositories query the database.
3. **ALWAYS validate user input** before passing to Services. Use `$this->validate()` or FormRequest classes.
4. **ALWAYS use the layered flow**: Controller → Service → Repository → Model.
5. **ALWAYS use proper naming**: `UserController`, `UserService`, `UserRepository`, `StoreUserRequest`, `User` (model).

## File Locations
- Controllers:   `app/Controllers/{Name}Controller.php`
- Models:        `app/Models/{Name}.php`
- Services:      `app/Services/{Name}Service.php`
- Repositories:  `app/Repositories/{Name}Repository.php`
- FormRequests:  `app/Requests/{Action}{Name}Request.php`
- Middleware:    `app/Middlewares/{Name}Middleware.php`
- Migrations:    `migrations/m_{timestamp}_{description}.php`
- Views:         `views/{feature}/{action}.php`
- Routes:        `routes/web.php`

## Code Generation Checklist
When asked to create a feature, generate ALL of these:
- [ ] Migration (if new table needed)
- [ ] Model (`app/Models/`)
- [ ] Repository (`app/Repositories/`)
- [ ] Service (`app/Services/`)
- [ ] FormRequest(s) (`app/Requests/`)
- [ ] Controller (`app/Controllers/`)
- [ ] Routes (`routes/web.php`)
- [ ] Views (`views/{feature}/`)

## View Template Syntax
- Use `{{ $var }}` for escaped output
- Use `{!! $html !!}` for raw output
- Use `@extends('layouts/main')` for layout inheritance
- Use `@section('content')` ... `@endsection` for sections
- Use `@foreach`, `@if`, `@else`, `@endif` for control flow
- Use `@csrf` in all forms
- Use `@method('PUT')` or `@method('DELETE')` for method spoofing
- Use `@error('field')` ... `@enderror` for validation errors
- Use `@paginate($collection)` for pagination
- Use `@url(route('name'))` for safe URLs in href attributes
- Use `@tailwind` or `@extends('layouts/tailwind')` for zero-npm Tailwind CSS
- Use `@bootstrap` or `@extends('layouts/bootstrap')` for zero-npm Bootstrap 5
- Use `@css` to load the framework configured in .env (CSS_FRAMEWORK=tailwind|bootstrap)
- Use `old('field')` to repopulate form fields after validation failure

## Validation Syntax
Rules use pipe-separated strings: `'required|email|max:255|unique:users,email'`

## Response Patterns
- Web: `return $this->render('view', $params)` or `$this->redirect($url, 302, $flash)`
- API: `$this->json($data)` or `$this->success($msg)` or `$this->error($msg, 422)`

## API-First Development & Frontend Integration
- API routes live in `routes/api.php` (auto-prefixed with `/api`, CORS enabled, CSRF bypassed)
- Frontends (React, Next.js, Nuxt.js) authenticate via API keys:
  - Header: `Authorization: Bearer <key>` OR `X-Api-Key: <key>`
  - Generate keys via CLI: `php ace make:apikey "React App"`
  - List keys: `php ace apikey:list`
  - Revoke key: `php ace apikey:revoke <prefix>`
- Middleware:
  - `Ace\Middlewares\CorsMiddleware` handles CORS for React/Next/Nuxt frontends
  - `Ace\Middlewares\ApiKeyMiddleware` validates keys from DB, checks expiry/status
- In controllers: `$request->apiKey()`, `$request->apiKeyName()`, `$request->hasPermission('perm')`

## Background Job Queue
- Create jobs: `php ace make:job SendWelcomeEmail` → `app/Jobs/SendWelcomeEmailJob.php`
- Jobs extend `Ace\Job`, implement `handle()`, optionally override `failed()`
- Dispatch: `SendWelcomeEmailJob::dispatch(['user_id' => 42])`
- Delayed: `SendWelcomeEmailJob::dispatchLater(60, $data)`
- Helper: `dispatch(SendWelcomeEmailJob::class, $data)`
- Worker: `php ace queue:work --queue=default --sleep=3 --tries=3`
- Manage: `php ace queue:failed`, `queue:retry <id|all>`, `queue:clear`
- Tables: `jobs` and `failed_jobs` (via migration `m2026_01_01_000003_create_jobs_table.php`)
- Sync mode: set `QUEUE_DRIVER=sync` in `.env` for immediate execution

## ActiveRecord Relationships
- `$this->hasOne(Related::class)` → one-to-one (returns `Ace\Relations\HasOne`)
- `$this->hasMany(Related::class)` → one-to-many (returns `Ace\Relations\HasMany`)
- `$this->belongsTo(Related::class)` → inverse (returns `Ace\Relations\BelongsTo`)
- `$this->belongsToMany(Related::class, 'pivot_table')` → many-to-many (returns `Ace\Relations\BelongsToMany`)
- Property access: `$user->posts` — executes query and caches result
- Method access: `$user->posts()->where('status', 'active')->get()` — fluent chain
- Pivot: `$user->roles()->attach($id)`, `detach($id)`, `sync([1,2,3])`

## Interactive Console (Tinker)
- `php ace tinker` — starts interactive REPL with full framework boot
- Auto-aliases all `app/Models/*.php` classes to root namespace
- Supports: PHP expressions, Model queries, Queue ops, math, arrays

## Multi-Template Presentation Layer
- Config: `config/templates.php` (enabled, default, strategy, available, fallback, allow_overrides)
- Policies: `session` (Policy A), `visit` (Policy B), `persistent` (Policy C), `random` (Policy D), `fixed` (Policy E)
- Views structure: `views/templates/{template_name}/{view}.php`
- Fallback hierarchy: Template-specific view → Fallback template view → Shared root `views/{view}.php`
- Directives: `@activeTemplate`, `@template('name') ... @endtemplate`, `@templateAsset('path')`
- Helpers: `active_template()`, `template('name')`, `template_asset('path')`, `view($view, $params, $template)`
- Controller override: `$this->render($view, $params, $template)` or `$this->setTemplate($template)`
- CLI: `php ace make:template <name>`
- Critical: Switching templates MUST NEVER touch or reset authentication, customer carts, or business data.

## API Idempotency & Mutation Deduplication
- Middleware: `Ace\Middlewares\IdempotencyMiddleware`
- Header: `Idempotency-Key` or `X-Idempotency-Key` (UUID)
- Behavior: Caches responses (24h) for identical method + path + payload; replays with `Idempotent-Replayed: true`
- Concurrent requests receive 409 Conflict with `Retry-After: 2`
- Reused keys with modified payloads receive 422 Unprocessable Entity
- Tables: `idempotency_keys` (via migration `m2026_01_01_000004_create_idempotency_keys_table.php`)
- CLI: `php ace idempotency:purge`
- Helper: `idempotency()` returns `Ace\IdempotencyManager`

## Multi-Gateway Payments & Webhook Idempotency
- Config: `config/payment.php` (default driver, per-gateway credentials, idempotency settings)
- Drivers: `paystack`, `flutterwave`, `stripe`, `crypto` (NOWPayments / Coinbase Commerce)
- Interface: `Ace\Payments\PaymentGatewayInterface` — unified contract for all gateways
- Manager: `Ace\Payments\PaymentManager` — driver factory with idempotent init/webhook
- Facade: `Ace\Payment` — static proxy (`Payment::driver('stripe')`, `Payment::initialize(...)`)
- Helper: `payment(?string $driver)` returns `PaymentManager` or specific gateway
- Idempotent Init: `payment()->idempotentInitialize($key, ...)` — prevents double charges
- Idempotent Webhook: `payment()->idempotentWebhook($eventId, fn() => ...)` — prevents double-crediting
- Custom Drivers: `payment()->extend('momo', fn() => new MoMoGateway())`
- All gateways forward `Idempotency-Key` headers to upstream APIs for native dedup

## For Full Reference
Read: GUIDE.md (in project root)

