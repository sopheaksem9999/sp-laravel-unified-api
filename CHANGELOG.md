# Changelog

All notable changes to `sp-laravel-api` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Comprehensive setup validation command (`sp-laravel-api:validate`)
- Enhanced README with troubleshooting and advanced configuration
- Examples directory with sample configurations
- Basic test structure for dynamic API and SchemaRegistry
- Development tools configuration (PHPStan, PHP CS Fixer, GitHub Actions)
- Contributing guidelines and development setup documentation

### Changed
- Improved README structure with step-by-step setup guide
- Enhanced configuration examples with Type classes
- Updated package documentation with comprehensive troubleshooting

### Fixed
- SchemaRegistry database compatibility (already working for MySQL and SQLite)

## [2.0.0] - 2024-01-XX

### Added
- Dynamic API controller with CRUD operations
- Advanced query filtering and sorting capabilities
- Comprehensive audit logging system
- JWT authentication integration
- Request ID middleware for request tracking
- Cursor-based pagination for large datasets
- Schema registry for database introspection
- Query caching service for performance optimization
- OpenAPI specification generation
- Spatie Laravel Permission integration
- Multi-database support (MySQL, PostgreSQL, SQLite)
- Configurable API endpoints and permissions
- Background job processing for audit logs
- Cache management commands
- Relationship loading with depth control
- Tenant-aware functionality (optional)

### Changed
- **BREAKING**: Configuration format now uses Type classes instead of arrays
- **BREAKING**: Updated middleware registration approach
- **BREAKING**: Enhanced permission system integration
- Improved caching mechanisms with Redis support
- Enhanced error handling and API responses
- Updated service provider registration

### Security
- Added input validation for all API endpoints
- Implemented proper authorization checks
- SQL injection prevention through parameterized queries
- XSS protection for API responses

## [1.x] - Legacy Version

### Features
- Basic API functionality
- Simple CRUD operations
- Basic authentication
- Limited query capabilities

---

## Migration Guides

### From 1.x to 2.x

#### Configuration Changes

**Old format (1.x):**
```php
// config/record.php
return [
    'tables' => [
        'users' => [
            'model' => App\Models\User::class,
            'permissions' => [
                'view' => 'view_users',
                'create' => 'create_users',
            ],
        ],
    ],
];
```

**New format (2.x):**
```php
// config/record.php
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordSpatiePermissionType;

return [
    'tables' => [
        'users' => new RecordTableType(
            model: \App\Models\User::class,
            permissions: new RecordSpatiePermissionType(
                view: 'view_users',
                create: 'create_users',
                update: 'update_users',
                delete: 'delete_users'
            ),
            relationships: [],
            soft_deletes: false,
            has_tenant_id: false
        ),
    ],
];
```

#### Middleware Registration

**Old approach:**
```php
// Manual middleware registration required
```

**New approach:**
```php
// Automatic registration through service provider
// Optional manual registration for specific routes
Route::middleware(['api', 'auth:api', 'request.id'])->group(function () {
    // Your API routes
});
```

#### Command Changes

**New commands in 2.x:**
```bash
# Setup and validation
php artisan sp-laravel-api:setup
php artisan sp-laravel-api:validate

# Cache management
php artisan sp-laravel-api:clear-cache
php artisan sp-laravel-api:clear-cache users

# OpenAPI generation
php artisan sp-laravel-api:generate-openapi
```

### Breaking Changes Summary

1. **Configuration Format**: Must update to use Type classes
2. **Permission System**: Enhanced integration with Spatie Laravel Permission
3. **Middleware**: Updated registration approach
4. **Caching**: New caching mechanisms and commands
5. **Dependencies**: Updated minimum requirements (PHP 8.1+, Laravel 10+)

### Upgrade Steps

1. **Update Composer**
   ```bash
   composer require sopheak/sp-laravel-api:^2.0
   ```

2. **Republish Configuration**
   ```bash
   php artisan vendor:publish --provider="Sopheak\Core\CoreServiceProvider" --force
   ```

3. **Update Configuration Format**
   - Convert array-based configuration to Type classes
   - See examples in `examples/config/record.php`

4. **Run Migrations**
   ```bash
   php artisan migrate
   ```

5. **Validate Setup**
   ```bash
   php artisan sp-laravel-api:validate --fix
   ```

6. **Update Middleware Registration** (if manually registered)
   - Update middleware groups in `app/Http/Kernel.php`

7. **Test Your Application**
   - Run comprehensive tests
   - Verify API endpoints functionality
   - Check authentication and permissions

---

## Support

For questions about upgrading or changes:

- [GitHub Issues](https://github.com/your-username/sp-laravel-api/issues)
- [GitHub Discussions](https://github.com/your-username/sp-laravel-api/discussions)
- [Documentation](https://sp-laravel-api.readthedocs.io)