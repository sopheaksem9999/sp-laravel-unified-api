# SP Laravel Unified API

A comprehensive Laravel package that provides standardized API responses, dynamic API controllers, query helpers, audit logging, optional permission integration, flexible authentication via Laravel guards, and OpenAPI specification generation for ERP SaaS applications.

## 🚀 Quick Start

```bash
# 1. Install the package
composer require sopheak/sp-laravel-api

# 2. Generate/publish configs (record/audit/sp-laravel-api)
php artisan sp-laravel-api:setup

# 3. Publish package migrations + run migrations
php artisan vendor:publish --tag=sp-laravel-api-migrations
php artisan migrate

# 4. Define rate limiters in your AppServiceProvider (see below)

# 5. Configure at least 1 table in config/record.php (see below) or scaffold a table config via the record command

# 6. Test a public read endpoint
curl -X GET http://your-app.test/api/v1/users
```

## ✨ Features

- **🔄 Dynamic API Controller**: Full CRUD operations for any database table with advanced filtering
- **🚀 Upsert Support**: Atomic update-or-create operations with configurable matching logic
- **📊 Standardized API Responses**: Consistent JSON response format across your application
- **🔍 QueryHelpers Trait**: Powerful trait for advanced query filtering and manipulation
- **📝 Audit Logging**: Comprehensive audit trail for all data changes with queue-based processing
- **🔐 Permission System**: Optional Spatie Laravel Permission integration for role-based access control
- **🔑 Authentication Driver Agnostic**: Works with any Laravel auth guard (JWT, Sanctum, Passport, etc.)
- **📚 OpenAPI Spec Generation**: CLI command to generate API documentation
- **🎯 Request ID Middleware**: Automatic request tracking for debugging and monitoring
- **⚡ Performance Optimized**: Query caching and lazy loading
- **🧹 Audit Log Cleanup**: CLI command for cleaning old audit logs based on retention policy
- **🏢 Multi-Tenant Ready**: Built-in support for tenant isolation
- **🔧 Configuration Publishing**: Easy setup with sensible defaults

## 📚 Documentation

- [API Documentation](docs/api-documentation.md): Detailed guide on endpoints, request/response formats, and bulk operations.
- [Performance & Scalability](docs/performance.md): Benchmark results and optimization strategies.
- [Audit Interface](docs/audit-interface.md): How to implement custom audit logging.
- [Legacy Cursor Pagination](docs/cursor-pagination.md): Background on the removed cursor-based paginator.
- [Use Cases](docs/use-cases.md): Why use this for SaaS ERP or E-commerce.

## 📋 Requirements

- **PHP**: 8.2 or higher
- **Laravel**: 12.x
- **Database**: MySQL 8.0+, PostgreSQL 13+, or SQLite 3.8+
- **Extensions**: BCMath, Ctype, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML

## 📦 Dependencies and Optional Integrations

Core dependency installed with the package:
- **Carbon** (^2.0 or ^3.0) - Date manipulation library

Optional integrations you can install in your application:
- **Spatie Laravel Permission** (^6.21) - Role and permission management
- **PHP Open Source Saver JWT Auth** (^2.8.2) - JSON Web Token authentication (install/configure in your app)

## 📥 Installation & Setup

### Step 1: Install the Package

#### Option A: Via Composer (Recommended for Production)
```bash
composer require sopheak/sp-laravel-api
```

#### Option B: Local Development (VCS Repository)
Add this to your project's `composer.json`:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/sopheaksem9999/sp-laravel-api.git"
        }
    ],
    "require": {
        "sopheak/sp-laravel-api": "*"
    }
}
```

Then run:
```bash
composer update sopheak/sp-laravel-api -W
```

### Step 2: Publish/Generate Configuration Files
```bash
php artisan sp-laravel-api:setup
```

This command publishes package configs (tag: `sp-laravel-api-config`) and creates missing app config files:
- `config/record.php` - Database table configurations and relationships
- `config/audit.php` - Audit logging settings
- `config/sp-laravel-api.php` - Package settings (auth guard, OpenAPI output)

To overwrite existing generated configs, run:

```bash
php artisan sp-laravel-api:setup --force
```

### Step 3: Environment Configuration

Add these environment variables to your `.env` file:

```env
# Audit logging
AUDIT_LOG_ENABLED=true
AUDIT_LOG_RETENTION_DAYS=365

# Which Laravel auth guard the package uses
SP_LARAVEL_API_AUTH_GUARD=api
```

### Step 4: Publish Migrations and Run Migrations

```bash
php artisan vendor:publish --tag=sp-laravel-api-migrations
php artisan migrate
```

### Step 5: Configure Rate Limiters

The package routes use `throttle:api-reads`, `throttle:api-writes`, and `throttle:api-functions`. Define them in your app (example in `app/Providers/AppServiceProvider.php`):

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('api-reads', function (Request $request): Limit {
        $key = $request->user()?->getAuthIdentifier() ?? $request->ip();
        return Limit::perMinute(200)->by((string) $key);
    });

    RateLimiter::for('api-writes', function (Request $request): Limit {
        $key = $request->user()?->getAuthIdentifier() ?? $request->ip();
        return Limit::perMinute(100)->by((string) $key);
    });

    RateLimiter::for('api-functions', function (Request $request): Limit {
        $key = $request->user()?->getAuthIdentifier() ?? $request->ip();
        return Limit::perMinute(100)->by((string) $key);
    });
}
```

