# App Core (Laravel Package)

Core utilities for Laravel apps: standardized API responses, request ID middleware, and simple CLI helpers. Designed to drop into any Laravel app via path-based Composer install.

## Features
- **Standardized API Responses**: Consistent JSON response format across your application
- **Request ID Middleware**: Automatic request tracking for debugging and monitoring
- **Dynamic API Controller**: Full CRUD operations for any database table with advanced filtering
- **QueryHelpers Trait**: Powerful trait for advanced query filtering and manipulation
- **Audit Logging**: Comprehensive audit trail for all data changes
- **Audit Interface**: Custom audit queries with `AuditQueryInterface` and `HasAuditQuery` trait
- **AuditLogJob**: Queue-based audit logging for improved performance
- **Audit Log Cleanup**: CLI command for cleaning old audit logs based on retention policy
- **Query Caching**: Intelligent caching system for improved performance
- **Permission System**: Built-in Spatie Laravel Permission for role-based access control
- **JWT Authentication**: Integrated JWT authentication support
- **OpenAPI Spec Generation**: CLI command to generate API documentation
- **Configuration Publishing**: Easy setup with sensible defaults

## Installation & Setup

### Requirements
- PHP 8.2 or higher
- Laravel 12.x
- MySQL 8.0+ or PostgreSQL 13+

### Included Dependencies
The package automatically installs these dependencies:
- **Spatie Laravel Permission** (^6.21) - Role and permission management
- **JWT Auth** (^2.8.2) - JSON Web Token authentication
- **Carbon** (^3.0) - Date manipulation library

### Step 1: Install the Package

#### Option A: Via Composer (Recommended for Production)
```bash
composer require sopheak/sp-laravel-api
```

#### Option B: Local Development (Path Repository)
Add this to your project's `composer.json`:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../sp-laravel-api"
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

### Step 2: Publish Configuration Files
```bash
php artisan sp-laravel-api:setup
```

This command publishes the following configuration files:
- `config/record.php` - Database table configurations and relationships
- `config/audit.php` - Audit logging settings
- `config/cursor_pagination.php` - Cursor pagination settings
- `config/sp-laravel-api.php` - Main package configuration

### Step 3: Environment Configuration

Add these environment variables to your `.env` file:

```env
# API Configuration
RECORD_API_PREFIX=api
RECORD_MAX_DEPTH=3
RECORD_CACHE_TTL=3600
RECORD_LAZY_CACHE_TTL=300

# Audit Logging
AUDIT_LOG_ENABLED=true
AUDIT_LOG_RETENTION_DAYS=365
AUDIT_LOG_QUEUE_ENABLED=true

# Cursor Pagination
CURSOR_PAGINATION_AUTO_THRESHOLD=1000
CURSOR_PAGINATION_DEFAULT_PER_PAGE=15
CURSOR_PAGINATION_MAX_PER_PAGE=100

# JWT Authentication (if using JWT)
JWT_SECRET=your-jwt-secret-key
JWT_TTL=60
JWT_REFRESH_TTL=20160
```

### Step 4: Database Setup

#### Run Migrations
The package includes migrations for audit logging. Run them with:
```bash
php artisan migrate
```

#### Configure Database Tables (Optional)
Edit `config/record.php` to configure your database tables for the dynamic API:

```php
return [
    'api_prefix' => env('RECORD_API_PREFIX', 'api'),
    'tables' => [
        'users' => [
            'model' => App\Models\User::class,
            'permissions' => [
                'view' => 'view_users',
                'create' => 'create_users',
                'update' => 'update_users',
                'delete' => 'delete_users',
            ],
            'relationships' => [
                'posts' => [
                    'type' => 'hasMany',
                    'model' => App\Models\Post::class,
                ],
            ],
        ],
        // Add more tables as needed
    ],
];
```

### Step 5: Authentication Setup

#### Option A: JWT Authentication
If using JWT, install the JWT package:
```bash
composer require tymon/jwt-auth
php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret
```

Configure your User model:
```php
use Tymon\JWTAuth\Contracts\JWTSubject;

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
If using Sanctum:
```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### Step 6: Middleware Configuration

Add the request ID middleware to your API routes in `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'api' => [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'throttle:api',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Sopheak\Core\Http\Middleware\RequestId::class, // Add this line
    ],
];
```

Or apply it to specific route groups:
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

### Step 8: Permissions Setup (Included)

The package includes Spatie Laravel Permission as a dependency. Set it up:

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

### Step 9: Test the Installation

Create a test route to verify everything is working:

```php
// routes/api.php
Route::middleware(['api', 'request.id'])->get('/test', function () {
    $response = app('api.response');
    return $response->success(['message' => 'SP Laravel API is working!']);
});
```

Test the endpoint:
```bash
curl -X GET http://your-app.test/api/test
```

Expected response:
```json
{
    "success": true,
    "data": {
        "message": "SP Laravel API is working!"
    },
    "meta": {
        "request_id": "req_1234567890abcdef"
    }
}
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

## Quick Start

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

### ApiResponseService
Provides standardized JSON responses:
```php
use Sopheak\Core\Services\ApiResponseService;

$response = app('api.response');
return $response->success($data, 'Operation successful');
return $response->error('Error message', 400);
```

### AuditLogService
Comprehensive audit logging for data changes:
```php
use Sopheak\Core\Services\AuditLogService;

