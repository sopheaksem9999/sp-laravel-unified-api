#! Deprecated: Cursor Pagination

This package previously included an experimental cursor-based pagination service and related configuration. That implementation has been removed in favor of using Laravel's default page/per_page style pagination everywhere.

All record listing endpoints now use traditional pagination with `page` and `per_page` query parameters and return:

- `meta.page`
- `meta.per_page`
- `meta.total`