### Step 6: Validate Installation

Verify your installation is working correctly:

```bash
# Validate the complete setup
php artisan sp-laravel-api:validate

# Run with verbose output for detailed information
php artisan sp-laravel-api:validate --verbose

# Auto-fix common issues
php artisan sp-laravel-api:validate --fix
```

### Step 7: Configure Database Tables

Edit `config/record.php` to configure your database tables for the dynamic API. See the [examples directory](examples/config/record.php) for a complete configuration example:

```php
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordTableTriggerType;
return [
    'api_prefix' => 'api/v1',

    'tables' => [
        'users' => new RecordTableType(
            pmsName: 'user',
            table: 'users',
            isAuthRead: true,
            isAuthWrite: true,
            relationships: [
                'posts' => new RecordHasManyType(
                    table: 'posts',
                    foreignKey: 'user_id',
                    localKey: 'id',
                ),
            ],
            softDeletes: false,
            hasTenantId: false,
            createValidator: function (\Illuminate\Http\Request $request, ?int $id = null): \Illuminate\Contracts\Validation\Validator {
                return \Illuminate\Support\Facades\Validator::make($request->all(), [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email',
                ]);
            },
            updateValidator: function (\Illuminate\Http\Request $request, ?int $id = null): \Illuminate\Contracts\Validation\Validator {
                return \Illuminate\Support\Facades\Validator::make($request->all(), [
                    'name' => 'sometimes|required|string|max:255',
                ]);
            },
            deleteValidator: function (\Illuminate\Http\Request $request, ?int $id = null): \Illuminate\Contracts\Validation\Validator {
                return \Illuminate\Support\Facades\Validator::make(['id' => $id], [
                    'id' => 'required|integer',
                ]);
            },
            beforeCreate: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'beforeCreate',
            ),
            afterCreate: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'afterCreate',
            ),
            beforeUpdate: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'beforeUpdate',
            ),
            afterUpdate: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'afterUpdate',
            ),
            beforeDelete: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'beforeDelete',
            ),
            afterDelete: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'afterDelete',
            ),
            beforeRead: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'beforeRead',
            ),
            afterRead: new RecordTableTriggerType(
                class: \App\Record\Triggers\UserTriggers::class,
                functionName: 'afterRead',
            ),
        ),
    ],
];
```

The package routes are loaded automatically by `Sopheak\Core\CoreServiceProvider` using this prefix. Record endpoints authorize per-table using `isAuthRead` / `isAuthWrite` and permissions; `public` remains as legacy compatibility and is derived from auth flags.

You can override permission evaluation by setting `record.authorization` in `config/record.php`:

```php
'authorization' => \App\Security\RecordAuthorization::class,
```

Handler contract:

- class-string: container-resolved and must expose `handle($user, $permission, $table, $action): bool`
- callable/closure: invoked as `fn($user, string $permission, string $table, string $action): bool`
- `null`: fallback to default `Gate::forUser($user)->allows($permission)`

### Config-Driven Middleware Map (Client Use Case)

You can apply different middleware stacks per route action and per table without editing package routes.

```php
// config/record.php
'middleware_map' => [
    'default' => [
        '*' => [],
        'read' => [],
        'write' => ['auth:sanctum'],
        'function' => ['auth:sanctum'],
    ],
    'tables' => [
        // Public query routes
        'customers' => [
            'read' => [],
        ],
        // Auth + subscription routes
        'bills' => [
            'write' => ['auth:sanctum', 'subscribed'],
            'table_function' => ['auth:sanctum', 'subscribed'],
        ],
    ],
],
```

Action names available in the middleware map:
- `list`, `show`
- `create`, `update`, `delete`, `restore`, `force_delete`, `upsert`
- `bulk`, `bulk_create`, `bulk_update`, `bulk_delete`, `bulk_upsert`
- `table_function`, `global_function`
- grouped keys: `read`, `write`, `function`, and wildcard `*`

### Request Context for Hooks and Custom Audit

Request context is built-in and always available for hooks and custom audit callbacks.
Tenant resolution keeps backward compatibility:
- first from request attribute `resolved_tenant_id` (or `record_context.tenant_id`)
- then fallback to tenant header (`X-Tenant-ID` by default)

Client middleware can set tenant before CRUD/controller logic:

```php
public function handle($request, \Closure $next)
{
    $request->attributes->set('resolved_tenant_id', $request->user()?->tenant_id);

    return $next($request);
}
```

Or set directly into request context:

```php
$request->attributes->set('record_context', [
    'tenant_id' => $request->user()?->tenant_id,
]);
```

Use context inside trigger:

```php
public static function beforeCreate(\Illuminate\Http\Request $request, string $table, array $context): array
{
    $requestContext = $context['request_context'] ?? $request->attributes->get('record_context', []);
    $tenantId = $requestContext['tenant_id'] ?? null;
    $userId = $requestContext['user']['id'] ?? null;

    $payload = $request->all();
    $payload['tenant_id'] = $tenantId;
    $payload['created_by'] = $userId;
    $request->replace($payload);

    return [$request, $table, $context];
}
```

Alternatively, you can keep `config/record.php` focused on global options and define per-table configurations under `config/records/tables` using the Artisan helper:

### Table-Level Custom Audit Logger

You can override the default audit logging behavior for a specific table by providing a `customAuditLog` callback on the `RecordTableType` configuration. When set, this callback is invoked instead of the built-in `AuditLogService::insertAuditLog` calls for that table.

**Example table config (config/records/tables/invoices.php):**

```php
use Sopheak\Core\Types\RecordTableType;

return new RecordTableType(
    table: 'invoices',
    pmsName: 'invoice',
    isAuthRead: false,
    isAuthWrite: false,
    // String callback formats are supported...
    // customAuditLog: \App\Http\Controllers\InvoiceAuditLogger::class . '@handle',

    // ...and so is native PHP callable array syntax
    customAuditLog: [\App\Http\Controllers\InvoiceAuditLogger::class, 'handle'],
);
```

**Example custom audit handler:**

```php
namespace App\Http\Controllers;

use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;

class InvoiceAuditLogger
{
    public function handle(
        AuditLogEventEnum $event,
        string $entityClass,
        array $auditData,
        mixed $tenantId,
        array $context
    ): void {
        // Optionally transform or enrich $auditData here

        // Delegate to the core audit logic with your customized payload
        AuditLogService::handleAuditDataEntry(
            event: $event,
            entityName: AuditLogService::getTableNameFromEntityType($entityClass),
            entityType: AuditLogService::getTableNameFromEntityType($entityClass),
            queryData: $auditData,
            tenantId: $tenantId,
        );
    }
}
```

The `$context` parameter contains useful runtime information you can use for more advanced scenarios:

- `request` – The current `Illuminate\Http\Request` instance.
- `table` – The logical table name used in the API (e.g. `invoices`).
- `operation` – One of `create`, `update`, `delete`, or `upsert`.
- `record_context` – The internal record context used by `RecordService` (includes `id`, `payload`, `response`, etc. depending on the operation).

```bash
php artisan sp-laravel-api:record customers
```

This generates `config/records/tables/customers.php` returning a `RecordTableType` for the `customers` table. After creating the file and the underlying database table, you can sync its `columns` definition from the DB schema:

```bash
php artisan sp-laravel-api:sync-record-columns --force
```

### Step 8: Test Your Installation

Test the dynamic API endpoints:

```bash
# Test basic API functionality
curl -X GET http://your-app.test/api/v1/users

# Test with authentication (if configured)
curl -X GET http://your-app.test/api/v1/users \
  -H "Authorization: Bearer your-access-token"

# Test creating a record
curl -X POST http://your-app.test/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com"}'
```

## 🗄️ Database-Specific Instructions

### MySQL Configuration

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

**Recommended MySQL Settings:**
```sql
-- For better performance with large datasets
SET GLOBAL innodb_buffer_pool_size = 1G;
SET GLOBAL query_cache_size = 256M;
SET GLOBAL max_connections = 200;
```

### PostgreSQL Configuration

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### SQLite Configuration

```env
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite
```

**Note:** SQLite is suitable for development but not recommended for production use with this package.

## 🔧 Configuration Guide

### Core Configuration (`config/record.php`)

```php
return [
    // Multi-tenant mode (optional)
    'enable_tenant_id' => false,
    'tenant_column' => 'tenant_id',
    'tenant_header' => 'X-Tenant-ID',
    'table_config_path' => 'records/tables',

    // API route prefix
    'api_prefix' => 'api/v1',

    // permission 
    'permission_separator' => ':', // separator for permission ex: view:invoice
    'restrict_to_own_records' => false, // limit queries to records created by the authenticated user
    'own_records_permission_prefix' => 'viewOwn', // example: viewOwn_invoice

    // Global limits
    'per_page_max' => 10000,
    'limit_max' => 10000,
    'bulk_max' => 1000,

    // Relationship nesting limit
    'max_depth' => 10,

    // Query caching (used by QueryCacheService)
    'cache' => [
        'enabled' => env('SP_LARAVEL_API_CACHE_API', false),
        'ttl' => 3600,
        'prefix' => 'sp_laravel_api',
        'per_table' => [],
    ],
    
    // Global RPC function configurations
    'global_functions' => [],

    // Table configurations
    'tables' => [
        // Your table configurations here
    ],
];
```

#### Large Schemas (Many Tables)

For applications with many tables and global RPC functions, you can split configurations into multiple files and merge them in `config/record.php`. For example:

