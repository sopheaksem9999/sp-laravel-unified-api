---
title: "Record Data Types"
description: "Reference for data types used in RecordTableType columns, automatic default-validation type rules, and response casting types Columns, Validation, Casting."
keywords:
  - record data types
  - columns type mapping
  - default validation types
  - cast types
  - uuid integer numeric boolean
  - datetime json array
---

# Record Data Types

This page collects all practical type mappings used by the package in one place.

## 1) Column Metadata Types in `RecordTableType::columns`

You can store database-like type strings in `columns.{field}.type`, for example:

- `uuid`
- `integer`, `bigint`, `smallint`
- `numeric`, `decimal(12,2)`, `float`, `double`
- `boolean`
- `date`, `timestamp`, `datetime`
- `varchar`, `char`, `text`
- `json`, `jsonb`

Example:

```php
'orders' => new RecordTableType(
    table: 'orders',
    columns: [
        'id' => ['type' => 'uuid', 'nullable' => false],
        'customer_id' => ['type' => 'bigint', 'nullable' => false],
        'total' => ['type' => 'decimal(12,2)', 'nullable' => false],
        'is_paid' => ['type' => 'boolean', 'nullable' => false, 'default' => false],
        'placed_at' => ['type' => 'timestamp', 'nullable' => true],
        'metadata' => ['type' => 'jsonb', 'nullable' => true],
        'note' => ['type' => 'text', 'nullable' => true],
    ],
)
```

## 2) Default Validation Type Mapping (`DefaultValidationUtils`)

When `record.default_validation.types=true`, column type is mapped to Laravel rules as:

| DB type pattern | Validation rule |
|---|---|
| contains `uuid` | `uuid` |
| `int2,int4,int8,integer,bigint,smallint,serial,bigserial` | `integer` |
| `numeric,decimal,float4,float8,real,double precision,double,float` | `numeric` |
| `bool,boolean` | `boolean` |
| contains `json` | `array` |
| contains `date` or `timestamp` | `date` |
| contains `time` | `string` |
| contains `char,text,varchar` | `string` |

## 3) Response Casting Types (`RecordTableType::casting`)

Supported cast strings:

- `int`, `integer`
- `float`, `double`, `real`
- `decimal`, `decimal:N`
- `string`
- `bool`, `boolean`
- `array`, `json`
- `object`
- `date`
- `datetime`
- `timestamp`

Custom cast forms:

- `Closure`
- `'Class@method'`
- `[ClassName::class, 'method']`
- Class name with `get()` method

Example:

```php
'orders' => new RecordTableType(
    table: 'orders',
    casting: [
        'total' => 'decimal:2',
        'is_paid' => 'boolean',
        'placed_at' => 'datetime',
        'metadata' => 'array',
        'customer.score' => 'decimal:2', // dot-notation for relationship column
    ],
)
```

## 4) Relationship Enum Type Values (`RecordRelationshipsEnum`)

Available relationship types:

- `belongsTo`
- `hasMany`
- `hasOne`
- `belongsToMany`
- `hasManyThrough`
- `hasOneThrough`
- `morphTo`
- `morphOne`
- `morphMany`
- `morphToMany`
- `morphByMany`
- `spatiePermission`