$auditService = app(AuditLogService::class);
$auditService->handleAuditData($event, $table, $oldData, $newData);
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
    entityType: 'User',
    queryData: $userData
);
```

### CursorPagination
Efficient pagination for large datasets:
```php
use Sopheak\Core\Services\CursorPagination;

$pagination = app(CursorPagination::class);
$result = $pagination->paginate($query, $request);
```

### QueryCacheService
Intelligent query caching:
```php
use Sopheak\Core\Services\QueryCacheService;

$cache = app(QueryCacheService::class);
$result = $cache->remember($key, $query, $ttl);
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

### Generate OpenAPI Specification
```bash
php artisan sp-laravel-api:openapi
```

### Setup Package
```bash
php artisan sp-laravel-api:setup
```
Publishes default configurations for:
- `config/record.php` - Database table configurations and relationships
- `config/audit.php` - Audit logging settings  
- `config/jwt.php` - JWT authentication settings

### Record Cache Management
```bash
# Clear record cache
php artisan record:cacheClear

# Get record cache status
php artisan record:getCache

# Refresh record cache
php artisan record:refreshCache

# Clean old audit logs based on retention policy
php artisan sp-laravel-api:clean-audit-logs

# Clean audit logs with options
php artisan sp-laravel-api:clean-audit-logs --dry-run
php artisan sp-laravel-api:clean-audit-logs --force --days=30
php artisan sp-laravel-api:clean-audit-logs --batch-size=500
```

## API Endpoints

The package automatically registers RESTful API routes for dynamic database operations. The route prefix is configurable via `config('record.api_prefix')` (default: `api`).

### Route Configuration

```php
// config/record.php
'api_prefix' => env('RECORD_API_PREFIX', 'api'),
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
- `DELETE /{prefix}/{table}/{id}` - Soft delete record

### Advanced Operations
- `POST /{prefix}/{table}/{id}/restore` - Restore soft-deleted record
- `DELETE /{prefix}/{table}/{id}/force` - Permanently delete record
- `POST /{prefix}/{table}/bulk` - Bulk operations
- `POST /{prefix}/{table}/bulk/create` - Bulk create
- `POST /{prefix}/{table}/bulk/update` - Bulk update
- `POST /{prefix}/{table}/bulk/delete` - Bulk delete

### RPC Functions
- `POST /{prefix}/{functionName}` - Execute global functions
- `POST /{prefix}/{table}/rpc/{functionName}` - Execute table-specific functions

## Config
- Publish config: `php artisan vendor:publish --tag=sp-laravel-api-config`
- File: `config/sp-laravel-api.php`
  - `response.include_request_id` enables `meta.request_id` injection.
  - `openapi.output` sets default output filename.

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

- `applyCommonQueries($builder, $request, $isArray = false, $orderBy = 'id')` - Apply common query filters
- `applyRequestFilters($request, $isArray = false)` - Apply request-based filters

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

- `is.{value}` - Filter where column is null (e.g., `?name=is.null`)
- `eq.{value}` - Filter where column equals value (e.g., `?name=eq.John`)
- `neq.{value}` - Filter where column does not equal value (e.g., `?status=neq.inactive`)
- `like.{value}` - Filter using LIKE operator (e.g., `?name=like.John`)
- `gt.{value}` - Filter where column is greater than value (e.g., `?age=gt.18`)
- `lt.{value}` - Filter where column is less than value (e.g., `?price=lt.100`)
- `gte.{value}` - Filter where column is greater than or equal to value (e.g., `?score=gte.75`)
- `lte.{value}` - Filter where column is less than or equal to value (e.g., `?price=lte.100`)
- `in.{value}` - Filter where column is in a list of values (e.g., `?category=in.electronics,clothing`)
- `contains.{value}` - Filter where column contains a substring (e.g., `?name=contains.john`)
- `between.{value},{value}` - Filter where column is between values (e.g., `?date=between.2025-06-01,2025-06-20`)
- `not_between.{value},{value}` - Filter where column is not between values
- `compare.{field2}` - Compare two fields (e.g., `?total_amount=compare.neq.balance`)

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
- **Cursor Pagination**: [`docs/cursor-pagination.md`](docs/cursor-pagination.md) - Efficient pagination for large datasets
- **Package Overview**: [`docs/README.md`](docs/README.md) - Package features and quick reference

### Core Classes Reference
- **Request ID Middleware**: `Sopheak\Core\Http\Middleware\RequestId`
- **API Response Service**: `Sopheak\Core\Services\ApiResponseService`
- **Audit Log Service**: `Sopheak\Core\Services\AuditLogService`
- **Query Cache Service**: `Sopheak\Core\Services\QueryCacheService`
- **Cursor Pagination Service**: `Sopheak\Core\Services\CursorPagination`
- **Dynamic API Controller**: `Sopheak\Core\Http\Controllers\DynamicApiController`
- **Query Helpers Trait**: `Sopheak\Core\Traits\QueryHelpers`
- **Audit Query Interface**: `Sopheak\Core\Interfaces\AuditQueryInterface`

## Notes
- Keep `request.id` middleware active to ensure `meta.request_id` consistency.
- Extend the OpenAPI generator as needed for your endpoints.