```php
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sopheak\Core\Types\RecordTableType;

$tables = [
    // Core tables defined inline
];
$globalFunctions = [];

$tablesDirectory = __DIR__ . '/records/tables';
$globalFunctionsDirectory = __DIR__ . '/records/globalFunctions';

if (is_dir($tablesDirectory)) {
    $directoryIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tablesDirectory)
    );

    foreach ($directoryIterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $config = require $path;

        if ($config instanceof RecordTableType) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $tables[$name] = $config;
        } elseif (is_array($config)) {
            $tables = array_merge($tables, $config);
        }
    }
}

if (is_dir($globalFunctionsDirectory)) {
    $globalFunctionsDirectoryIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($globalFunctionsDirectory)
    );

    foreach ($globalFunctionsDirectoryIterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $config = require $path;
        if (!is_array($config)) {
            continue;
        }

        $group = pathinfo((string) $path, PATHINFO_FILENAME);
        foreach ($config as $functionName => $functionConfig) {
            if (!is_string($functionName) || $functionName === '') {
                continue;
            }

            $normalizedFunctionName = ltrim($functionName, '/');
            $prefixedFunctionName = str_contains($normalizedFunctionName, '/')
                ? $normalizedFunctionName
                : $group . '/' . $normalizedFunctionName;

            $globalFunctions[$prefixedFunctionName] = $functionConfig;
        }
    }
}

return [
    'api_prefix' => 'api/v1',
    'enable_tenant_id' => false,
    'tenant_column' => 'tenant_id',
    'tenant_header' => 'X-Tenant-ID',
    'max_depth' => 10,
    'cache' => [
        'enabled' => env('SP_LARAVEL_API_CACHE_API', false),
        'ttl' => 3600,
        'prefix' => 'sp_laravel_api',
        'per_table' => [],
    ],
    'global_functions' => $globalFunctions,
    'tables' => $tables,
];
```

Each file under `config/records/tables` can return a single `RecordTableType` or an array of `[table_name => RecordTableType]`.
Each file under `config/records/globalFunctions` must return an array. File name becomes group prefix for keys without `/` (example: `auth.php` + `login` => `auth/login`).

### Authentication Setup

SP Laravel API does not ship its own authentication driver. Instead, it uses the Laravel `auth` guard you configure in `config/sp-laravel-api.php`, which can point to any driver (JWT, Sanctum, Passport, etc.):

```php
return [
    'auth' => [
        'guard' => env('SP_LARAVEL_API_AUTH_GUARD', 'api'),
    ],
];
```

Configure your desired guard in `config/auth.php` and set `SP_LARAVEL_API_AUTH_GUARD` accordingly.

#### Option A: JWT Authentication

```bash
# Install JWT package
composer require php-open-source-saver/jwt-auth
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret
```

Configure your User model:
```php
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
```

#### Option B: Laravel Sanctum

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### Middleware Configuration

SP Laravel API routes already include `request.id`. If you want request IDs on your own endpoints too, apply it to your routes:

```php
Route::middleware(['api', 'auth:api', 'request.id'])->group(function () {
    // Your API routes
});
```

### Step 7: Queue Configuration (Optional but Recommended)

For optimal performance with audit logging, configure queues:

```bash
# Install Redis (recommended)
composer require predis/predis

# Or use database queues
php artisan queue:table
php artisan migrate
```

Update your `.env`:
```env
QUEUE_CONNECTION=redis
# or
QUEUE_CONNECTION=database
```

Start the queue worker:
```bash
php artisan queue:work
```

### Step 8: Permissions Setup (Optional)

If you install Spatie Laravel Permission, you can set it up like this:

```bash
# Publish the permission migration
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# Run the migration
php artisan migrate
```

Create basic permissions for your tables:
```php
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Create permissions for your tables
Permission::create(['name' => 'view_users']);
Permission::create(['name' => 'create_users']);
Permission::create(['name' => 'update_users']);
Permission::create(['name' => 'delete_users']);

// Create roles and assign permissions
$adminRole = Role::create(['name' => 'admin']);
$adminRole->givePermissionTo(['view_users', 'create_users', 'update_users', 'delete_users']);

$userRole = Role::create(['name' => 'user']);
$userRole->givePermissionTo(['view_users']);
```

Add the trait to your User model:
```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
    
    // Your model code
}
```

## 🧪 Testing Instructions

### Running Package Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suites
vendor/bin/phpunit tests/Feature/DynamicApiTest.php
vendor/bin/phpunit tests/Unit/SchemaRegistryTest.php

# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Manual Testing

Create test data and verify API functionality:

```bash
# Create test user
php artisan tinker
>>> $user = \App\Models\User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => bcrypt('password')]);

# Test API endpoints
curl -X GET http://your-app.test/api/v1/users
curl -X GET http://your-app.test/api/v1/users/1
curl -X POST http://your-app.test/api/v1/users -H "Content-Type: application/json" -d '{"name":"New User","email":"new@example.com"}'
```

## 🔍 Troubleshooting

### Common Issues and Solutions

#### 1. "Class 'Sopheak\Core\CoreServiceProvider' not found"

**Solution:**
```bash
# Clear composer autoload cache
composer dump-autoload

# Ensure package is properly installed
composer require sopheak/sp-laravel-api

# Clear Laravel caches
php artisan config:clear
php artisan cache:clear
```

