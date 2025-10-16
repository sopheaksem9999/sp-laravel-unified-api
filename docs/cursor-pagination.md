# Cursor-Based Pagination

This document describes the cursor-based pagination implementation for the SP Laravel API package, which provides better performance for large datasets compared to traditional offset-based pagination.

## Overview

Cursor-based pagination uses a cursor (typically a unique identifier like `id` or `created_at`) to navigate through large datasets efficiently. Unlike traditional pagination that uses `OFFSET` and `LIMIT`, cursor pagination uses `WHERE` clauses with the cursor value, making it much faster for large datasets.

### Benefits

- **Better Performance**: No expensive `COUNT()` queries or large `OFFSET` operations
- **Consistent Results**: No duplicate or missing records when data is inserted/deleted during pagination
- **Scalable**: Performance remains constant regardless of dataset size
- **Real-time Friendly**: Works well with live data that changes frequently

## Configuration

Cursor pagination is configured in `config/cursor_pagination.php`:

```php
return [
    // Auto-detection threshold (rows)
    'auto_threshold' => 10000,
    
    // Default and maximum items per page
    'default_per_page' => 25,
    'max_per_page' => 100,
    
    // Default cursor column
    'default_cursor_column' => 'id',
    
    // Tables that always use cursor pagination
    'forced_tables' => [
        'large_table_name',
    ],
    
    // Tables that never use cursor pagination
    'excluded_tables' => [
        'migrations',
        'password_resets',
    ],
    
    // Cache table statistics for auto-detection (disabled)
    'cache_statistics' => false,
    'statistics_cache_ttl' => 3600,
];
```

## Auto-Detection

The system automatically detects when to use cursor-based pagination based on:

1. **Table Size**: Tables with more than `auto_threshold` rows (default: 10,000)
2. **Forced Tables**: Tables listed in `forced_tables` configuration
3. **Explicit Request**: When `cursor` parameter is present in the request

Tables in `excluded_tables` will never use cursor pagination.

## API Usage

### Basic Cursor Pagination

```http
# First page (using default API prefix 'api')
GET /api/invoices?per_page=25

# Next page using cursor
GET /api/invoices?per_page=25&cursor=12345&direction=next

# Previous page using cursor
GET /api/invoices?per_page=25&cursor=12345&direction=prev
```

**Note**: The API prefix is configurable via `config('record.api_prefix', 'api')` and can be customized to `/api/v1`, `/api/v2`, etc.

### Explicit Cursor Column

```http
# Use created_at as cursor column
GET /api/invoices?per_page=25&cursor_column=created_at

# Composite cursor for complex sorting
GET /api/invoices?per_page=25&composite_cursor=true&sortby=created_at
```

### Force Cursor Pagination

```http
# Force cursor pagination even for small tables
GET /api/small_table?per_page=25&cursor=0
```

## Response Format

### Cursor Pagination Response

```json
{
  "data": [
    // ... records
  ],
  "meta": {
    "per_page": 25,
    "cursor_column": "id",
    "has_more": true,
    "cursors": {
      "next": "12345",
      "prev": "12300"
    }
  }
}
```

### Response Headers

```http
X-Per-Page: 25
X-Cursor-Column: id
X-Has-More: true
Link: <https://api.example.com/invoices?cursor=12345&direction=next>; rel="next", <https://api.example.com/invoices?cursor=12300&direction=prev>; rel="prev"
```

### Traditional Pagination Response (for comparison)

```json
{
  "data": [
    // ... records
  ],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 50000
  }
}
```

## Implementation Details

### Simple Cursor Pagination

For single-column cursors (typically `id`):

```sql
-- Next page
SELECT * FROM invoices WHERE id < 12345 ORDER BY id DESC LIMIT 25;

-- Previous page
SELECT * FROM invoices WHERE id > 12345 ORDER BY id ASC LIMIT 25;
```

### Composite Cursor Pagination

For multi-column cursors (e.g., `created_at` + `id`):

```sql
-- Next page
SELECT * FROM invoices 
WHERE (created_at < '2025-01-01' OR (created_at = '2025-01-01' AND id < 12345))
ORDER BY created_at DESC, id DESC 
LIMIT 25;
```

### Auto-Detection Logic

