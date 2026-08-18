---
title: "Relationships"
description: "Relationship selection (select/with), filtering, supported relationship types, configuration limits, and the relationship write payload guide."
keywords:
  - relationship selection
  - relationship types
  - relationship filtering
  - relationship write payload
  - select include
  - with include
---

# Relationships

This page documents relationship selection (`select=` / `with=`), nested
filtering, the supported relationship types, and the relationship write payload
guide. For the request-filtering macro used outside dynamic CRUD, see
[Apply Request Filters](/guide/api-apply-request-filters).

### Relationship Selection & Filtering

Relationship loading uses the `select` or `with` query parameter for nested inclusion and filtering. Both parameters support the exact same syntax and capabilities.

**Syntax:**
`?select=column1,column2,relationship(column1,column2,filter)`
or
`?with=relationship(column1,column2,filter)`

**Examples:**

1. **Basic Inclusion:**
   `GET /api/v1/invoices?select=*,customer(*)`
   or
   `GET /api/v1/invoices?with=customer(*)`
   Fetches all columns from invoices and all columns from the `customer` relationship.

2. **Nested Inclusion:**
   `GET /api/v1/customers?select=*,orders(*,items(*))`
   or
   `GET /api/v1/customers?with=orders(*,items(*))`
   Fetches customers with their orders and order items.

3. **Filtering Nested Records (Embedding):**
   You can apply filters to related records using the `column=operator.value` syntax inside the relationship parenthesis.

   `GET /api/v1/projects?select=*,tasks(*,assignees(*,name=eq.admin))`
   or
   `GET /api/v1/projects?with=tasks(*,assignees(*,name=eq.admin))`

   This fetches:
   - All columns from `projects`
   - All columns from `tasks`
   - All columns from `assignees` (users) WHERE `name` equals `admin`.

   **Supported Operators in Nested Filters:**
   - `eq`: Equal (`name=eq.John`)
   - `neq`: Not equal (`status=neq.archived`)
   - `gt`, `gte`: Greater than (or equal) (`age=gte.18`)
   - `lt`, `lte`: Less than (or equal) (`price=lt.100`)
   - `like`: Pattern matching (`name=like.%Smith%`)
   - `in`: In list (`status=in.active,pending`)

   **Note:** If no operator is specified (e.g., `name=admin`), it defaults to equality (`eq`).

4. **Filtering by Relationship (Top-Level):**
   You can filter the main result set based on criteria in related tables using the dot notation `relationship.column=operator.value`.

   `GET /api/v1/users?select=*,posts(*)&roles.name=eq.admin`
   or
   `GET /api/v1/users?with=posts(*)&roles.name=eq.admin`

   This fetches:
   - Users who have a role named 'admin'.
   - Includes their posts (if requested via `select` or `with`).

   **Supported Relationships:**
   - `belongsTo`
   - `hasMany` (uses EXISTS subquery)
   - `hasManyThrough`
   - `belongsToMany` (uses pivot table)

   **Example:**
   `GET /api/v1/posts?author.name=eq.John`
   Fetches posts where the author's name is 'John'.

5. **Combining `select` and `with`:**
   You can use both parameters together. They will be merged automatically.
   `GET /api/v1/customers?select=id,name&with=invoices(id,total)`

6. **Using `with=` prefix inside `select`:**
   For compatibility with some frontend libraries, you can prefix relationship names with `with=` inside the `select` parameter.
   `GET /api/v1/customers?select=*,with=invoices(id,total)`

### Configuration Limits

To prevent performance issues and memory exhaustion from deeply nested or overly broad queries, you can configure the following limits in `config/record.php`:

- **`max_depth`** (default: 10): The maximum nesting depth for relationship queries.
- **`subquery_optimization_max_records`** (default: 100): The maximum list size that uses relationship subquery JSON optimization before falling back to bulk loading.

### Supported Relationship Types

