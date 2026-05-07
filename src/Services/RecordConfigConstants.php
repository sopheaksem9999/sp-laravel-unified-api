<?php

namespace Sopheak\Core\Services;

/**
 * Centralized configuration constants for the Record API.
 *
 * This class extracts magic numbers from config files into named constants
 * for better maintainability and code clarity.
 */
final class RecordConfigConstants
{
    // Pagination & Limits
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 10000;
    public const MAX_LIMIT = 10000;
    public const MAX_BULK_ITEMS = 1000;

    // Cache
    public const DEFAULT_CACHE_TTL = 3600;
    public const CACHE_PREFIX = 'sp_laravel_api';

    // Relationship Depth
    public const DEFAULT_MAX_DEPTH = 10;

    // HTTP Timeouts
    public const WEBHOOK_TIMEOUT = 10;
    public const MAX_WEBHOOK_PAYLOAD_BYTES = 1048576; // 1MB

    // Retry Configuration
    public const DEFAULT_JOB_TRIES = 3;
    public const DEFAULT_JOB_BACKOFF = [60, 300, 600]; // 1 min, 5 mins, 10 mins

    // Rate Limiting
    public const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;
    public const DEFAULT_RATE_LIMIT_BURST = 10;

    private function __construct()
    {
        // Prevent instantiation
    }
}