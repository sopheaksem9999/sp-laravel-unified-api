---
title: "Validation"
description: "Table-level validators (create/update/delete), PHP attribute validators, and schema-based default validation."
keywords:
  - validation
  - createValidator
  - updateValidator
  - deleteValidator
  - RecordValidator
  - default validation
  - RecordValidationType
---

# Validation

Each table configured in `config/record.php` (or in per-table files under `config/record/tables`) can define event-specific validators using the `RecordTableType` configuration. Validators support:

- Callable arrays (e.g. `[ClassName::class, 'method']`)
- `RecordValidationType` objects
- Arrays of validator configs (multiple validators per event)

```php
use App\Record\Validators\RecordValidator;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;

return [
    'invoices' => new RecordTableType(
        pmsName: 'invoice',
        createValidator: [
            [RecordValidator::class, 'createInvoice'],
            new RecordValidationType(
                class: RecordValidator::class,
                functionName: 'createInvoice',
            ),
        ],
        updateValidator: [
            new RecordValidationType(
                class: RecordValidator::class,
                functionName: 'updateInvoice',
            ),
        ],
        deleteValidator: [
            [RecordValidator::class, 'deleteInvoice'],
        ],
    ),
];
```

If you run `php artisan config:cache`, avoid closures (including `fn (...) => ...`) in config files. Use callable arrays like `[ClassName::class, 'method']` (or a `'ClassName::method'` callable string).

Example validator class:

```php
<?php

declare(strict_types=1);

namespace App\Record\Validators;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class RecordValidator
{
    public static function createInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make($request->all(), [
            'invoice_number' => 'required|string|max:50',
            'customer_id' => 'required|integer',
            'total' => 'required|numeric|min:0',
        ]);
    }

    public static function updateInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make($request->all(), [
            'status' => 'sometimes|required|in:draft,pending,paid,cancelled',
        ]);
    }

    public static function deleteInvoice(Request $request, ?int $id = null): ValidatorContract
    {
        return Validator::make(['id' => $id], [
            'id' => 'required|integer',
        ]);
    }
}
```

- `createValidator` runs before `POST /{api_prefix}/{table}`.
- `updateValidator` runs before `PUT`/`PATCH /{api_prefix}/{table}/{id}`.
- `deleteValidator` runs before `DELETE /{api_prefix}/{table}/{id}`.

If a validator fails, the API returns a `422 Validation Error` with the standard error format described in the **Error Responses** section.

## Using PHP Attributes for Validators

Instead of passing arrays or closures directly in `config/record.php`, you can use the `#[RecordValidator]` attribute inside your validator or handler classes.

**1. Create the Validator Class:**

```php
namespace App\Record\User;

use Sopheak\Core\Attributes\RecordValidator;

class UserValidators
{
    #[RecordValidator('create')]
    public static function createRules(): array
    {
        return [
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ];
    }

    #[RecordValidator('update')]
    public static function updateRules(): array
    {
        return [
            'email' => 'sometimes|email',
        ];
    }
}
```

**2. Register the Class in `RecordTableType`:**

You can pass the class name as a string to the validator properties, or include it in the `triggers` array (which scans for both triggers and validators).

```php
use Sopheak\Core\Types\RecordTableType;
use App\Record\User\UserValidators;

return [
    'users' => new RecordTableType(
        // Option A: Explicitly assign the class
        createValidator: UserValidators::class,
        updateValidator: UserValidators::class,

        // Option B: Let the package auto-discover it via the triggers array
        // triggers: [UserValidators::class],
    ),
];
```

## Default Validation (Schema-Based)

You can enable automatic validation rules derived from table columns. This is optional and disabled by default.

```php
return [
    'default_validation' => [
        'enabled' => true,
        'only_when_missing' => true,
        'required' => true,
        'types' => true,
        'unique' => true,
        'foreign_keys' => true,
    ],
];
```

Rules generated:

- **required**: non-nullable columns without a default that the database does not generate (auto-increment, `nextval(...)` default or primary key), excluding system, write-disabled and tenant columns. The MCP schema tools mark exactly these columns `required` in create payloads.
- **types**: basic mapping (`uuid`, `integer`, `numeric`, `boolean`, `date`, `string`, `array`)
- **unique**: single-column unique indexes
- **foreign_keys**: `exists:{table},{column}` based on DB constraints

Notes:

- Only applies on **create** and **update** endpoints (including bulk create/update).
- When `only_when_missing` is `true`, table validators still take priority.
- Table validators and default validation run in the HTTP CRUD controller and for the MCP / AI SDK tools (`record.mcp.run_record_hooks`). Your own `RecordService::executeCreate/Update` calls skip them; they still reject payload fields that are neither a column nor a relationship.

## Related Docs

- [Configuration and Middleware](/guide/api-config-and-middleware)
- [Record Data Types](/guide/feature-record-data-types) — column types and their default-validation mapping
- [Record Hooks](/guide/record-hooks) — lifecycle triggers (run after authorization)
- [Error Responses](/guide/api-errors-rate-security)