The dynamic API understands all relationship types declared in `RecordRelationshipsEnum`. These relationships are configured per table via the `relationships` array on `RecordTableType` and are available to `select` and filter expressions.

#### Belongs To (`belongsTo`)

Use `RecordBelongsToType` when the current table has a foreign key pointing to a parent table.

Example configuration:

```php
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordBelongsToType;

'invoices' => new RecordTableType(
    table: 'invoices',
    relationships: [
        'customer' => new RecordBelongsToType(
            table: 'customers',
            type: RecordRelationshipsEnum::BELONGS_TO,
            foreignKey: 'customer_id',
            ownerKey: 'id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/invoices?select=*,customer(*)`
- `GET /api/v1/invoices?customer.name=like.%Acme%`

#### Has Many (`hasMany`)

Use `RecordHasManyType` when the current table is the parent and the related table has the foreign key.

Example configuration:

```php
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Types\RecordHasManyType;

'customers' => new RecordTableType(
    table: 'customers',
    relationships: [
        'invoices' => new RecordHasManyType(
            table: 'invoices',
            type: RecordRelationshipsEnum::HAS_MANY,
            foreignKey: 'customer_id',
            localKey: 'id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/customers?select=*,invoices(*)`
- `GET /api/v1/customers?invoices.status=eq.paid`

#### Has One (`hasOne`)

Use `RecordHasManyType` with `RecordRelationshipsEnum::HAS_ONE` when the related table has a unique row per parent (semantically has-one, loaded via the same optimized path as has-many).

Example configuration:

```php
'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'profile' => new RecordHasManyType(
            table: 'user_profiles',
            type: RecordRelationshipsEnum::HAS_ONE,
            foreignKey: 'user_id',
            localKey: 'id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,profile(*)`

#### Belongs To Many (`belongsToMany`)

Use `RecordMetaBelongsToManyType` for many-to-many relationships backed by a pivot table. This cannot be replaced by `RecordAassociationType` because `RecordAassociationType` only supports has-many-through over a meta table with owner/target columns and does not support pivot semantics (extra pivot columns, timestamps, morph pivots, or arbitrary pivot keys).

Example configuration:

```php
use Sopheak\Core\Types\RecordMetaBelongsToManyType;

'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'roles' => new RecordMetaBelongsToManyType(
            related: 'roles',
            type: RecordRelationshipsEnum::BELONGS_TO_MANY,
            table: 'role_user',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'role_id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,roles(*)`
- `GET /api/v1/users?roles.name=eq.admin`

#### Has Many Through (`hasManyThrough`)

Three variants are supported:

1. **Standard has-many-through** using `RecordHasManyThroughType`.
2. **Global meta-table has-many-through** using `RecordMetaHasManyThroughType`.
3. **Association has-many-through** using `RecordAassociationType` with simplified parameters.

`RecordAassociationType` can replace `RecordMetaHasManyThroughType` only when your meta table uses the standard columns (`owner`, `owner_id`, `target`, `target_id`) and `owner` stores the source table name while `target` stores the related table name. If your meta table uses different column names or needs ownerColumn customization, keep `RecordMetaHasManyThroughType`.

Standard example:

```php
use Sopheak\Core\Types\RecordHasManyThroughType;

'projects' => new RecordTableType(
    table: 'projects',
    relationships: [
        'tasks' => new RecordHasManyThroughType(
            table: 'tasks',
            through: 'project_tasks',
            firstKey: 'project_id',
            secondKey: 'id',
            localKey: 'id',
            secondLocalKey: 'task_id',
        ),
    ],
),
```

Global meta-table example:

```php
use Sopheak\Core\Types\RecordMetaHasManyThroughType;

'packages' => new RecordTableType(
    table: 'packages',
    relationships: [
        'modules' => new RecordMetaHasManyThroughType(
            table: 'modules',
            through: 'meta',
            firstKey: 'owner_id',
            secondKey: 'id',
            localKey: 'id',
            secondLocalKey: 'target_id',
            ownerColumn: 'owner',
            owner: 'package',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/projects?select=*,tasks(*)`
