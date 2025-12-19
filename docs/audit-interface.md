# Audit Interface Documentation

The `sp-laravel-api` package provides a comprehensive audit interface that allows controllers to implement custom audit queries and logging functionality.

## Overview

The audit interface consists of:
- `AuditQueryInterface` - Interface for controllers to implement custom audit queries
- `HasAuditQuery` trait - Provides common audit functionality for controllers
- `AuditLogController` - Dedicated controller for audit operations
- Audit routes - API endpoints for audit functionality

## AuditQueryInterface

Controllers can implement this interface to provide custom audit queries:

```php
<?php

namespace Sopheak\Core\Interfaces;

use Illuminate\Database\Eloquent\Builder;

interface AuditQueryInterface
{
    /**
     * Get the custom audit query for this controller
     * This method should return a query builder with all necessary joins,
     * selects, and relationships for audit logging
     */
    public function getAuditQuery(): Builder;

    /**
     * Get the entity name for audit logging
     * This should return a human-readable name for the entity
     */
    public function getAuditEntityName(): string;

    /**
     * Get the entity class for audit logging
     * This should return the fully qualified class name of the model
     */
    public function getAuditEntityClass(): string;
}
```

## HasAuditQuery Trait

The `HasAuditQuery` trait provides common audit functionality that can be used in controllers:

```php
use Sopheak\Core\Traits\HasAuditQuery;

class InvoiceController extends Controller
{
    use HasAuditQuery;
    
    // Your controller methods...
}
```

### Available Methods

#### `logAuditWithCustomQuery($recordId, $event, $oldData = null, $newData = null)`
Logs an audit event using the custom audit query if the controller implements `AuditQueryInterface`.

#### `getAuditLogsForRecord($recordId, $perPage = 15)`
Retrieves audit logs for a specific record with pagination.

#### `auditLogs(Request $request)`
API endpoint to get audit logs for the controller's entity.

#### `auditStats(Request $request)`
API endpoint to get audit statistics for the controller's entity.

#### `auditFieldTimeline(Request $request)`
API endpoint to get timeline data for a specific field.

#### `auditFieldStats(Request $request)`
API endpoint to get statistics for a specific field.

## Example Implementation

Here's how to implement the audit interface in your controller, based on the `InvoiceController` example:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Sopheak\Core\Interfaces\AuditQueryInterface;
use Sopheak\Core\Traits\HasAuditQuery;
use Sopheak\Core\Services\AuditLogService;
use App\Models\Invoice;

class InvoiceController extends Controller implements AuditQueryInterface
{
    use HasAuditQuery;

    public function getAuditQuery(): Builder
    {
        return Invoice::select([
            'invoices.id',
            'invoices.invoice_number',
            'invoices.invoice_date',
            'invoices.due_date',
            'invoices.subtotal',
            'invoices.total_tax',
            'invoices.total_amount',
            'invoices.balance_due',
            'invoices.status',
            'invoices.notes',
            'invoices.terms_id',
            'invoices.location_id',
            'invoices.class_id',
            'invoices.customer_id',
            'invoices.bank_id',
            'invoices.created_at',
            'invoices.updated_at',
            'customers.display_name as customer_name',
            'customer_attended.display_name as customer_attended_name',
            'banks.account_name as bank_name',
            'classes.name as class_name',
            'locations.name as location_name',
            'terms.name as terms_name'
        ])
        ->leftJoin('customers', 'invoices.customer_id', '=', 'customers.id')
        ->leftJoin('customers as customer_attended', 'invoices.customer_attended_id', '=', 'customer_attended.id')
        ->leftJoin('banks', 'invoices.bank_id', '=', 'banks.id')
        ->leftJoin('classes', 'invoices.class_id', '=', 'classes.id')
        ->leftJoin('locations', 'invoices.location_id', '=', 'locations.id')
        ->leftJoin('terms', 'invoices.terms_id', '=', 'terms.id')
        ->with(['items', 'relationship']);
    }

    public function getAuditEntityName(): string
    {
        return 'Invoice';
    }

    public function getAuditEntityClass(): string
    {
        return Invoice::class;
    }

    public function store(Request $request)
    {
        // Validate and create invoice
        $invoice = Invoice::create($request->validated());
        
        // Log audit with custom query
        $this->logAuditWithCustomQuery(
            $invoice->id,
            'created',
            null,
            $invoice->toArray()
        );
        
        return response()->json($invoice);
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);
        $oldData = $invoice->toArray();
        
