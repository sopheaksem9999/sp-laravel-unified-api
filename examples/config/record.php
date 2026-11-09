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
use Sopheak\Core\Enums\RecordFunctionMethodEnum;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordComputedAttributeType;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordSchemaType;
use Sopheak\Core\Types\RecordTablePermissionType;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;

$tables = [
    /*
    |--------------------------------------------------------------------------
    | Users Table Example
    |--------------------------------------------------------------------------
    */
    'users' => new RecordTableType(
        pmsName: 'user',
        table: 'users',
        hasTenantId: false,
        softDeletes: false,
        isAuthRead: true,
        isAuthWrite: true,
        searchable: ['name', 'email', 'posts.title', 'roles.name'],
        relationships: [
            'posts' => new RecordHasManyType(
                table: 'posts',
                foreignKey: 'user_id',
                localKey: 'id'
            ),
            'orders' => new RecordHasManyType(
                table: 'orders',
                foreignKey: 'user_id',
                localKey: 'id'
            ),
            'roles' => new RecordMetaBelongsToManyType(
                related: 'roles',
                table: 'model_has_roles',
                foreignPivotKey: 'model_id',
                relatedPivotKey: 'role_id',
                parentKey: 'id',
                relatedKey: 'id'
            ),
        ],
        functions: [
            'getFullName' => new RecordFunctionType(
                httpMethod: [RecordFunctionMethodEnum::GET->value],
                class: 'App\\Services\\UserService',
                functionName: 'getFullName',
                pmsName: 'view_user',
                description: 'Get the full name of a user by ID',
                querySchema: RecordSchemaType::object(
                    properties: [
                        'id' => RecordSchemaType::integer(description: 'User ID', example: 123),
                    ],
                    required: ['id'],
                ),
                responseSchema: RecordSchemaType::object(
                    properties: [
                        'data' => RecordSchemaType::object(
                            properties: [
                                'full_name' => RecordSchemaType::string(example: 'John Doe'),
                            ],
                            required: ['full_name'],
                        ),
                    ],
                    required: ['data'],
                ),
            ),
        ],
        createValidator: new RecordValidationType(
            class: 'App\\Record\\Validators\\UserValidators',
            functionName: 'createRules',
        ),
        updateValidator: new RecordValidationType(
            class: 'App\\Record\\Validators\\UserValidators',
            functionName: 'updateRules',
        ),
        deleteValidator: new RecordValidationType(
            class: 'App\\Record\\Validators\\UserValidators',
            functionName: 'deleteRules',
        ),
        beforeRead: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'beforeRead'
        ),
        afterRead: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'afterRead'
        ),
        beforeCreate: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'beforeCreate'
        ),
        afterCreate: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'afterCreate'
        ),
        beforeUpdate: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'beforeUpdate'
        ),
        afterUpdate: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'afterUpdate'
        ),
        beforeDelete: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'beforeDelete'
        ),
        afterDelete: new RecordTableTriggerType(
            class: 'App\\Record\\Triggers\\UserTriggers',
            functionName: 'afterDelete'
        ),
        attributes: [
            'display_label' => new RecordComputedAttributeType(
                class: 'App\\Record\\Attributes\\UserAttributes',
                functionName: 'getDisplayLabel',
                type: 'string',
                description: 'Formatted user display label',
            ),
        ],
        permissions: new RecordTablePermissionType(
            read: 'view:user',
            create: 'create:user',
            update: 'update:user',
            delete: 'delete:user',
        ),
    ),

    /*
    |--------------------------------------------------------------------------
    | Posts Table Example
    |--------------------------------------------------------------------------
    */
    'posts' => new RecordTableType(
        pmsName: 'post',
        hasTenantId: false,
        softDeletes: true, // The PMS name for permissions (e.g., view_post, create_post)
        isAuthRead: true,
        isAuthWrite: true,
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
        ]
    ),

    /*
    |--------------------------------------------------------------------------
    | Comments Table Example
    |--------------------------------------------------------------------------
    */
    'comments' => new RecordTableType(
        pmsName: 'comment',
        hasTenantId: false,
        softDeletes: true, // The PMS name for permissions (e.g., view_comment, create_comment)
        isAuthRead: true,
        isAuthWrite: true,
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
        ]
    ),

    /*
    |--------------------------------------------------------------------------
    | Orders Table Example (with Tenant Support)
    |--------------------------------------------------------------------------
    */
    'orders' => new RecordTableType(
        pmsName: 'order',
        hasTenantId: true,
        softDeletes: true, // The PMS name for permissions (e.g., view_order, create_order)
        isAuthRead: true,
        isAuthWrite: true,
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
        ] // This table supports multi-tenancy
    ),

    /*
    |--------------------------------------------------------------------------
    | Products Table Example
    |--------------------------------------------------------------------------
    */
    'products' => new RecordTableType(
        pmsName: 'product',
        hasTenantId: true,
        softDeletes: true, // The PMS name for permissions (e.g., view_product, create_product)
        isAuthRead: true,
        isAuthWrite: true,
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
        ]
    ),

    /*
    |--------------------------------------------------------------------------
    | Categories Table Example
    |--------------------------------------------------------------------------
    */
    'categories' => new RecordTableType(
        pmsName: 'category',
        hasTenantId: false,
        softDeletes: false, // The PMS name for permissions (e.g., view_category, create_category)
        isAuthRead: true,
        isAuthWrite: true,
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
        ]
    ),
];

$tablesDirectory = __DIR__ . '/records/tables';

if (is_dir($tablesDirectory)) {
    foreach (glob($tablesDirectory . '/*.php') as $path) {
        $config = require $path;

        if ($config instanceof RecordTableType) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $tables[$name] = $config;
        } elseif (is_array($config)) {
            $tables = array_merge($tables, $config);
        }
    }
}

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
    'api_prefix' => 'api/v1',

    /*
    |--------------------------------------------------------------------------
    | Global Settings
    |--------------------------------------------------------------------------
    */
    'max_depth' => env('RECORD_MAX_DEPTH', 3),
    'cache_ttl' => env('RECORD_CACHE_TTL', 3600),
    'lazy_cache_ttl' => env('RECORD_LAZY_CACHE_TTL', 300),
    'enable_tenant_id' => false,
    'table_config_path' => 'records/tables',

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
    | - softDeletes: Whether the table uses soft deletes
    | - hasTenantId: Whether the table has tenant_id column
    |
    */
    'tables' => $tables,

    /*
    |--------------------------------------------------------------------------
    | Global Functions
    |--------------------------------------------------------------------------
    |
    | Define global RPC functions that can be called via POST /{api_prefix}/{function_name}
    |
    */
    'global_functions' => [
        'searchAll' => new RecordFunctionType(
            httpMethod: [RecordFunctionMethodEnum::POST->value],
            class: 'App\\Services\\GlobalSearchService',
            functionName: 'searchAll',
            pmsName: 'search_all',
            description: 'Search across multiple resources',
            querySchema: [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 20,
                        'description' => 'Maximum results to return',
                    ],
                ],
            ],
            payloadSchema: [
                'type' => 'object',
                'properties' => [
                    'q' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'Search query',
                        'example' => 'invoice',
                    ],
                    'tables' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional list of tables to search',
                        'example' => ['users', 'posts'],
                    ],
                ],
                'required' => ['q'],
            ],
            responseSchema: [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'required' => ['data'],
            ],
        ),
    ],
];