- `GET /api/v1/packages?select=*,modules(*)`

Association example (simplified parameters with meta table):

```php
use Sopheak\Core\Types\RecordAassociationType;

'packages' => new RecordTableType(
    table: 'packages',
    relationships: [
        'modules' => new RecordAassociationType(
            related: 'meta',
            type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
            fromObjectType: 'packages',
            fromObjectId: 'owner_id',
            toObjectType: 'modules',
            toObjectId: 'target_id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/packages?select=*,modules(*)`

#### Has One Through (`hasOneThrough`)

`RecordHasManyThroughType` also supports the `HAS_ONE_THROUGH` semantic. In most cases, you configure it the same way as has-many-through but use the enum to indicate the expected cardinality.

Example configuration:

```php
'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'latestInvoice' => new RecordHasManyThroughType(
            table: 'invoices',
            through: 'invoice_logs',
            firstKey: 'user_id',
            secondKey: 'id',
            localKey: 'id',
            secondLocalKey: 'invoice_id',
            orderBy: ['created_at' => 'desc'],
            type: RecordRelationshipsEnum::HAS_ONE_THROUGH,
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,latestInvoice(*)`

#### Morph Relationships

Morph relationships are detected via `RecordRelationshipsEnum::isMorphRelationship()` and are supported anywhere relationship selection is supported.

##### morphMany (`RecordMorphHasManyType`)

A polymorphic one-to-many relationship (no pivot table): the related table has a discriminator column (e.g. `target_type`) and a foreign key column (e.g. `target_id`), and each parent table supplies its own discriminator value via `morphClass`.

```php
use Sopheak\Core\Types\RecordMorphHasManyType;

'videos' => new RecordTableType(
    table: 'videos',
    relationships: [
        'translations' => new RecordMorphHasManyType(
            table: 'translations',
            morphType: 'target_type',
            morphId: 'target_id',
            morphClass: 'videos',
            localKey: 'id',
        ),
    ],
),
'promotions' => new RecordTableType(
    table: 'promotions',
    relationships: [
        'translations' => new RecordMorphHasManyType(
            table: 'translations',
            morphType: 'target_type',
            morphId: 'target_id',
            morphClass: 'promotions',
            localKey: 'id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/videos?select=*,translations(*)` — only returns `translations` rows where `target_type = 'videos'` and `target_id` matches the video, even if a `promotions` row shares the same id.
- Nested create (`POST /api/v1/videos` with a `translations` array in the payload) sets `target_type`/`target_id` on each child row automatically — any client-supplied `target_type`/`target_id` in the payload is overridden, so a request can't link a translation to the wrong parent or table.
- `allowCreate` / `allowUpdate` / `allowDelete` (all default `true`) control whether nested writes are permitted for this relationship.

##### morphTo / morphOne

These don't have a dedicated `RecordXxxType` class yet — they're typically configured via specialized resource classes or custom loaders. The enum types are:

- `MORPH_TO`
- `MORPH_ONE`

Example conceptual usage (comments only):

- A `comments` table with `commentable_type` and `commentable_id` can be exposed as a morphTo relationship from `comments` to multiple parent tables (e.g. posts, invoices).
- In the API, you can select nested comments using `?select=*,comments(*)` regardless of the underlying parent model.

##### morphToMany / morphByMany

Many-to-many morph relationships use a pivot table and are treated as pivot-supporting morph types.

Example using `RecordMetaBelongsToManyType` with a morph relation:

```php
'models' => new RecordTableType(
    table: 'models',
    relationships: [
        'roles' => new RecordMetaBelongsToManyType(
            related: config('permission.models.role'),
            type: RecordRelationshipsEnum::MORPH_TO_MANY,
            table: config('permission.table_names.model_has_roles'),
            foreignPivotKey: config('permission.column_names.model_morph_key'),
            relatedPivotKey: 'role_id',
            relation: 'model',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/models?select=*,roles(*)`