#### 2. "Configuration file not found"

**Solution:**
```bash
# Publish configuration files
php artisan vendor:publish --provider="Sopheak\Core\CoreServiceProvider"

# Or publish specific configs
php artisan vendor:publish --tag=sp-laravel-api-config
```

#### 3. "Database connection issues"

**Solution:**
```bash
# Test database connection
php artisan sp-laravel-api:validate --verbose

# Check database configuration
php artisan config:show database.connections.mysql

# Verify migrations
php artisan migrate:status
```

#### 4. "Permission denied errors" (using Spatie Permission)

**Solution (if you are using Spatie Laravel Permission):**
```bash
# Ensure permissions are created
php artisan permission:create-permission view_users
php artisan permission:create-permission create_users

# Assign permissions to user
php artisan tinker
>>> $user = \App\Models\User::find(1);
>>> $user->givePermissionTo('view_users');
```

#### 5. "Auth token issues" (JWT/Sanctum/Passport/etc)

**Solution:**
```bash
# Clear config cache
php artisan config:clear
```

Verify your guard configuration:
- `config/sp-laravel-api.php` → `auth.guard`
- `config/auth.php` → the configured guard/driver setup

#### 6. "API routes not working"

**Solution:**
```bash
# Check route registration
php artisan route:list | grep api

# Verify middleware configuration
php artisan route:list --middleware=api

# Clear route cache
php artisan route:clear
```

#### 7. "Command summary"
**Available artisan commands:**
**Solution:**
```bash
  # Clean old audit logs based on retention configuration
  php artisan sp-laravel-api:clean-audit-logs
  # Setup SP Laravel API package: publish configs and create record/audit configurations using config/record.php + config/records/tables/*.php + config/records/globalFunctions/*.php
  php artisan sp-laravel-api:setup
  # Create a RecordTableType config file under config/records/tables
  php artisan sp-laravel-api:record customers
  # Populate RecordTableType columns in config/records/tables PHP files based on DB schema
  php artisan sp-laravel-api:sync-record-columns
  # Validate SP Laravel API package setup and configuration
  php artisan sp-laravel-api:validate
```

### Debug Mode

Enable debug mode for detailed error information:

```env
APP_DEBUG=true
LOG_LEVEL=debug
```

### Validation Command

Use the validation command to diagnose issues:

```bash
# Run comprehensive validation
php artisan sp-laravel-api:validate --verbose --fix
```

## ⚙️ Advanced Configuration

### Performance Optimization

#### 1. Database Optimization

```php
// config/record.php
return [
    // Limit relationship nesting depth for better performance
    'max_depth' => 2,

    // Enable/disable query caching
    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
    ],
];
```

#### 2. Redis Configuration

```env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

#### 3. Queue Optimization

```bash
# Use multiple queue workers
php artisan queue:work --queue=high,default --tries=3 --timeout=60

# Use Supervisor for production
sudo apt-get install supervisor
```

### Security Configuration

#### 1. API Rate Limiting

The package routes use `throttle:api-reads`, `throttle:api-writes`, and `throttle:api-functions`. Define those limiters in your application (example shown in the installation steps).

#### 2. CORS Configuration

Laravel ships CORS configuration out of the box. Configure it in your app via `config/cors.php`.

#### 3. API Versioning

```php
// config/record.php
return [
    'api_prefix' => 'api/v1',
];
```

### Multi-tenancy Setup

```php
// config/record.php
return [
    'enable_tenant_id' => true,
    'tenant_column' => 'tenant_id',
    'tenant_header' => 'X-Tenant-ID',
];
```

When `enable_tenant_id=true`, requests for tables configured with `hasTenantId=true` must include the tenant header (default: `X-Tenant-ID`).

### Custom Middleware

```php
// Register custom middleware
protected $middlewareGroups = [
    'api' => [
        \App\Http\Middleware\TenantMiddleware::class,
        \Sopheak\Core\Http\Middleware\RequestId::class,
        \App\Http\Middleware\ApiVersioning::class,
    ],
];
```

## 🚀 Development Setup

### Setting up for Package Development

```bash
# Clone the repository
git clone https://github.com/your-username/sp-laravel-api.git
cd sp-laravel-api

# Install dependencies
composer install

# Set up testing environment
cp .env.example .env.testing
php artisan key:generate --env=testing

# Run tests
vendor/bin/phpunit
```

### Code Quality Tools

```bash
# Install development tools
composer require --dev rector/rector
composer require --dev phpunit/phpunit

# Fix code style
vendor/bin/rector process src
```

### Contributing Guidelines

1. Fork the repository
2. Create a feature branch
3. Write tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## 📚 Migration Guide

### From Version 1.x to 2.x

```bash
# Update composer.json
"sopheak/sp-laravel-api": "*"

# Update dependencies
composer update

# Republish configurations
php artisan vendor:publish --provider="Sopheak\Core\CoreServiceProvider" --force

# Run new migrations
php artisan migrate

