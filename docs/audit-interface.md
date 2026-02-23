# Audit Log Documentation

This package supports audit logging in three common ways:

- Dynamic Record API (automatic) with an optional per-table `customAuditLog` hook.
- Controller-driven auditing with a custom `getAuditQuery()` snapshot.
- Model-driven auditing using `AuditableTrait`.

## Controller Auditing (Manual)

Use this approach when you want full control over what gets written to the audit log, or when you are not using `AuditableTrait`.

### Example: `insertAuditLog()` + `getAuditQuery()`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;

class CompanyController extends Controller
{
    public function update(Request $request, int $id)
    {
        DB::table('companies')->where('id', $id)->update([
            'name' => $request->input('name'),
        ]);

        AuditLogService::insertAuditLog(
            auditLogEventEnum: AuditLogEventEnum::UPDATED,
            entityClass: \App\Models\Company::class,
            queryData: $this->getAuditQuery($id),
        );

        return response()->json(['success' => true]);
    }

    public function getAuditQuery(int $id): array
    {
        $record = DB::table('companies')->where('id', $id)->first();

        if (!$record) {
            return [];
        }

        return AuditLogService::handleMapperQueryData((array) $record);
    }
}
```

## Dynamic Record API Hook (Per Table)

When you use the package dynamic CRUD, audit logging can be customized per table via `RecordTableType::customAuditLog`.

### RecordTableType Audit Options

`RecordTableType` provides two audit-related knobs for the Dynamic Record API:

- `disableAuditLog` (bool): Skips built-in audit logging for create/update/delete for this table.
- `customAuditLog` (callable|string|array): Overrides built-in logging for this table. When it is configured and resolvable, the package calls your handler and does not call `AuditLogService::insertAuditLog()`.

Example:

```php
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

return [
    'tables' => [
        'companies' => new RecordTableType(
            table: 'companies',
            pmsName: 'company',
            public: new RecordTablePublic(read: true, write: true),
            disableAuditLog: false,
            customAuditLog: [\App\Audit\CompanyAuditLogger::class, 'handle'],
        ),
    ],
];
```

Handler signature:

```php
use Sopheak\Core\Enums\AuditLogEventEnum;

function handle(
    AuditLogEventEnum $event,
    string $entityClass,
    array $auditData,
    mixed $tenantId,
    array $context
): void
```

Notes:

- `$auditData` should include an `id` (or `entity_id`) for update/delete events; otherwise the audit entry is skipped.
- `$context` contains: `request`, `table`, `operation` (`create|update|delete`), and `record_context`.

### Global Audit Config That Affects RecordTableType

Audit logging also depends on the global audit config (`config/audit.php`):

- `audit.enabled`: Must be `true` or the package will not create audit logs.
- `audit.queue_enabled`: When `true`, audit writes are dispatched to a job (instead of being written inline).
- `audit.log_relationships`: When `true`, audit snapshots include configured relationships (default: `false`).

If you enable tenant mode (`record.enable_tenant_id = true`), audit logs also store the tenant column (default: `tenant_id`).

## Audit Formatting & Labels

Audit title, subject, and recap values are generated from config and normalized by `AuditLogService::generateLabel()` (camelCase, snake_case, and hyphenated values are converted into readable labels).

### Subject Fields

`audit.subject_fields` defines the ordered list of fields to use for the audit subject. If the list is empty, the subject is blank.

Example:

```php
'subject_fields' => ['ref_number', 'name'],
```

### Entity Labels

`audit.entity_labels` defines explicit labels per entity. If a label is not defined, the value is derived from `generateLabel()` instead of a hardcoded default.

Example:

```php
'entity_labels' => [
    'invoices' => 'Invoice',
    'sale_orders' => 'Sale Receipt',
],
```

### Recap Generation

For updated records, the recap is generated from changed main fields:

- `audit.recap_entities`: Entities that use the detailed recap formatter.
- `audit.main_field_labels`: Field label map used by detailed recaps and generic recaps.
- `audit.recap_max_fields`: Max number of fields shown in generic recaps. Additional fields are summarized as “and N more”.
- Technical fields like `id`, `created_at`, `updated_at`, and `deleted_at` are excluded from recap changes.

## Model Auditing (Eloquent)

Apply `AuditableTrait` to an Eloquent model to automatically log create/update/delete events.

```php
use Illuminate\Database\Eloquent\Model;
use Sopheak\Core\Traits\AuditableTrait;

class Company extends Model
{
    use AuditableTrait;

    protected $guarded = [];
}
```
