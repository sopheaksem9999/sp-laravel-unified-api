# Performance & Scalability

This document outlines the performance characteristics of the `sp-laravel-api` package and provides recommendations for high-scale environments.

## Benchmarks

The following benchmarks were conducted on a local development environment to establish baseline performance metrics for the `bulkRecord` endpoint.

### Test Environment
- **PHP**: 8.3
- **Database**: SQLite (In-Memory for testing) / MySQL 8.0 (Production target)
- **Framework**: Laravel 11.x
- **Hardware**: Apple M1 Pro (Local Dev)

### Bulk Insert Results

| Items per Request | Total Duration | Time per Item | Memory Usage |
|------------------:|---------------:|--------------:|-------------:|
| 1                 | ~5 ms          | ~5.00 ms      | ~0.3 MB      |
| 10                | ~26 ms         | ~2.60 ms      | ~0.8 MB      |
| 50                | ~114 ms        | ~2.27 ms      | ~0.2 MB      |
| 100               | ~445 ms        | ~4.45 ms      | ~0.2 MB      |

*Note: Duration excludes framework bootstrap time (approx 20-30ms per request in standard PHP-FPM).*

## Scaling to 10k Requests Per Second

To achieve a throughput of 10,000 operations per second, we recommend the following strategies based on our benchmark data.

### Strategy A: High Concurrency (1 Item per Request)
If your client sends 10,000 individual HTTP requests per second:

- **Throughput**: ~30-50 requests/sec per PHP-FPM worker.
- **Resources**:
    - **Concurrency**: ~500 concurrent workers needed.
    - **Memory**: ~15 GB RAM (assuming 30MB per worker).
    - **CPU**: High overhead due to context switching and framework bootstrapping.

### Strategy B: Bulk Batching (Recommended)
If you batch operations into groups of 100 items per request (100 requests/sec total):

- **Throughput**: Each request takes ~450ms to process 100 items.
- **Resources**:
    - **Concurrency**: ~45 concurrent workers needed.
    - **Memory**: ~1.6 GB RAM.
    - **CPU**: Significantly lower overhead.
    - **Efficiency**: ~90% reduction in resource usage compared to single-item requests.

## Architecture for 1 Million RPM (16k+ RPS)

Handling **1 million requests per minute** (~16,700 req/sec) requires specific architectural choices. This package is capable of supporting this load **only if** the following configurations are applied.

### 1. Mandatory Infrastructure
- **Laravel Octane**: You **must** use Laravel Octane (Swoole or RoadRunner) to eliminate the 20-30ms framework boot time. Without Octane, you would need ~500+ CPU cores just to handle the boot overhead.
- **Queue Driver**: Redis or SQS (not database).

### 2. Audit Log Strategy (Critical)
At 1M RPM, writing audit logs to a standard MySQL/PostgreSQL table is **not viable** (1.4 billion rows/day).
- **Configuration**: Set `AUDIT_LOG_QUEUE=true` in `.env`.
- **Storage**: You should override the `AuditLogJob` to write to a high-volume data store (e.g., **ClickHouse**, **Elasticsearch**, or **DynamoDB**) instead of the default SQL table.
- **Filtering**: Aggressively use `excluded_events` in `config/audit.php` to ignore high-frequency, low-value events.

### 3. Caching
- **Schema Registry**: The package caches schema definitions in memory (RAM) and Redis. Ensure your Redis instance is sized correctly.
- **Query Caching**: Use the `QueryCacheService` for read-heavy endpoints.

### 4. Bulk Triggers Warning
The `executeTableTrigger` method runs PHP logic for **every single item** in a bulk batch.
- **Do**: Keep triggers lightweight (e.g., setting a timestamp, simple calculation).
- **Don't**: Perform DB queries or external API calls inside a trigger. This will cause a massive performance degradation in bulk operations.

### 5. Async Bulk Processing (Zero-Downtime Scaling)
For high-volume writes where client latency is critical, use the Async Bulk feature. This offloads processing to the queue workers, returning an immediate `202 Accepted` response.

- **Usage**: Add `?async=true` to the URL or header `X-Async-Process: true`.
- **Benefit**: API responds in <10ms regardless of batch size.
- **Requirement**: Ensure your queue workers are scaled (e.g., via Kubernetes HPA) to handle the backlog.

## Optimization Tips

1.  **Use Bulk Endpoints**: Always prefer `POST /{table}/bulk/create` over individual `POST` requests for large data imports.
2.  **Audit Logs**: Audit logging doubles the database write volume. For massive data migrations, consider temporarily disabling audit logs or using a queued listener.
3.  **Triggers**: Table triggers (`beforeCreate`, `afterCreate`) run for *every* item in a bulk request. Ensure your trigger logic is lightweight.
4.  **Laravel Octane**: For high-concurrency scenarios (Strategy A), using Laravel Octane (Swoole/RoadRunner) can eliminate the framework bootstrap overhead, significantly improving throughput for single-item requests.