# Update configuration format
# See examples/config/record.php for new format
```

### Breaking Changes in 2.x

- Configuration format changed to use Type classes
- New permission system integration
- Updated middleware registration
- Enhanced caching mechanisms

## 🎯 Performance Optimization

### Database Optimization

```sql
-- Add indexes for better performance
CREATE INDEX idx_audit_logs_entity ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at);
-- Optional (only if you enable multi-tenant mode and store tenant IDs in audit_logs)
-- CREATE INDEX idx_audit_logs_tenant_id ON audit_logs(tenant_id);
CREATE INDEX idx_users_email ON users(email);
```

### Caching Strategy

```php
// config/record.php
return [
    'cache' => [
        'enabled' => env('CACHE_API', false),
        'ttl' => 3600,
        'prefix' => 'sp_laravel_api',
        'per_table' => [],
    ],
];
```

### Queue Configuration

```bash
# Use Redis for better performance
QUEUE_CONNECTION=redis

# Configure queue workers
php artisan queue:work --queue=high,default --sleep=3 --tries=3 --max-time=3600
```

### Step 10: Configure Your Models (Optional)

To use the QueryHelpers trait in your models:

```php
use Sopheak\Core\Traits\QueryHelpers;

class User extends Authenticatable
{
    use QueryHelpers;
    
    // Your model code
}
```

To implement audit logging in your models:

```php
use Sopheak\Core\Traits\HasAuditQuery;
use Sopheak\Core\Interfaces\AuditQueryInterface;

class User extends Authenticatable implements AuditQueryInterface
{
    use HasAuditQuery;
    
    public function getAuditEntityName(): string
    {
        return 'users';
    }
    
    public function getAuditEntityClass(): string
    {
        return static::class;
    }
}
```

## Usage Examples

Once installed, you can immediately start using the dynamic API endpoints:

```bash
# List users with pagination
GET /api/users

# Get specific user with relationships
GET /api/users/1?with=posts,roles

# Create new user
POST /api/users
{
    "name": "John Doe",
    "email": "john@example.com"
}

# Update user
PUT /api/users/1
{
    "name": "Jane Doe"
}

# Delete user (soft delete)
DELETE /api/users/1

# Search users
GET /api/users?s=john&status=eq.active

# Advanced filtering
GET /api/users?age=gt.18&created_at=between.2024-01-01,2024-12-31
```

## Services

### RecordApiResponseService
Provides standardized JSON responses:
```php
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;

$response = app('api.response');
return $response->success($data, 'Operation successful');
return $response->error('Error message', RecordApiJsonResponseEnum::ERROR->value);
```

### AuditLogService
Comprehensive audit logging for data changes:
```php
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Enums\AuditLogEventEnum;

AuditLogService::handleAuditDataEntry(
    event: AuditLogEventEnum::CREATED,
    entityName: 'users',
    entityType: 'users',
    queryData: ['id' => 1, 'name' => 'John Doe'],
    subject: 'John Doe',
    recap: null,
    tenantId: null,
);
```

### AuditLogJob
Queue-based audit logging for improved performance:
```php
use Sopheak\Core\Jobs\AuditLogJob;
use Sopheak\Core\Enums\AuditLogEventEnum;

// Dispatch audit log job to queue
AuditLogJob::dispatch(
    event: AuditLogEventEnum::CREATED,
    entityName: 'users',
    entityType: 'users',
    queryData: ['id' => 1, 'name' => 'John Doe'],
    tenantId: null,
);
```

### QueryCacheService
Intelligent query caching:
```php
use Sopheak\Core\Services\QueryCacheService;

$result = QueryCacheService::remember(
    key: 'users:first',
    callback: fn () => \Illuminate\Support\Facades\DB::table('users')->first(),
    ttl: 3600,
);
```

## Middleware

The package registers the `request.id` middleware:

```php
Route::middleware('request.id')->group(function () {
    // Your routes here
});
```

## Usage
- Middleware:
  - Add `request.id` to routes or groups: `Route::middleware(['request.id'])->group(function () { ... });`.
- Responses:
  - Resolve via container: `$service = app('api.response');`
  - `return $service->success(['items' => []]);`
  - All responses include `meta.request_id` when middleware is active.

## CLI Commands

### OpenAPI Specification

OpenAPI is generated dynamically from record configuration at request time:

```text
GET /{api_prefix}/docs/openapi
GET /{api_prefix}/docs/openapi.json
GET /{api_prefix}/docs/llms.mdx
GET /{api_prefix}/docs/llms.txt
```

`/{api_prefix}/docs/openapi.json` is the recommended endpoint for AI agents and tools, returned with `application/vnd.oai.openapi+json`.
`/{api_prefix}/docs/llms.mdx` (or `llms.txt`) provides an AI-oriented markdown contract that points to the OpenAPI schema and key endpoint patterns.

Use the bundled Scalar page to browse the API documentation:

```text
GET /api-docs
```

The generated OpenAPI 3.0 schema includes:

**Enhanced Schema Generation:**
- **Full Schema**: Complete model schema with all properties
- **Read Schema**: Optimized for GET responses (includes computed fields, relationships)
- **Write Schema**: Optimized for POST/PUT requests (excludes read-only fields)

**Automatic Documentation:**
- Dynamic CRUD endpoints for all configured tables
- RPC function endpoints (global and table-specific)
- Comprehensive parameter documentation (pagination, filtering, sorting)
- Detailed response schemas with examples
- Security schemes (Bearer token authentication)

**Smart Table Detection:**
- Automatically discovers database tables
- Generates appropriate tags and descriptions
- Includes relationship documentation
- Supports custom table configurations

**Compatibility:**
- Compatible with Swagger UI, Postman, and other OpenAPI tools

**Example Generated Features:**
- RESTful endpoints: `GET /{prefix}/{table}`, `POST /{prefix}/{table}`, etc.
- RPC endpoints: `GET|POST|PUT|PATCH|DELETE /{prefix}/rpc/{functionName}`, `GET|POST|PUT|PATCH|DELETE /{prefix}/{table}/rpc/{functionName}`
- Advanced filtering and pagination parameters
- Comprehensive error response documentation

### Setup Package
```bash
php artisan sp-laravel-api:setup
```
Publishes default configurations for:
- `config/record.php` - Dynamic table and global function loader configuration
- `config/records/tables` - Per-table `RecordTableType` files
- `config/records/globalFunctions` - Global RPC function group files
- `config/audit.php` - Audit logging settings  
- `config/sp-laravel-api.php` - Package settings (auth guard, OpenAPI output)

### Record Table & Cache Management
```bash
# Create a new per-table RecordTableType config (config/records/tables/{name}.php)
php artisan sp-laravel-api:record customers

