# SP Laravel Unified API

A comprehensive Laravel package that provides standardized API responses, dynamic API controllers, query helpers, audit logging, optional permission integration, flexible authentication via Laravel guards, and OpenAPI specification generation for ERP SaaS applications.

## 🚀 Why use this?
SP Laravel API replaces the traditional Laravel MVC pattern with a **Config-Driven Pattern**. You simply define a Schema in `config/records/tables/*.php`, and the package dynamically generates:
- Full CRUD API endpoints with advanced filtering & pagination
- Atomic Upsert operations
- OpenAPI 3.0 Documentation
- Detailed Audit Logging

## 📥 Quick Start

```bash
# 1. Install the package
composer require sopheak/sp-laravel-api

# 2. Generate/publish configs (record/audit/sp-laravel-api)
php artisan sp-laravel-api:setup

# 3. Publish package migrations + run migrations
php artisan vendor:publish --tag=sp-laravel-api-migrations
php artisan migrate
```

## ⚙️ Configuration Example

Define a table in `config/records/tables/users.php`:

```php
use Sopheak\Core\Types\RecordTableType;

return new RecordTableType(
    pmsName: 'user',
    table: 'users',
    isAuthRead: true,
    isAuthWrite: true,
    relationships: [
        'posts' => new \Sopheak\Core\Types\RecordHasManyType(
            table: 'posts',
            foreignKey: 'user_id',
            localKey: 'id',
        ),
    ],
);
```

## 📚 Full Documentation

For detailed installation instructions, architecture overview, API documentation, and feature guides, please visit our dedicated documentation site:

**[Read the Full Documentation](https://github.com/sopheaksem9999/sp-laravel-api-docs)** 

*(Note: Replace the link above with the live Vercel URL once deployed).*

## 📄 License

This package is proprietary software.