1. Check if table is in `forced_tables` → Use cursor pagination
2. Check if table is in `excluded_tables` → Use traditional pagination
3. Estimate table size using `INFORMATION_SCHEMA.TABLES`
4. If size > `auto_threshold` → Use cursor pagination
5. Otherwise → Use traditional pagination

## Performance Considerations

### Index Requirements

For optimal performance, ensure proper indexes exist:

```sql
-- Single cursor column
CREATE INDEX idx_invoices_id ON invoices(id);

-- Composite cursor columns
CREATE INDEX idx_invoices_created_at_id ON invoices(created_at DESC, id DESC);

-- With additional filters
CREATE INDEX idx_invoices_status_created_at_id ON invoices(status, created_at DESC, id DESC);
```

### Memory Usage

Cursor pagination uses constant memory regardless of dataset size, while traditional pagination memory usage grows with page number.

### Cache Considerations

- Table statistics caching is disabled by default since no tables currently use cursor pagination
- Cache key format: `cursor_pagination_stats_{table_name}` (when enabled)
- Enable caching by setting `cache_statistics` to `true` if needed for large datasets

## Limitations

1. **No Total Count**: Cursor pagination doesn't provide total record count
2. **No Random Access**: Can't jump to arbitrary pages (page 50, etc.)
3. **Sorting Limitations**: Cursor column must be part of the sort order
4. **Complex Queries**: May not work well with complex JOINs or subqueries

## Migration from Traditional Pagination

### Client-Side Changes

```javascript
// Before (traditional pagination)
const response = await fetch('/api/v2/invoices?page=2&per_page=25');
const { data, meta } = await response.json();
console.log(`Page ${meta.page} of ${Math.ceil(meta.total / meta.per_page)}`);

// After (cursor pagination)
const response = await fetch('/api/v2/invoices?cursor=12345&direction=next&per_page=25');
const { data, meta } = await response.json();
if (meta.cursors?.next) {
  // Load next page using meta.cursors.next
}
```

### Backward Compatibility

The system maintains backward compatibility:

- Small tables continue using traditional pagination
- `page` parameter still works for traditional pagination
- Response format includes appropriate metadata for each type

## Troubleshooting

### Common Issues

1. **Slow Performance**: Ensure proper indexes on cursor columns
2. **Inconsistent Results**: Check if cursor column values are unique
3. **Auto-Detection Not Working**: Verify table statistics are up-to-date

### Debug Information

Enable query logging to see which pagination method is being used:

```php
// In your controller or service
DB::enableQueryLog();
// ... perform pagination
$queries = DB::getQueryLog();
```

### Configuration Testing

```php
// Test auto-detection
$query = DB::table('large_table');
$shouldUseCursor = CursorPagination::shouldUseCursorPagination($query);

// Test table statistics
$estimatedRows = CursorPagination::estimateRowCount($query);
```

## Best Practices

1. **Use Appropriate Indexes**: Always index cursor columns
2. **Choose Good Cursor Columns**: Prefer unique, sequential columns like `id` or `created_at`
3. **Monitor Performance**: Use query logging to verify pagination efficiency
4. **Configure Thresholds**: Adjust `auto_threshold` based on your data patterns
5. **Handle Edge Cases**: Implement proper error handling for invalid cursors

## Examples

### Laravel Controller

```php
class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query();
        
        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        // Pagination is handled automatically by QueryHelpers trait
        return $this->commonQuery($query, $request, true);
    }
}
```

### Vue.js Frontend

```vue
<template>
  <div>
    <div v-for="invoice in invoices" :key="invoice.id">
      {{ invoice.number }}
    </div>
    
    <button @click="loadNext" :disabled="!hasMore">Load More</button>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const invoices = ref([])
const nextCursor = ref(null)
const hasMore = ref(false)

const loadNext = async () => {
  const params = new URLSearchParams({
    per_page: '25',
    ...(nextCursor.value && { cursor: nextCursor.value, direction: 'next' })
  })
  
  const response = await fetch(`/api/v2/invoices?${params}`)
  const { data, meta } = await response.json()
  
  invoices.value.push(...data)
  nextCursor.value = meta.cursors?.next
  hasMore.value = meta.has_more
}
</script>
```