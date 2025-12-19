<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cursor Pagination Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for cursor-based pagination
    | which provides better performance for large datasets compared to
    | traditional offset-based pagination.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Auto-Detection Threshold
    |--------------------------------------------------------------------------
    |
    | The minimum estimated row count threshold above which cursor-based
    | pagination will be automatically used instead of traditional pagination.
    | Set to 0 to disable auto-detection.
    |
    */
    'auto_threshold' => env('CURSOR_PAGINATION_THRESHOLD', 10000),

    /*
    |--------------------------------------------------------------------------
    | Default Per Page
    |--------------------------------------------------------------------------
    |
    | The default number of items per page when using cursor pagination.
    |
    */
    'default_per_page' => env('CURSOR_PAGINATION_PER_PAGE', 15),

    /*
    |--------------------------------------------------------------------------
    | Maximum Per Page
    |--------------------------------------------------------------------------
    |
    | The maximum number of items that can be requested per page.
    |
    */
    'max_per_page' => env('CURSOR_PAGINATION_MAX_PER_PAGE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Default Cursor Column
    |--------------------------------------------------------------------------
    |
    | The default column to use for cursor pagination when not specified.
    | This should be an indexed column for optimal performance.
    |
    */
    'default_cursor_column' => env('CURSOR_PAGINATION_DEFAULT_COLUMN', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Forced Tables
    |--------------------------------------------------------------------------
    |
    | Tables that should always use cursor-based pagination regardless
    | of the auto-detection threshold.
    |
    */
    'forced_tables' => [
        // 'large_table_name',
        // 'another_large_table',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables that should never use cursor-based pagination, even if they
    | exceed the auto-detection threshold.
    |
    */
    'excluded_tables' => [
        'migrations',
        'password_resets',
        'personal_access_tokens',
        'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Statistics
    |--------------------------------------------------------------------------
    |
    | Whether to cache table row count statistics for auto-detection.
    | Enabled for better performance with optimized estimation strategies.
    |
    */
    'cache_statistics' => true,

    /*
    |--------------------------------------------------------------------------
    | Statistics Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) to cache table statistics.
    | Shorter TTL for more accurate estimates in dynamic environments.
    |
    */
    'statistics_cache_ttl' => 300, // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Default Row Count Estimate
    |--------------------------------------------------------------------------
    |
    | Default row count estimate when all estimation strategies fail.
    | Used as ultimate fallback to prevent pagination failures.
    |
    */
    'default_estimate' => env('CURSOR_PAGINATION_DEFAULT_ESTIMATE', 5000),

    /*
    |--------------------------------------------------------------------------
    | Estimation Strategy Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) to spend on row count estimation.
    | Prevents slow database queries from blocking pagination.
    |
    */
    'estimation_timeout' => env('CURSOR_PAGINATION_ESTIMATION_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Sampling Size
    |--------------------------------------------------------------------------
    |
    | Number of rows to sample for statistical estimation when other
    | strategies fail. Larger samples are more accurate but slower.
    |
    */
    'sampling_size' => env('CURSOR_PAGINATION_SAMPLING_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Estimation Multiplier
    |--------------------------------------------------------------------------
    |
    | Default multiplier used for statistical estimation when full sample
    | is retrieved. Adjusted automatically based on table size.
    |
    */
    'estimation_multiplier' => env('CURSOR_PAGINATION_ESTIMATION_MULTIPLIER', 10),

    /*
    |--------------------------------------------------------------------------
    | Schema Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) to cache table schema information for column
    | validation. Helps reduce database schema queries for better performance.
    |
    */
    'schema_cache_ttl' => env('CURSOR_PAGINATION_SCHEMA_CACHE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Maximum Cursor Columns
    |--------------------------------------------------------------------------
    |
    | Maximum number of columns allowed in composite cursor pagination.
    | Prevents performance issues with overly complex cursors.
    |
    */
    'max_cursor_columns' => env('CURSOR_PAGINATION_MAX_CURSOR_COLUMNS', 5),

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security-related configuration for cursor pagination.
    |
    */
    'security' => [
        // | Enable strict cursor validation
        'strict_validation' => env('CURSOR_PAGINATION_STRICT_VALIDATION', true),

        // | Maximum cursor length to prevent DoS attacks
        'max_cursor_length' => env('CURSOR_PAGINATION_MAX_CURSOR_LENGTH', 1024),

        // | Rate limiting for cursor pagination requests
        'rate_limit_enabled' => env('CURSOR_PAGINATION_RATE_LIMIT', false),
        'rate_limit_requests' => env('CURSOR_PAGINATION_RATE_LIMIT_REQUESTS', 100),
        'rate_limit_window' => env('CURSOR_PAGINATION_RATE_LIMIT_WINDOW', 60), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Table-Specific Thresholds
    |--------------------------------------------------------------------------
    |
    | Override auto-detection threshold for specific tables.
    | Useful for tables with known performance characteristics.
    |
    */
    'table_thresholds' => [
        // 'invoices' => 5000,
        // 'invoice_items' => 10000,
        // 'audit_logs' => 1000,
    ],
];