# Clear record cache
php artisan sp-laravel-api:cache-clear

# Sync RecordTableType columns in config/records/tables from DB schema
php artisan sp-laravel-api:sync-record-columns

# Clean old audit logs based on retention policy
php artisan sp-laravel-api:clean-audit-logs

# Clean audit logs with options
php artisan sp-laravel-api:clean-audit-logs --dry-run
php artisan sp-laravel-api:clean-audit-logs --force --days=30
php artisan sp-laravel-api:clean-audit-logs --batch-size=500
```

## API Endpoints

The package automatically registers RESTful API routes for dynamic database operations. The route prefix is configurable via `config('record.api_prefix')` (default: `api/v1`).

### Route Configuration

```php
// config/record.php
'api_prefix' => 'api/v1',
```

**Examples:**
- `'api'` → `/api/{table}`
- `'api/v1'` → `/api/v1/{table}`
- `'api/v2'` → `/api/v2/{table}`
- `'records'` → `/records/{table}`

### Standard CRUD Operations
- `GET /{prefix}/{table}` - List records with filtering and pagination
- `GET /{prefix}/{table}/{id}` - Get specific record
- `POST /{prefix}/{table}` - Create new record
- `PUT/PATCH /{prefix}/{table}/{id}` - Update record
- `POST /{prefix}/{table}/upsert` - Upsert (create or update) record
- `DELETE /{prefix}/{table}/{id}` - Soft delete record

### Advanced Operations
- `POST /{prefix}/{table}/{id}/restore` - Restore soft-deleted record
- `DELETE /{prefix}/{table}/{id}/force` - Permanently delete record
- `POST /{prefix}/{table}/bulk` - Bulk operations
- `POST /{prefix}/{table}/bulk/create` - Bulk create
- `POST /{prefix}/{table}/bulk/update` - Bulk update
- `POST /{prefix}/{table}/bulk/delete` - Bulk delete
- `POST /{prefix}/{table}/bulk/upsert` - Bulk upsert

### RPC Functions
- `GET|POST|PUT|PATCH|DELETE /{prefix}/rpc/{functionName}` - Execute global functions
- `GET|POST|PUT|PATCH|DELETE /{prefix}/{table}/rpc/{functionName}` - Execute table-specific functions

## Configuration

### Publishing Configuration Files
```bash
# Publish main package configuration
php artisan vendor:publish --tag=sp-laravel-api-config

# Publish all configurations (record, audit, cursor_pagination, sp-laravel-api)
php artisan sp-laravel-api:setup
```

### Main Configuration (`config/sp-laravel-api.php`)
```php
return [
    'response' => [
        'include_request_id' => env('SP_LARAVEL_API_INCLUDE_REQUEST_ID', true),
    ],
    'openapi' => [
        'output' => env('SP_LARAVEL_API_OPENAPI_OUTPUT', 'storage/openapi-schema.json'),
        'info' => [
            'title' => env('APP_NAME', 'Laravel API'),
            'version' => '2.0.0',
            'description' => 'Comprehensive API documentation with dynamic CRUD operations...',
        ],
        'servers' => [
            [
                'url' => env('APP_URL', 'http://localhost'),
                'description' => 'Development server',
            ],
        ],
    ],
];
```

### Record Configuration (`config/record.php`)
Controls database table operations and OpenAPI generation:
```php
return [
    'enable_tenant_id' => false,
    'api_prefix' => 'api/v1',
    'global_functions' => [
        // Define custom RPC functions here
        // 'functionName' => YourFunctionClass::class,
    ],
    // Table-specific configurations...
];
```

## Example Controller
```php
use Illuminate\Http\Request;

