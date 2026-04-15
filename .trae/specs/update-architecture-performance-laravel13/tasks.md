# Tasks
- [x] Task 1: Create Laravel 13 compatible Domain Events
  - [x] SubTask 1.1: Create `src/Events/RecordCreated.php` as a readonly class accepting `$table`, `$payload`, `$id`, and `$auditContext`.
  - [x] SubTask 1.2: Create `src/Events/RecordUpdated.php` as a readonly class accepting `$table`, `$oldPayload`, `$newPayload`, `$id`, and `$auditContext`.
  - [x] SubTask 1.3: Create `src/Events/RecordDeleted.php` as a readonly class accepting `$table`, `$oldPayload`, `$id`, and `$auditContext`.
- [x] Task 2: Create Event Listeners for Cache and Audit
  - [x] SubTask 2.1: Create `src/Listeners/InvalidateRecordCacheListener.php` to clear cache based on the event's table.
  - [x] SubTask 2.2: Create `src/Listeners/LogRecordAuditListener.php` (implementing `ShouldQueue`) to pass the `$auditContext` to `AuditLogService`.
  - [x] SubTask 2.3: Register both listeners in `src/CoreSpLaravelApiProvider.php`'s `boot` method.
- [x] Task 3: Extract RecordQueryBuilder for Performance
  - [x] SubTask 3.1: Create `src/Services/Queries/RecordQueryBuilder.php` to handle base query building, tenant isolation, and strict selects.
- [x] Task 4: Refactor RecordService to Dispatch Events
  - [x] SubTask 4.1: Modify `executeCreate` in `RecordService` to extract `$auditContext` and dispatch `RecordCreated`, removing synchronous calls.
  - [x] SubTask 4.2: Modify `executeUpdate` in `RecordService` to extract `$auditContext` and dispatch `RecordUpdated`, removing synchronous calls.
  - [x] SubTask 4.3: Modify `executeDelete` in `RecordService` to extract `$auditContext` and dispatch `RecordDeleted`, removing synchronous calls.
- [x] Task 5: Implement Route Binding for `{table}`
  - [x] SubTask 5.1: Add `Route::bind('table')` logic in `src/CoreSpLaravelApiProvider.php` to dynamically validate the table config.

# Task Dependencies
- [Task 2] depends on [Task 1]
- [Task 4] depends on [Task 1]