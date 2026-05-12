---
title: "QueryHelpers Trait Documentation"
description: "Purpose, usage, public API, query parameters, operators, and helper methods for QueryHelpers."
keywords:
  - QueryHelpers
  - trait documentation
  - query parameters
  - filter operators
  - helper methods
---

## Trait QueryHelpers Documentation

`Sopheak\Core\Traits\QueryHelpers` is an Eloquent-only utility for building custom ORM-based endpoints. It is not part of the Record CRUD table endpoints and does not affect how `/api/v1/{table}` works.

### Purpose

- Provide a single Eloquent scope (`applyRequestFilters`) that converts request query parameters into Eloquent query constraints.
- Standardize filtering, sorting, pagination, and eager-loading patterns for custom controllers/services using Eloquent models.

### Usage (Clean Architecture)

**Model**

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Sopheak\Core\Traits\QueryHelpers;

class Invoice extends Model
{
    use QueryHelpers;
}
```

**Service**

```php
namespace App\Services;

use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceService
{
    public function list(Request $request)
    {
        return Invoice::query()->applyRequestFilters($request);
    }
}
```

**Controller**

```php
namespace App\Http\Controllers;

use App\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $result = $this->invoices->list($request);

        if ($result instanceof \Illuminate\Database\Eloquent\Builder) {
            return response()->json($result->get());
        }

        return response()->json($result);
    }
}
```

### Public API

#### applyRequestFilters (Eloquent scope)

Signature:

```php
public function scopeApplyRequestFilters(
    \Illuminate\Database\Eloquent\Builder $builder,
    \Illuminate\Http\Request $request,
    bool $isArray = false,
    string $orderBy = 'id'
)
```

Return behavior:

- Returns `LengthAwarePaginator` when `per_page` is present.
- Returns `LazyCollection` when `lazy=true`.
- Returns `array{data: mixed, total: int}` when `total_record=true`.
- Returns a `Collection` when `$isArray=true`.
- Otherwise returns the modified `Eloquent\Builder`.

You can also use named arguments when calling the scope (PHP 8+):

```php
$result = Invoice::query()->applyRequestFilters(
    request: $request,
    isArray: true,
    orderBy: 'created_at',
);
```

### Supported Query Parameters

**Search**

- `s`: Searches across the model table's searchable text columns. Example: `?s=invoice`.
- `search`: Uses `RecordTableType(searchable: [...])` when that table is registered in the record schema. Supports root columns and one-level relationship fields. Example: `?search=invoice`.

**Select (main table only)**

- `select`: Selects only columns from the main table (table-prefixed). Example: `?select=id,invoice_number,total`.

**Eager Loading**

- `with`: Eloquent eager-loading. Examples:
  - `?with=customer,items`
  - `?with=posts.comments`
  - Column-constrained: `?with=customer(id,name)` or `?with=customer(*)`
  - JSON array is accepted: `?with=["customer(id,name)","items"]`

**Sorting**

- `sortby`: Sort column (defaults to `$orderBy`, default `id`)
- `order`: Sort direction (`asc` or `desc`, default `desc`)

**Result Shape**

- `per_page`: Enables pagination.
- `lazy=true`: Returns a `LazyCollection`.
- `limit`: Limits results when `$isArray=true` (max 20000).
- `total_record=true`: Returns `{ data, total }` (uses `limit` for the data size).

**Join Helpers**

- `join`: Table join by name. Example: `?join=customer` joins `customer.id = {main_table}.customer_id`.
- `select_join`: JSON map of `{table: [columns...]}`. Example: `?select_join={"customers":["name","email"]}`.

### Filter Operators

Operators are passed as `{column}={operator}.{value}`:

- `is.null`
- `eq.{value}`, `neq.{value}`
- `like.{value}`, `contains.{value}`
- `gt.{value}`, `gte.{value}`, `lt.{value}`, `lte.{value}`
- `in.{a,b,c}`
- `between.{start,end}`, `not_between.{start,end}`

Multiple columns OR (comma-separated keys):

- `?invoice_number,reference=contains.ACME`

Compare two columns:

- `?total=compare.gt.balance`

### Helper Methods (Internal)

These are private methods used by the trait implementation:

#### applyFilterOperator

```php
private function applyFilterOperator(
    \Illuminate\Database\Eloquent\Builder $builder,
    string $key,
    string $operator,
    string $queryValue,
    string $tableName
): void
```

#### parseSelectColumns

```php
private function parseSelectColumns(string $selectParam, string $tableName): array
```

#### parseWithRelations

```php
private function parseWithRelations(string $withParam): array
```

#### castStringToArray

```php
private function castStringToArray(string $value): array
```

#### handleOptimizedQuery

```php
private function handleOptimizedQuery(
    \Illuminate\Database\Eloquent\Builder $builder,
    \Illuminate\Http\Request $request
)
```