##### Spatie Permission (`spatiePermission`)

The package includes a dedicated `RecordSpatiePermissionType` to integrate with `spatie/laravel-permission` using a morphToMany pattern.

Example configuration:

```php
use Sopheak\Core\Types\RecordSpatiePermissionType;

'users' => new RecordTableType(
    table: 'users',
    relationships: [
        'roles' => new RecordSpatiePermissionType(
            related: config('permission.models.role'),
            relation: 'model',
            recordRelationshipsEnum: RecordRelationshipsEnum::SPATIE_PERMISSION,
            table: config('permission.table_names.model_has_roles'),
            foreignPivotKey: config('permission.column_names.model_morph_key'),
            relatedPivotKey: 'role_id',
        ),
    ],
),
```

Example usage:

- `GET /api/v1/users?select=*,roles(*)`
- `GET /api/v1/users?roles.name=eq.admin`

### Relationship Write Payload Guide

For `POST` / `PUT` / `PATCH`, relationship input is type-driven and should follow the config in `RecordTableType->relationships`.

#### What can be sent in payload

| Enum type (`RecordRelationshipsEnum`) | Payload support | Payload shape |
|---|---|---|
| `BELONGS_TO` | ✅ FK scalar only | `customer_id: 10` |
| `HAS_MANY` | ✅ alias array | `items: [1, {"id": 2}, {"name": "Line A"}]` |
| `BELONGS_TO_MANY` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `HAS_MANY_THROUGH` | ✅ alias array | `tasks: [3, {"id": 4}]` |
| `MORPH_MANY` | ✅ alias array | `comments: [1, {"id": 2}]` |
| `MORPH_TO_MANY` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `MORPH_BY_MANY` | ✅ alias array | `tags: [1, {"id": 2}]` |
| `SPATIE_PERMISSION` | ✅ alias array | `roles: [1, {"id": 2}]` |
| `HAS_ONE` | ⚠️ use FK style of your schema | Prefer scalar FK field in root payload |
| `HAS_ONE_THROUGH` | ⚠️ not a direct write alias | Use main table fields / custom function |
| `MORPH_TO` | ⚠️ use morph columns in root payload | `commentable_type`, `commentable_id` |
| `MORPH_ONE` | ⚠️ use FK style of your schema | Prefer scalar FK field in root payload |

#### FK-style examples (`BELONGS_TO`)

```json
{
  "ref_number": "INV-1001",
  "customer_id": 10
}
```

Do not send:

```json
{
  "customer": { "id": 10, "name": "Acme" }
}
```

#### Many-type alias examples (`*Many`)

```json
{
  "items": [
    1,
    { "id": 2 },
    { "name": "Line A", "qty": 1 },
    { "id": 5, "_delete": true }
  ]
}
```

#### Pivot-style examples (`BELONGS_TO_MANY`, `MORPH_TO_MANY`, `SPATIE_PERMISSION`)

```json
{
  "roles": [
    1,
    { "id": 2 },
    { "id": 3, "_delete": true }
  ]
}
```

#### Notes

- Array relationship aliases are accepted only when declared in table `relationships` config.
- For `BELONGS_TO`, the payload should use root FK scalar fields, not nested objects.
- `_delete` / `_destroy` can be used on alias-array items where relationship handling supports detach/remove — it requires the related row's primary key, since that's what identifies which row to remove.
- **`allowCreate`/`allowUpdate`/`allowDelete: false` rejects the request, it does not silently skip the item**: an alias-array item that asks for an operation the relationship's config disallows (e.g. a `_delete` item when `allowDelete: false`, or a new item with no id when `allowCreate: false`) returns `422` naming the relationship, the action, and the disabled flag — the whole write (parent included) is rolled back, not just that item. The one exception is re-sending an already-linked `belongsToMany`/`morphToMany`/`morphByMany`/`spatiePermission` item with no pivot fields to change: that's a no-op regardless of `allowUpdate`, since nothing would actually change.
