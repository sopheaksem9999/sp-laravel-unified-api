# Architecture and Performance Improvements (Laravel 13 Support) Spec

## Why
The current proposed architecture improvements decouple side-effects (cache, audit) via Laravel Events. However, the events were originally designed to accept the `Illuminate\Http\Request` object. When queued, this will cause a serialization failure (Closure serialization error) in modern Laravel applications. This update ensures the architecture plan fully supports Laravel 13 by removing the Request object from events, using `readonly` classes, and passing primitive data structures.

## What Changes
- Create `readonly` event classes for `RecordCreated`, `RecordUpdated`, and `RecordDeleted` using PHP 8.2+ syntax.
- Replace the `Request` dependency in events with an `$auditContext` array containing primitive data (IP, User Agent, User ID).
- Update `LogRecordAuditListener` and `InvalidateRecordCacheListener` to accept and process the new event structure.
- Refactor `RecordService` to extract audit context data *before* dispatching the events.
- Implement explicit route binding for `{table}` in `CoreSpLaravelApiProvider` to enable route caching.
- Create `RecordQueryBuilder` for optimized DB selects and relationships.

## Impact
- Affected specs: Architecture Performance Improvements
- Affected code:
  - `src/Events/RecordCreated.php`
  - `src/Events/RecordUpdated.php`
  - `src/Events/RecordDeleted.php`
  - `src/Listeners/InvalidateRecordCacheListener.php`
  - `src/Listeners/LogRecordAuditListener.php`
  - `src/CoreSpLaravelApiProvider.php`
  - `src/Services/RecordService.php`
  - `src/Services/Queries/RecordQueryBuilder.php`

## ADDED Requirements
### Requirement: Laravel 13 Compatible Domain Events
The system SHALL use readonly event classes that only contain serializable data structures (strings, ints, arrays) and specifically prohibit the injection of `Illuminate\Http\Request` objects.

#### Scenario: Success case
- **WHEN** a dynamic record is created, updated, or deleted
- **THEN** the corresponding event is dispatched with an extracted `$auditContext` array, allowing queue workers to serialize the event without throwing closure exceptions.

### Requirement: Optimized Dynamic Querying
The system SHALL extract the base query generation logic into a dedicated `RecordQueryBuilder` to isolate database querying logic from the massive `RecordService` class and enforce strict select statements for performance.

## MODIFIED Requirements
### Requirement: Existing Dynamic Routing
**Reason**: Relying on regex loop compilation for `{table}` routes prevents Laravel from properly caching routes when the registry relies on database queries.
**Migration**: Implement explicit `Route::bind('table', closure)` to safely resolve tables and allow `php artisan route:cache` to function normally.
