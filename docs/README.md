# SP Laravel API Package

A comprehensive Laravel package that provides dynamic CRUD operations, audit logging, cursor-based pagination, and advanced query capabilities for building robust APIs.

## Features

- 🚀 **Dynamic CRUD Operations** - Automatic REST API endpoints for any database table
- 📊 **Audit Logging** - Comprehensive audit trail with custom query support
- ⚡ **Cursor-Based Pagination** - High-performance pagination for large datasets
- 🔍 **Advanced Query Helpers** - Powerful filtering, sorting, and relationship loading
- 🛡️ **Security & Authorization** - JWT authentication with permission-based access control
- 🎯 **Custom Functions** - Support for global and table-specific RPC functions
- 🏢 **Multi-Tenancy** - Built-in tenant isolation support
- 💾 **Query Caching** - Intelligent caching for improved performance
- 🔧 **Configurable** - Highly customizable via configuration files

## Installation

1. Install the package via Composer:

```bash
composer require sopheak/sp-laravel-api
```

2. Run the setup command to publish configurations and create default files:

```bash
php artisan sp-laravel-api:setup
```

3. Configure your environment variables:

```env
# JWT Configuration
JWT_SECRET=your-jwt-secret-key
JWT_TTL=60

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

4. Run migrations if needed and configure your tables in `config/record.php`.

## Quick Start

### Basic API Usage

Once configured, your tables automatically get REST API endpoints:

```http
# List records with filtering and pagination
GET /api/users?per_page=25&sortby=created_at&order=desc

# Get a specific record
GET /api/users/123

# Create a new record
POST /api/users
Content-Type: application/json
{
  "name": "John Doe",
  "email": "john@example.com"
}

# Update a record
PUT /api/users/123
Content-Type: application/json
{
  "name": "John Smith"
}

# Delete a record
DELETE /api/users/123
```

### Table Configuration

Configure your tables in `config/record.php`:

```php
'tables' => [
    'users' => [
        'pms_name' => 'user',
        'soft_deletes' => true,
        'relationships' => [
            'profile' => new RecordBelongsToType(
                table: 'profiles',
                foreignKey: 'user_id',
                ownerKey: 'id'
            ),
            'posts' => new RecordHasManyType(
                table: 'posts',
                foreignKey: 'user_id',
                localKey: 'id'
            ),
        ],
        'functions' => [
            'get_stats' => 'App\\Services\\UserService@getStats',
        ],
    ],
],
```

## API Endpoints

### Standard CRUD Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/{table}` | List records with filtering, sorting, pagination |
| GET | `/{table}/{id}` | Get a specific record |
| POST | `/{table}` | Create a new record |
| PUT/PATCH | `/{table}/{id}` | Update a record |
| DELETE | `/{table}/{id}` | Delete a record (soft delete if enabled) |
| POST | `/{table}/{id}/restore` | Restore a soft-deleted record |
| DELETE | `/{table}/{id}/force` | Force delete (permanent) |

### Bulk Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/{table}/bulk` | Legacy bulk operations with auto-detection |
| POST | `/{table}/bulk/create` | Bulk create multiple records |
| POST | `/{table}/bulk/update` | Bulk update multiple records |
| POST | `/{table}/bulk/delete` | Bulk delete multiple records |

### Audit Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/audit/logs` | Get audit logs with filtering |
| GET | `/audit/stats` | Get audit statistics |
| GET | `/audit/field-timeline` | Get field change timeline |
| GET | `/audit/field-stats` | Get field statistics |
| POST | `/audit/logs` | Manually create audit log |
| GET | `/audit/logs/{id}` | Get specific audit log |
| DELETE | `/audit/cleanup` | Clean up old audit logs (admin only) |

### Custom Functions

| Method | Endpoint | Description |
|--------|----------|-------------|
| ANY | `/{functionName}` | Execute global custom function |
| ANY | `/{table}/rpc/{functionName}` | Execute table-specific function |

## Query Parameters

### Filtering & Search
- `search` - Full-text search across searchable fields
- `{field}` - Filter by specific field value
- `{field}_like` - LIKE search on field
- `{field}_in` - IN clause for multiple values
- `{field}_between` - BETWEEN clause for ranges

### Selection & Relationships
- `select` - Comma-separated list of fields to return
- `with` - Load relationships (e.g., `with=profile,posts`)
- `withCount` - Count relationships (e.g., `withCount=posts`)

