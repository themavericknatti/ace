# ⚡ Ace Core Framework (`ace/core`)

[![Latest Stable Version](https://img.shields.io/packagist/v/ace/core.svg?style=flat-square)](https://packagist.org/packages/ace/core)
[![Total Downloads](https://img.shields.io/packagist/dt/ace/core.svg?style=flat-square)](https://packagist.org/packages/ace/core)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-blue.svg?style=flat-square)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)

**Ace Core** is the high-performance, lightweight engine and library powering the **Ace PHP MVC Framework**. Inspired by modern PHP architecture patterns (Laravel, Symfony), it delivers enterprise capabilities without framework bloat:

- 🚀 **Zero Heavy Bloat**: Ultra-fast execution with minimal memory footprint.
- 🧩 **Robust IoC / Dependency Injection Container**: Auto-wiring, singleton binding, and service locator patterns.
- 🗄️ **ActiveRecord ORM & Fluent Query Builder**: Clean database interaction with `hasOne`, `hasMany`, and `belongsTo` relationship support.
- 🛡️ **Defensive Security & Validation**: Strict request validation via `FormRequest`, XSS sanitization, CSRF protection, and API key management.
- 🎨 **Multi-Template Presentation Layer**: Blade-like template syntax, inheritance, layout engines, and view compilation caching.
- 📦 **Background Job Queue**: File/Database-backed worker queues for asynchronous background task execution.
- 💳 **Unified Payments Engine**: Driver-based payment gateway integration (Paystack, Stripe, Flutterwave).
- 🔁 **API Idempotency Manager**: Mutation deduplication and duplicate-request protection for modern API backends.

---

## 📦 Installation

Install `ace/core` via [Composer](https://getcomposer.org/):

```bash
composer require ace/core
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
| **Middlewares** | `Ace\Middleware`, `Ace\Middlewares\*` | HTTP pipeline interception, CORS negotiation, and maintenance mode. |
| **Database & ORM** | `Ace\Database`, `Ace\Model`, `Ace\QueryBuilder`, `Ace\Relations\*` | PDO abstraction, ActiveRecord records, relationship resolution, and fluent queries. |
| **Validation** | `Ace\Validator`, `Ace\FormRequest` | Strict input validation rules, custom error messages, and payload sanitization. |
| **Presentation** | `Ace\View`, `Ace\ViewData`, `Ace\TemplateManager` | Template compilation, directives, sections, components, and layout inheritance. |
| **Queue & Worker** | `Ace\Queue`, `Ace\QueueWorker`, `Ace\Job` | Asynchronous task queues, worker daemon processing, and failure handling. |
| **Payments** | `Ace\Payment`, `Ace\Payments\*` | Unified payment driver abstraction for multiple gateways. |
| **Security & Auth** | `Ace\Guard`, `Ace\ApiKey`, `Ace\Hash`, `Ace\Session`, `Ace\XssSanitizer` | Session handling, bcrypt hashing, API authentication, and input sanitization. |
| **Utilities** | `Ace\Collection`, `Ace\Paginator`, `Ace\Logger`, `Ace\Mail` | Fluent collections, data pagination, logging, and transactional email. |

---

## 💡 Quick Start Example

### 1. Booting the Application Kernel

```php
use Ace\Application;

$rootDir = dirname(__DIR__);
$config = [
    'app' => [
        'name' => 'My Ace App',
        'env' => 'development',
        'debug' => true,
    ],
    'db' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'ace_db',
        'username' => 'root',
        'password' => '',
    ],
];

$app = new Application($rootDir, $config);
```

### 2. Registering Routes

```php
use Ace\Router;
use Ace\Request;
use Ace\Response;

$router = $app->router;

$router->get('/', function (Request $request, Response $response) {
    return $response->json([
        'status' => 'success',
        'message' => 'Powered by Ace Core Framework v' . Application::VERSION,
    ]);
});

$router->get('/users/{id}', function (Request $request, Response $response) {
    $userId = $request->param('id');
    return $response->json(['user_id' => $userId]);
});
```

### 3. ActiveRecord Model & Queries

```php
use Ace\Model;

class User extends Model
{
    protected static string $table = 'users';
    protected static string $primaryKey = 'id';
}

// Fluent querying
$activeUsers = User::query()
    ->where('status', '=', 'active')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

---

## 🧪 Testing

Ace Core includes a full PHPUnit test suite covering the core modules:

```bash
composer test
```

or directly:

```bash
php vendor/bin/phpunit
```

---

## 📚 Complete Framework Guide

For exhaustive documentation on framework architecture, data flows, coding standards, and best practices, see [GUIDE.md](GUIDE.md).

---

## 🤝 Contributing & License

Contributions, bug reports, and pull requests are welcome on [GitHub](https://github.com/themavericknatti/ace-core).

The Ace Core Framework is open-sourced software licensed under the [MIT License](LICENSE).
