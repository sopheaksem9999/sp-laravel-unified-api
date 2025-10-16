# App Core (Laravel Package)

Core utilities for Laravel apps: standardized API responses, request ID middleware, and simple CLI helpers. Designed to drop into any Laravel app via path-based Composer install.

## Features
- **Standardized API Responses**: Consistent JSON response format across your application
- **Request ID Middleware**: Automatic request tracking for debugging and monitoring
- **Dynamic API Controller**: Full CRUD operations for any database table with advanced filtering
- **QueryHelpers Trait**: Powerful trait for advanced query filtering and manipulation
- **Audit Logging**: Comprehensive audit trail for all data changes
- **AuditLogJob**: Queue-based audit logging for improved performance
- **Audit Log Cleanup**: CLI command for cleaning old audit logs based on retention policy
- **Query Caching**: Intelligent caching system for improved performance
- **OpenAPI Spec Generation**: CLI command to generate API documentation
- **Configuration Publishing**: Easy setup with sensible defaults

## Installation
1. Add this package as a path repo in root `composer.json` (already handled if you are in this repo):
   - `repositories` entry pointing to `packages/sp-laravel-api`.
   - Require `sopheak/sp-laravel-api: *`.
2. Run `composer update sopheak/sp-laravel-api -W`.
3. The service provider auto-discovers; no manual registration needed.

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
- API records: `docs/api-v2-records.md`
- Request ID middleware: `Sopheak\\Core\\Http\\Middleware\\RequestId`
- Response service: `Sopheak\\Core\\Services\\ApiResponseService`
- OpenAPI CLI: `Sopheak\\Core\\Console\\GenerateOpenApiSpec`
- Vue module architecture: `docs/vue-module-architecture.md`
- Cursor pagination: `docs/cursor-pagination.md`
- Currency formatting: `docs/currency-formatting.md`, `docs/currency-formatting-examples.md`
- Composables overview: `docs/composables-README.md`
- Composables forms: `docs/composables-useFormService.md`
- Composables form dialog: `docs/composables-useFormDialogService.md`
- Composables page view: `docs/composables-usePageViewService.md`, `docs/composables-usePageViewDialogService.md`
- NVM setup: `docs/nvm-setup.md`
- Legacy app-core notes: `docs/app-core.md`

## Notes
- Keep `request.id` middleware active to ensure `meta.request_id` consistency.
- Extend the OpenAPI generator as needed for your endpoints.