### Sorting & Pagination
- `sortby` - Field to sort by
- `order` - Sort direction (`asc` or `desc`)
- `per_page` - Number of items per page
- `page` - Page number (offset pagination)
- `cursor` - Cursor value (cursor pagination)
- `direction` - Cursor direction (`next` or `prev`)

### Advanced Options
- `trashed` - Include soft-deleted records (`with`, `only`)
- `distinct` - Remove duplicate results
- `limit` - Limit number of results

## Authentication & Authorization

The package uses JWT authentication with permission-based access control:

### Required Permissions

| Operation | Permission Pattern |
|-----------|-------------------|
| List/View | `view_{resource}` |
| Create | `create_{resource}` |
| Update | `update_{resource}` |
| Delete | `delete_{resource}` |
| Restore | `update_{resource}` |
| Force Delete | `delete_{resource}` |

### Special Permissions
- `viewOnlyCreateBy_{resource}` - Only see records created by the user
- `updateStatus_{resource}` - Update status fields only

## Console Commands

### Setup & Configuration
```bash
# Initial package setup
php artisan sp-laravel-api:setup

# Setup with force overwrite
php artisan sp-laravel-api:setup --force
```

### Cache Management
```bash
# Refresh record table cache
php artisan sp-laravel-api:record-refresh-cache

# Clear record cache
php artisan sp-laravel-api:clear-record-cache

# Get cache information
php artisan sp-laravel-api:get-record-cache
```

### Audit Management
```bash
# Clean up old audit logs
php artisan sp-laravel-api:clean-audit-logs

# Dry run cleanup
php artisan sp-laravel-api:clean-audit-logs --dry-run

# Force cleanup without confirmation
php artisan sp-laravel-api:clean-audit-logs --force

# Custom retention period
php artisan sp-laravel-api:clean-audit-logs --days=30
```

### OpenAPI Documentation
```bash
# Generate OpenAPI specification
php artisan sp-laravel-api:openapi
```

## Configuration Files

### `config/record.php`
Main configuration for API endpoints, table settings, relationships, and functions.

### `config/audit.php`
Audit logging configuration including retention, queue settings, and security options.

### `config/cursor_pagination.php`
Cursor-based pagination settings including auto-detection thresholds and performance options.

### `config/jwt.php`
JWT authentication configuration for token management and security.

### `config/sp-laravel-api.php`
Package-specific settings for API responses and OpenAPI generation.

## Advanced Features

### Custom Relationship Types
- `RecordBelongsToType` - Belongs to relationships
- `RecordHasManyType` - Has many relationships  
- `RecordHasManyThroughType` - Has many through relationships
- `RecordSpatiePermissionType` - Spatie permission relationships
- `RecordMetaBelongsToManyType` - Meta-based many-to-many relationships

### Custom Functions
Define custom business logic as global or table-specific functions:

```php
// Global function
'global_functions' => [
    'system_stats' => 'App\\Services\\SystemService@getStats',
],

// Table-specific function
'functions' => [
    'calculate_total' => new RecordFunctionType(
        type: 'class',
        class: 'App\\Services\\InvoiceService',
        function_method: 'calculateTotal',
        method: ['POST'],
        description: 'Calculate invoice total'
    ),
],
```

### Multi-Tenancy
Automatic tenant isolation when `tenant_id` column is present:

```php
// Automatically scoped to current tenant
GET /api/invoices  // Only returns invoices for current tenant
```

### Soft Deletes
Automatic soft delete support when configured:

```php
'soft_deletes' => true,  // Enable soft delete support
```

## Performance Optimization

### Query Caching
- Automatic caching of foreign key metadata
- Configurable TTL for different cache types
- Lazy loading optimization

### Cursor Pagination
- Automatic detection for large tables
- Consistent performance regardless of dataset size
- Real-time friendly for live data

### Bulk Operations
- Efficient bulk create, update, and delete operations
- Configurable batch sizes
- Transaction support

## Security Features

- JWT token authentication
- Permission-based authorization
- Rate limiting with different throttles
- SQL injection prevention
- Input validation and sanitization
- Audit trail for all operations

## Documentation

- [API Documentation](api-documentation.md) - Comprehensive API reference
- [Audit Interface](audit-interface.md) - Audit logging implementation guide
- [Cursor Pagination](cursor-pagination.md) - Cursor-based pagination details

## Requirements

- PHP 8.1+
- Laravel 10.0+
- MySQL 5.7+ / PostgreSQL 12+

## License

This package is proprietary software. All rights reserved.

## Support

For support and questions, please contact the development team.