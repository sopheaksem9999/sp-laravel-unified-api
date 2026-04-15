---
title: "Record Type Config Examples"
description: "All Record*Type Objects Concrete config examples for RecordTableType, function/validator/trigger types, and all relationship Record*Type classes used by the package."
keywords:
  - RecordTableType example
  - RecordFunctionType example
  - RecordValidationType example
  - RecordTableTriggerType example
  - RecordBelongsToType
  - RecordHasManyType
  - RecordMetaBelongsToManyType
  - RecordSpatiePermissionType
---

# Record Type Config Examples

This page gives quick examples for each type class in `src/Types`.

## Core Table and RPC Types

### `RecordTableType`

```php
new RecordTableType(
    table: 'products',
    pmsName: 'product',
    hasTenantId: true,
    softDeletes: true,
    isAuthRead: true,
    isAuthWrite: true,
    columns: [
        'id' => ['type' => 'uuid', 'nullable' => false],
        'name' => ['type' => 'varchar', 'nullable' => false],
        'price' => ['type' => 'decimal(12,2)', 'nullable' => false],
    ],
    relationships: [
        'brand' => new RecordBelongsToType(table: 'brands', foreignKey: 'brand_id'),
        'items' => new RecordHasManyType(table: 'product_items', foreignKey: 'product_id'),
    ],
    functions: [
        'publish' => new RecordFunctionType(
            httpMethod: ['POST'],
            class: \App\Http\Controllers\ProductFunctionController::class,
            functionName: 'publish',
            pmsName: 'product',
            middleware: ['auth:sanctum']
        ),
    ],
)
```

### `RecordTablePublic` (legacy public flags)

```php
new RecordTableType(
    table: 'catalogs',
    public: new RecordTablePublic(read: true, write: false),
)
```

### `RecordFunctionType`

```php
new RecordFunctionType(
    httpMethod: ['GET', 'POST'],
    class: \App\Http\Controllers\InvoiceFunctionController::class,
    functionName: 'calculateTotals',
    pmsName: ['invoice', 'billing'],
    disableCache: false,
    cacheTTL: 300,
    clearCacheTables: ['invoices', 'invoice_items'],
    middleware: ['auth:sanctum', 'throttle:api-functions'],
)
```

### `RecordValidationType`

```php
createValidator: new RecordValidationType(
    class: \App\Validators\InvoiceValidator::class,
    functionName: 'createRules',
)
```

### `RecordTableTriggerType`

```php
beforeCreate: new RecordTableTriggerType(
    class: \App\Triggers\InvoiceTrigger::class,
    functionName: 'beforeCreate',
    description: 'Inject created_by before insert',
)
```

## Relationship Types

### `RecordBelongsToType`

```php
'customer' => new RecordBelongsToType(
    table: 'customers',
    type: RecordRelationshipsEnum::BELONGS_TO,
    foreignKey: 'customer_id',
    ownerKey: 'id',
)
```

### `RecordHasManyType`

```php
'items' => new RecordHasManyType(
    table: 'invoice_items',
    foreignKey: 'invoice_id',
    type: RecordRelationshipsEnum::HAS_MANY,
    localKey: 'id',
    with: ['product'],
    allowCreate: true,
    allowUpdate: true,
    allowDelete: true,
)
```

### `RecordHasManyThroughType`

```php
'payments' => new RecordHasManyThroughType(
    table: 'payments',
    through: 'invoice_payments',
    firstKey: 'invoice_id',
    secondKey: 'id',
    localKey: 'id',
    secondLocalKey: 'payment_id',
    orderBy: ['payment_date' => 'desc'],
    type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
)
```

### `RecordMetaHasManyThroughType`

```php
'modules' => new RecordMetaHasManyThroughType(
    table: 'modules',
    through: 'meta',
    firstKey: 'owner_id',
    secondKey: 'id',
    secondLocalKey: 'target_id',
    ownerColumn: 'owner',
    owner: 'packages',
)
```

### `RecordAassociationType`

```php
'modules' => new RecordAassociationType(
    related: 'modules',
    type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
    fromObjectType: 'packages',
    fromObjectId: 'owner_id',
    toObjectType: 'modules',
    toObjectId: 'target_id',
)
```

### `RecordMetaBelongsToManyType`

```php
'roles' => new RecordMetaBelongsToManyType(
    related: \App\Models\Role::class,
    type: RecordRelationshipsEnum::BELONGS_TO_MANY,
    table: 'model_has_roles',
    foreignPivotKey: 'model_id',
    relatedPivotKey: 'role_id',
    parentKey: 'id',
    relatedKey: 'id',
    relation: 'roles',
    withPivot: ['created_at'],
    wherePivot: ['model_type' => \App\Models\User::class],
    withTimestamps: false,
    select: ['id', 'name'],
)
```

### `RecordSpatiePermissionType`

```php
'roles' => new RecordSpatiePermissionType(
    related: \Spatie\Permission\Models\Role::class,
    relation: 'roles',
    type: RecordRelationshipsEnum::SPATIE_PERMISSION,
    teamsEnabled: true,
)
```