class ExampleController
{
    public function index(Request $request)
    {
        $responses = app('api.response');
        return $responses->success(['message' => 'OK']);
    }
}
```

## QueryHelpers Trait

The `QueryHelpers` trait provides powerful query filtering and manipulation capabilities for Eloquent models.

### Usage

```php
use Sopheak\Core\Traits\QueryHelpers;

class YourModel extends Model
{
    use QueryHelpers;
}
```

### Available Methods

- `applyRequestFilters($request, $isArray = false, $orderBy = 'id')` - Apply request-based filters

### Supported Query Parameters

- `s` - Search all fields (e.g., `?s=cambodia`)
- `select` - Specify columns including relationships (e.g., `?select=id,name,customer:id,name`)
- `with` - Load related models (e.g., `?with=user,posts.comments`)
- `sortby` - Column to sort by (e.g., `?sortby=name`)
- `order` - Sort direction: asc/desc (e.g., `?order=asc`)
- `per_page` - Enable pagination (e.g., `?per_page=20`)
- `limit` - Limit results when isArray=true (e.g., `?limit=1000`)
- `lazy` - Enable lazy loading (e.g., `?lazy=true`)

### Filter Operators

Supported syntax includes:
- Standard filters: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `between`, `not_between`, `like`, `ilike`, `contains`, `starts_with`, `ends_with`, `regex`, `match`, `imatch`, date operators, and null/empty operators.
- Grouped logic: `and=(...)`, `or=(...)`.
- Advanced expression style: `not.<operator>` and `operator(any|all).{...}`.
- List styles: both `id=in.(5,6,9)` and legacy `id=in.5,6,9`.
- Driver guard: unsupported operators return `422` validation error with explicit message.

For complete operator matrix, grouped logic examples, and driver compatibility details, see:
- `docs/api-documentation.md` → **Filter Operators** and **Grouped Logic**.

### Example Usage

```php
// In your controller
public function index(Request $request)
{
    $results = YourModel::query()
        ->applyRequestFilters($request, true);
    
    return response()->json($results);
}
```

You can also use named arguments when calling the scope (PHP 8+):

```php
$results = YourModel::query()->applyRequestFilters(
    request: $request,
    isArray: true,
    orderBy: 'created_at',
);
```

## Audit Log Cleanup

The package includes a powerful CLI command for cleaning old audit logs based on your retention policy.

### Configuration

Set the retention period in your audit configuration:

```php
// config/audit.php
'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 365),
```

### Command Options

- `--dry-run` - Show what would be deleted without actually deleting
- `--force` - Force deletion without confirmation prompt
- `--days=N` - Override retention days from config
- `--batch-size=N` - Number of records to delete per batch (default: 1000)

### Usage Examples

```bash
# Basic cleanup (uses config retention_days)
php artisan sp-laravel-api:clean-audit-logs

# Dry run to see what would be deleted
php artisan sp-laravel-api:clean-audit-logs --dry-run

# Force cleanup without confirmation
php artisan sp-laravel-api:clean-audit-logs --force

# Override retention period to 30 days
php artisan sp-laravel-api:clean-audit-logs --days=30

# Use smaller batch size for large datasets
php artisan sp-laravel-api:clean-audit-logs --batch-size=500

# Combine options
php artisan sp-laravel-api:clean-audit-logs --dry-run --days=90
```

### Features

- **Safe by default**: Requires confirmation unless `--force` is used
- **Batch processing**: Deletes records in configurable batches to prevent database locks
- **Progress tracking**: Shows real-time progress with progress bar
- **Statistics**: Reports total deleted and remaining records
- **Dry run mode**: Preview what would be deleted without making changes
- **Configurable**: Respects audit configuration or allows override

## Documentation

### Package Documentation
- **API Documentation**: [`docs/api-documentation.md`](docs/api-documentation.md) - Comprehensive API endpoints and usage guide
- **Audit Interface**: [`docs/audit-interface.md`](docs/audit-interface.md) - Custom audit queries and logging
- **Performance**: [`docs/performance.md`](docs/performance.md) - Benchmarks and optimization notes
- **Use Cases**: [`docs/use-cases.md`](docs/use-cases.md) - Why use this for SaaS/ERP style APIs

### Core Classes Reference
- **Request ID Middleware**: `Sopheak\Core\Http\Middleware\RequestId`
- **API Response Service**: `Sopheak\Core\Services\RecordApiResponseService`
- **Audit Log Service**: `Sopheak\Core\Services\AuditLogService`
- **Query Cache Service**: `Sopheak\Core\Services\QueryCacheService`
- **Record API Controller**: `Sopheak\Core\Http\Controllers\CoreRecordController`
- **Query Helpers Trait**: `Sopheak\Core\Traits\QueryHelpers`
- **Audit Query Interface**: `Sopheak\Core\Interfaces\AuditQueryInterface`

## Notes
- Keep `request.id` middleware active to ensure `meta.request_id` consistency.
- Extend the OpenAPI generator as needed for your endpoints.

## 📄 License

This package is proprietary software.
