---
title: "Apply Request Filters (Builder Macro)"
description: "Apply dynamic CRUD filters/pagination to any Query Builder via the applyRequestFilters macro."
keywords:
  - applyRequestFilters
  - builder macro
  - query filtering
  - custom endpoint filtering
  - eloqueent
---

# Apply Request Filters (Builder Macro)

The package extends Laravel's `Illuminate\Database\Query\Builder` with a macro `applyRequestFilters`. This is the same filtering/pagination mechanism used by the record CRUD endpoints when listing records.

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

public function index(Request $request)
{
    // Start with any base query
    $query = DB::table('invoices')->where('active', true);

    // Apply filters from request (e.g. ?status=eq.paid&sortby=created_at)
    $result = $query->applyRequestFilters($request);

    return response()->json($result);
}
```

The `applyRequestFilters` method returns an array containing:

- `data`: The result set
- `meta`: Pagination metadata
- `headers`: Response headers
- `filters`: Applied filters
- `request`: Original request object
- `cursor_meta`: Cursor pagination metadata (if applicable)

**Builder macro signature:**

```php
public function applyRequestFilters(
    \Illuminate\Http\Request $request,
    bool $isArray = false,
    string $orderBy = 'id',
    ?string $tenantColumn = ''
): array
```

Because the macro has named parameters, you can also call it using named arguments (PHP 8+):

```php
$result = DB::table('invoices')->applyRequestFilters(
    request: $request,
    isArray: true,
    orderBy: 'created_at',
    tenantColumn: 'company_id',
);
```

## Related Docs

- [Standard CRUD Operations](/guide/api-crud-operations) — the same filter/sort/pagination syntax
- [QueryHelpers Trait](/guide/api-queryhelpers-trait) — the Eloquent-only trait for custom ORM endpoints
- [Pagination Module](/guide/module-pagination)
