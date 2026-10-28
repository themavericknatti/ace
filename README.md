# ⚡ Ace Core Framework (`acex/core`)

[![Latest Stable Version](https://img.shields.io/packagist/v/acex/core.svg?style=flat-square)](https://packagist.org/packages/acex/core)
[![Total Downloads](https://img.shields.io/packagist/dt/acex/core.svg?style=flat-square)](https://packagist.org/packages/acex/core)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-blue.svg?style=flat-square)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)

**Ace Core** is the high-performance, lightweight engine and library powering the **Ace PHP MVC Framework**. Inspired by modern PHP architecture patterns (Laravel, Symfony), it delivers enterprise capabilities without framework bloat:

- 🚀 **Zero Heavy Bloat**: Ultra-fast execution with minimal memory footprint.
- 🛡️ **Next-Gen Web Application Firewall (WAF)**: Built-in 8-layer security shield protecting against SQLi, RCE, XSS, Path Traversal, and Scanners.
- 🔒 **Airtight PHP2Shell Defense**: Complete mitigation for Windows CGI Argument Injection (CVE-2024-4577), soft-hyphen bypasses, and disguised web shell uploads.
- ⚡ **Future-Proof PHP 8.2 to 8.5+ Ready**: 100% compliant with PHP 8.2, 8.3, 8.4, and 8.5+ without deprecation warnings.
- 🧩 **Robust IoC / Dependency Injection Container**: Auto-wiring, singleton binding, and service locator patterns.
- 🌐 **Fluent HTTP Client**: Zero-dependency wrapper for consuming REST APIs with retry, bearer tokens, and JSON dot-notation parsing.
- 🗂️ **Unified Storage & Disks**: Driver-based filesystem abstraction (`local`, `public`, extensible to S3/R2).
- 🗄️ **ActiveRecord ORM with Soft Deletes & Nested Transactions**: Fluent queries, `withTrashed()`, `onlyTrashed()`, and savepoint-aware transactions.
- 🛡️ **Defensive Security & Validation**: Strict request validation via `FormRequest`, XSS sanitization, CSRF protection, and API key management.
- 🎨 **Multi-Template Presentation Layer**: Blade-like template engine with `@vite`, `@checked`, `@selected`, `@disabled`, layout inheritance, and view compilation caching.
- 📦 **Background Job Queue**: File/Database-backed worker queues for asynchronous background task execution.
- 💳 **Unified Payments Engine**: Driver-based payment gateway integration (Paystack, Stripe, Flutterwave).
- 🔁 **API Idempotency Manager**: Mutation deduplication and duplicate-request protection for modern API backends.

---

## 📦 Installation

Install `acex/core` via [Composer](https://getcomposer.org/):

```bash
composer require acex/core
```

---

## 📋 Requirements

- **PHP**: `^8.2` or higher
- **Extensions**:
  - `pdo` (`pdo_mysql`, `pdo_sqlite`, or `pdo_pgsql`)
  - `curl`
  - `json`

---

## 🏗️ Core Architecture Overview

Ace Core organizes framework responsibilities into decoupled, reusable modules under the `Ace\` namespace:

| Module | Core Classes | Purpose |
| :--- | :--- | :--- |
| **Kernel & Container** | `Ace\Application`, `Ace\Container` | Application bootstrap lifecycle and dependency injection. |
| **HTTP & Routing** | `Ace\Router`, `Ace\Route`, `Ace\Request`, `Ace\Response` | RESTful routing, route groups, URL parameters, and HTTP responses. |
| **HTTP Client** | `Ace\Http`, `Ace\HttpResponse` | Fluent REST client for consuming external web services and APIs. |
| **Storage & Disks** | `Ace\Storage`, `Ace\Storage\LocalStorageDriver` | Driver-based filesystem abstraction with public/local disks and URLs. |
| **Database & ORM** | `Ace\Database`, `Ace\Model`, `Ace\QueryBuilder`, `Ace\Relations\*` | PDO abstraction, Soft Deletes, savepoint transactions, and relationships. |
| **Validation** | `Ace\Validator`, `Ace\FormRequest` | Strict input validation rules, custom error messages, and payload sanitization. |
| **Presentation** | `Ace\View`, `Ace\ViewData`, `Ace\TemplateManager` | Template compilation, `@vite`, boolean form directives, components, and layouts. |
| **Queue & Worker** | `Ace\Queue`, `Ace\QueueWorker`, `Ace\Job` | Asynchronous task queues, worker daemon processing, and failure handling. |
| **Payments** | `Ace\Payment`, `Ace\Payments\*` | Unified payment driver abstraction for multiple gateways. |
| **Security & Firewall** | `Ace\Firewall`, `Ace\Middlewares\FirewallMiddleware`, `Ace\Guard`, `Ace\XssSanitizer` | Built-in WAF, CVE-2024-4577 / PHP2Shell armor, IP auto-banning, input & XSS sanitization. |
| **Auth & Sessions** | `Ace\ApiKey`, `Ace\Hash`, `Ace\Session` | Session handling, bcrypt hashing, and API authentication. |
| **Utilities** | `Ace\Collection`, `Ace\Paginator`, `Ace\Logger`, `Ace\Mail` | Fluent collections, data pagination, logging, and transactional email. |

---

## 💡 Quick Start Examples

### 1. Fluent HTTP Client (`Ace\Http`)

Consume third-party REST APIs cleanly without curl boilerplate:

```php
use Ace\Http;

// GET with query parameters
$response = Http::get('https://api.github.com/users/octocat');

// POST with Bearer token, JSON payload, and automatic retries
$response = Http::withToken('secret-api-token')
    ->timeout(10)
    ->retry(3, sleepMs: 100)
    ->post('https://api.example.com/orders', [
        'item_id' => 42,
        'quantity' => 2,
    ]);

if ($response->successful()) {
    $orderId = $response->json('data.order.id');
}
```

Global helper: `http()->withToken(...)->get(...)`.

---

### 2. Unified Filesystem Storage (`Ace\Storage`)

Store, retrieve, and generate URLs for files effortlessly:

```php
use Ace\Storage;

// Store on default local disk (storage/app)
Storage::put('reports/monthly.pdf', $pdfBinary);
$exists = Storage::exists('reports/monthly.pdf');
$content = Storage::get('reports/monthly.pdf');

// Store on public disk (storage/app/public) with accessible URL
Storage::disk('public')->put('avatars/user_1.png', $imageBinary);
$url = Storage::disk('public')->url('avatars/user_1.png'); // Returns: /storage/avatars/user_1.png
```

Global helper: `storage('public')->put(...)`.

---

### 3. Soft Deletes & Database Transactions

```php
use Ace\Model;
use Ace\Database;

class Article extends Model
{
    protected static string $table = 'articles';
    protected bool $softDelete = true; // Enables soft delete support
}

// 1. Soft delete and restoration
$article = Article::find(1);
$article->delete();                  // Sets deleted_at = now()
$article->trashed();                 // Returns true
$article->restore();                 // Restores deleted_at = null

// 2. Querying soft deleted records
$activeOnly  = Article::all();                    // Automatically excludes soft-deleted
$withDeleted = Article::withTrashed()->get();      // Includes soft-deleted
$onlyDeleted = Article::onlyTrashed()->get();      // Returns ONLY soft-deleted

// 3. Savepoint-Aware Database Transactions
Database::run(function () {
    $user = User::create(['name' => 'John']);
    Profile::create(['user_id' => $user->id, 'bio' => 'Hello']);
});
// or via global helper:
db_transaction(fn() => ...);
```

---

### 4. Modern Vite & Template Directives

In your Blade templates (`views/**/*.php`):

```html
<head>
    <!-- Automatic Vite HMR in development & production manifest loading -->
    @vite('resources/css/app.css', 'resources/js/app.js')
</head>
<body>
    <form method="POST" action="/profile">
        @csrf

        <!-- Clean boolean attribute directives -->
        <input type="checkbox" name="notifications" @checked($user->wants_notifications)>
        
        <select name="role">
            <option value="user" @selected($user->role === 'user')>User</option>
            <option value="admin" @selected($user->role === 'admin')>Admin</option>
        </select>

        <button type="submit" @disabled($user->is_suspended)>Save</button>
    </form>
</body>
```

---

### 5. Built-in Web Application Firewall (`Ace\Firewall`)

Ace includes an out-of-the-box 8-layer security shield that inspects every request before it reaches routes or controllers:

```php
// Inspect request manually or configure rules
use Ace\Firewall;

Firewall::configure([
    'enabled' => true,
    'mode' => 'block',              // 'block' or 'monitor'
    'auto_ban' => true,             // Auto-ban IPs that trigger >= 3 attacks
    'max_threat_score' => 3,
    'ban_duration' => 900,          // 15 minutes
    'whitelist_ips' => ['127.0.0.1'],
]);

// Unban an IP:
Firewall::unban('203.0.113.195');
```

- **CVE-2024-4577 Mitigation (PHP2Shell)**: Intercepts Windows CGI argument injections and `%ad` soft-hyphen tampering.
- **Web Shell Upload Armor**: Blocks executable scripts (`.php*`, `.phtml`, `.phar`, `.htaccess`), double extensions, and scans file headers for embedded PHP code.
- **SQLi & RCE Defense**: Automatically blocks `UNION SELECT`, time-based injections, chained bash/powershell commands, and reverse shell triggers.

---

## 🧪 Testing

Ace Core includes a comprehensive PHPUnit test suite:

```bash
composer test
```

---

## 📚 Complete Framework Guide

For exhaustive documentation on framework architecture, data flows, coding standards, and best practices, see [GUIDE.md](GUIDE.md).

---

## 🤝 Contributing & License

Contributions, bug reports, and pull requests are welcome on [GitHub](https://github.com/themavericknatti/ace-core).

The Ace Core Framework is open-sourced software licensed under the [MIT License](LICENSE).
