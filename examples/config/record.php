<?php

/**
 * Example Record Configuration for SP Laravel API
 * 
 * This file demonstrates how to configure your database tables
 * for the dynamic API functionality.
 * 
 * Copy this to your Laravel app's config/record.php and modify
 * according to your database schema and requirements.
 */

use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordSpatiePermissionType;
use Sopheak\Core\Types\RecordTablePublic;

return [
    /*
    |--------------------------------------------------------------------------
    | API Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix for all dynamic API routes. This will be used to generate
    | routes like: /{api_prefix}/{table_name}
    |
    | Examples:
    | - 'api' → /api/users, /api/posts
    | - 'api/v1' → /api/v1/users, /api/v1/posts
    | - 'records' → /records/users, /records/posts
    |
    */
    'api_prefix' => env('RECORD_API_PREFIX', 'api'),

    /*
    |--------------------------------------------------------------------------
    | Global Settings
    |--------------------------------------------------------------------------
    */
    'max_depth' => env('RECORD_MAX_DEPTH', 3),
    'cache_ttl' => env('RECORD_CACHE_TTL', 3600),
    'lazy_cache_ttl' => env('RECORD_LAZY_CACHE_TTL', 300),
    'enable_tenant_id' => env('RECORD_ENABLE_TENANT_ID', false),

    /*
    |--------------------------------------------------------------------------
    | Database Tables Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your database tables here. Each table can have:
    | - model: The Eloquent model class
    | - table: The actual database table name (if different from key)
    | - permissions: Required permissions for CRUD operations
    | - relationships: Define relationships with other tables
    | - soft_deletes: Whether the table uses soft deletes
    | - has_tenant_id: Whether the table has tenant_id column
    |
    */
    'tables' => [
        /*
        |--------------------------------------------------------------------------
        | Users Table Example
        |--------------------------------------------------------------------------
        */
        'users' => new RecordTableType(
            pms_name: 'user', // The PMS name for permissions (e.g., view_user, create_user)
            table: 'users', // Optional: specify if different from key
            public: new RecordTablePublic(
                read: false, // Requires authentication for read operations
                write: false // Requires authentication for write operations
            ),
            relationships: [
                // One-to-Many: User has many posts
                'posts' => new RecordHasManyType(
                    table: 'posts',
                    foreignKey: 'user_id',
                    localKey: 'id'
                ),
                
                // One-to-Many: User has many orders
                'orders' => new RecordHasManyType(
                    table: 'orders',
                    foreignKey: 'user_id',
                    localKey: 'id'
                ),
                
                // Many-to-Many: User belongs to many roles (using Spatie Permission)
                'roles' => new RecordMetaBelongsToManyType(
                    related: 'roles',
                    table: 'model_has_roles',
                    foreignPivotKey: 'model_id',
                    relatedPivotKey: 'role_id',
                    parentKey: 'id',
                    relatedKey: 'id'
                ),
            ],
            soft_deletes: false,
            has_tenant_id: false
        ),

        /*
        |--------------------------------------------------------------------------
        | Posts Table Example
        |--------------------------------------------------------------------------
        */
        'posts' => new RecordTableType(
            pms_name: 'post', // The PMS name for permissions (e.g., view_post, create_post)
            public: new RecordTablePublic(
                read: false, // Requires authentication for read operations
                write: false // Requires authentication for write operations
            ),
            relationships: [
                // Belongs To: Post belongs to user
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id'
                ),
                
                // One-to-Many: Post has many comments
                'comments' => new RecordHasManyType(
                    table: 'comments',
                    foreignKey: 'post_id',
                    localKey: 'id'
                ),
                
                // Many-to-Many: Post belongs to many categories
                'categories' => new RecordMetaBelongsToManyType(
                    related: 'categories',
                    table: 'post_categories',
                    foreignPivotKey: 'post_id',
                    relatedPivotKey: 'category_id',
                    parentKey: 'id',
                    relatedKey: 'id'
                ),
            ],
            soft_deletes: true,
            has_tenant_id: false
        ),

        /*
        |--------------------------------------------------------------------------
        | Comments Table Example
        |--------------------------------------------------------------------------
        */
        'comments' => new RecordTableType(
            pms_name: 'comment', // The PMS name for permissions (e.g., view_comment, create_comment)
            public: new RecordTablePublic(
                read: false, // Requires authentication for read operations
                write: false // Requires authentication for write operations
            ),
            relationships: [
                // Belongs To: Comment belongs to post
                'post' => new RecordBelongsToType(
                    table: 'posts',
                    foreignKey: 'post_id',
                    ownerKey: 'id'
                ),
                
                // Belongs To: Comment belongs to user
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id'
                ),
            ],
            soft_deletes: true,
            has_tenant_id: false
        ),

        /*
        |--------------------------------------------------------------------------
        | Orders Table Example (with Tenant Support)
        |--------------------------------------------------------------------------
        */
        'orders' => new RecordTableType(
            pms_name: 'order', // The PMS name for permissions (e.g., view_order, create_order)
            public: new RecordTablePublic(
                read: false, // Requires authentication for read operations
                write: false // Requires authentication for write operations
            ),
            relationships: [
                // Belongs To: Order belongs to user
                'user' => new RecordBelongsToType(
                    table: 'users',
                    foreignKey: 'user_id',
                    ownerKey: 'id'
                ),
                
                // One-to-Many: Order has many order items
                'items' => new RecordHasManyType(
                    table: 'order_items',
                    foreignKey: 'order_id',
                    localKey: 'id'
                ),
                
                // Has Many Through: Order has many products through order items
                'products' => new RecordHasManyThroughType(
                    table: 'products',
                    through: 'order_items',
                    firstKey: 'order_id',
                    secondKey: 'id',
                    localKey: 'id',
                    secondLocalKey: 'product_id'
                ),
            ],
            soft_deletes: true,
            has_tenant_id: true // This table supports multi-tenancy
        ),

        /*
        |--------------------------------------------------------------------------
        | Products Table Example
        |--------------------------------------------------------------------------
        */
        'products' => new RecordTableType(
            pms_name: 'product', // The PMS name for permissions (e.g., view_product, create_product)
            public: new RecordTablePublic(
                read: false, // Requires authentication for read operations
                write: false // Requires authentication for write operations
            ),
            relationships: [
                // One-to-Many: Product has many order items
                'order_items' => new RecordHasManyType(
                    table: 'order_items',
                    foreignKey: 'product_id',
                    localKey: 'id'
                ),
                
                // Belongs To: Product belongs to category
                'category' => new RecordBelongsToType(
                    table: 'categories',
                    foreignKey: 'category_id',
                    ownerKey: 'id'
                ),
            ],
            soft_deletes: true,
            has_tenant_id: true
        ),

        /*
        |--------------------------------------------------------------------------
        | Categories Table Example
        |--------------------------------------------------------------------------
        */
        'categories' => new RecordTableType(
            pms_name: 'category', // The PMS name for permissions (e.g., view_category, create_category)
            public: new RecordTablePublic(
                read: false, // Requires authentication for read operations
                write: false // Requires authentication for write operations
            ),
            relationships: [
                // One-to-Many: Category has many products
                'products' => new RecordHasManyType(
                    table: 'products',
                    foreignKey: 'category_id',
                    localKey: 'id'
                ),
                
                // Self-referencing: Category has many subcategories
                'subcategories' => new RecordHasManyType(
                    table: 'categories',
                    foreignKey: 'parent_id',
                    localKey: 'id'
                ),
                
                // Self-referencing: Category belongs to parent category
                'parent' => new RecordBelongsToType(
                    table: 'categories',
                    foreignKey: 'parent_id',
                    ownerKey: 'id'
                ),
            ],
            soft_deletes: false,
            has_tenant_id: false
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Functions
    |--------------------------------------------------------------------------
    |
    | Define global RPC functions that can be called via POST /{api_prefix}/{function_name}
    |
    */
    'functions' => [
        // Example: POST /api/search-all
        // 'search-all' => new RecordFunctionType(
        //     handler: \App\Services\GlobalSearchService::class,
        //     method: 'searchAll',
        //     permissions: ['search_all']
        // ),
    ],
];