        $invoice->update($request->validated());
        
        // Log audit with custom query
        $this->logAuditWithCustomQuery(
            $invoice->id,
            'updated',
            $oldData,
            $invoice->fresh()->toArray()
        );
        
        return response()->json($invoice);
    }

    // Add audit endpoints to your routes
    public function getAuditLogs(Request $request)
    {
        return $this->auditLogs($request);
    }

    public function getAuditStats(Request $request)
    {
        return $this->auditStats($request);
    }
}
```

## API Endpoints

The package provides the following audit API endpoints under the main API prefix (configurable via `config('record.api_prefix', 'api')`):

### Global Audit Endpoints

- `GET /{api_prefix}/audit/logs` - Get audit logs with filtering
- `GET /{api_prefix}/audit/stats` - Get audit statistics
- `GET /{api_prefix}/audit/field-timeline` - Get field timeline data
- `GET /{api_prefix}/audit/field-stats` - Get field statistics
- `POST /{api_prefix}/audit/logs` - Manually create audit log
- `GET /{api_prefix}/audit/logs/{id}` - Get specific audit log
- `DELETE /{api_prefix}/audit/cleanup` - Clean up old audit logs (admin only)

**Note**: The `{api_prefix}` defaults to `api` but can be customized to `api/v1`, `api/v2`, etc. via the `record.api_prefix` configuration.

### Controller-Specific Endpoints

If you use the `HasAuditQuery` trait, you can add these routes to your controller:

```php
// In your routes file
Route::get('/invoices/audit/logs', [InvoiceController::class, 'getAuditLogs']);
Route::get('/invoices/audit/stats', [InvoiceController::class, 'getAuditStats']);
Route::get('/invoices/audit/field-timeline', [InvoiceController::class, 'auditFieldTimeline']);
Route::get('/invoices/audit/field-stats', [InvoiceController::class, 'auditFieldStats']);
```

## Query Parameters

### Audit Logs (`/audit/logs`)
- `entity_type` - Filter by entity type (e.g., 'Invoice')
- `entity_id` - Filter by specific entity ID
- `event` - Filter by event type (created, updated, deleted, etc.)
- `user_id` - Filter by user who performed the action
- `date_from` - Filter from date (Y-m-d format)
- `date_to` - Filter to date (Y-m-d format)
- `per_page` - Number of results per page (default: 15)
- `page` - Page number

### Audit Statistics (`/audit/stats`)
- `entity_type` - Filter by entity type
- `entity_id` - Filter by specific entity ID
- `date_from` - Filter from date
- `date_to` - Filter to date
- `group_by` - Group by: 'event', 'user', 'date', 'entity_type'

### Field Timeline (`/audit/field-timeline`)
- `entity_type` - Required: Entity type
- `entity_id` - Required: Entity ID
- `field` - Required: Field name to track
- `date_from` - Filter from date
- `date_to` - Filter to date

### Field Statistics (`/audit/field-stats`)
- `entity_type` - Required: Entity type
- `field` - Required: Field name
- `date_from` - Filter from date
- `date_to` - Filter to date

## Benefits of Custom Audit Queries

1. **Rich Data**: Include related data (joins) in audit logs
2. **Performance**: Optimized queries with necessary relationships
3. **Consistency**: Standardized audit data format across entities
4. **Flexibility**: Each controller can define its own audit requirements
5. **Maintainability**: Centralized audit logic with custom data handling

## Configuration

The audit functionality uses the following configuration files:

- `config/audit.php` - Audit logging settings
- `config/sp-laravel-api.php` - Package configuration

You can publish these configurations using:

```bash
php artisan vendor:publish --tag=sp-laravel-api-config
```

## Security

- All audit endpoints require authentication (`auth:api` middleware)
- Admin-only operations require `can:manage-audit-logs` permission
- Audit logs are immutable once created
- Sensitive data can be excluded via configuration

## Performance Considerations

- Use database indexes on frequently queried audit fields
- Consider archiving old audit logs
- Use the cleanup command to manage audit log retention
- Custom queries should be optimized for performance

## Console Commands

Clean up old audit logs:

```bash
# Basic cleanup (uses config retention_days)
php artisan sp-laravel-api:clean-audit-logs

# Dry run to see what would be deleted
php artisan sp-laravel-api:clean-audit-logs --dry-run

# Force cleanup without confirmation
php artisan sp-laravel-api:clean-audit-logs --force

# Override retention period
php artisan sp-laravel-api:clean-audit-logs --days=30
```