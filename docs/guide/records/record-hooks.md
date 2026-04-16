---
title: "Record Hooks (Lifecycle Trigger Guide)"
description: "Focused guide for RecordTableType lifecycle hooks including supported events, signatures, return behavior, registration patterns, execution order, and context usage."
keywords:
  - record hooks
  - lifecycle hooks
  - record trigger functions
  - RecordTableTriggerType
  - RecordTrigger attribute
  - beforeCreate
  - afterUpdate
  - global_triggers
---

# Record Hooks

This is the canonical guide for `RecordTableType` lifecycle hooks.

## Supported Hooks

- `beforeRead`
- `afterRead`
- `beforeCreate`
- `afterCreate`
- `beforeUpdate`
- `afterUpdate`
- `beforeDelete`
- `afterDelete`
- `beforeRestore`
- `afterRestore`

## Hook Signature

Use static methods with `Request`, `table`, and `context`:

```php
public static function beforeCreate(
    \Illuminate\Http\Request $request,
    string $table,
    array $context
): \Illuminate\Http\Request|array|void
```

## Return Behavior

- Return `Request`: replaces request for downstream flow.
- Return `array`: merged into request payload.
- Return `null`/`void`: no payload change.
- Throw exception: request aborts and returns API error response.

## Registration Pattern A: Direct Hook Mapping

```php
use Illuminate\Http\Request;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTableTriggerType;

new RecordTableType(
    table: 'users',
    beforeRead: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'beforeRead',
    ),
    afterRead: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'afterRead',
    ),
    beforeCreate: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'beforeCreate',
    ),
    afterCreate: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'afterCreate',
    ),
    beforeUpdate: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'beforeUpdate',
    ),
    afterUpdate: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'afterUpdate',
    ),
    beforeDelete: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'beforeDelete',
    ),
    afterDelete: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'afterDelete',
    ),
    beforeRestore: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'beforeRestore',
    ),
    afterRestore: new RecordTableTriggerType(
        class: \App\Record\Triggers\UserHooks::class,
        functionName: 'afterRestore',
    ),
)
```

All methods receive the same first parameters:

- `Request $request`
- `string $table`
- `array $context`

Recommended return style:

- `before*` hooks: `Request|array|void`
- `after*` hooks: `void`

Per-hook quick return matrix:

| Hook | Typical Return |
|------|----------------|
| `beforeRead` | `Request|void` |
| `afterRead` | `void` |
| `beforeCreate` | `Request|array|void` |
| `afterCreate` | `void` |
| `beforeUpdate` | `Request|array|void` |
| `afterUpdate` | `void` |
| `beforeDelete` | `Request|array|void` |
| `afterDelete` | `void` |
| `beforeRestore` | `Request|array|void` |
| `afterRestore` | `void` |

Full hook class example:

```php
namespace App\Record\Triggers;

use Illuminate\Http\Request;

final class UserHooks
{
    public static function beforeRead(Request $request, string $table, array $context): Request
    {
        // Return Request when you want to modify query params.
        return $request;
    }

    public static function afterRead(Request $request, string $table, array $context): void
    {
        // No return needed.
    }

    public static function beforeCreate(Request $request, string $table, array $context): array
    {
        // Return array to merge into payload.
        return ['created_by' => auth()->id()];
    }

    public static function afterCreate(Request $request, string $table, array $context): void
    {
        // No return needed.
    }

    public static function beforeUpdate(Request $request, string $table, array $context): array
    {
        // Return array to sanitize payload.
        $payload = $request->all();
        unset($payload['is_system']);
        return $payload;
    }

    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        // No return needed.
    }

    public static function beforeDelete(Request $request, string $table, array $context): void
    {
        // Throw/abort to block deletion.
    }

    public static function afterDelete(Request $request, string $table, array $context): void
    {
        // No return needed.
    }

    public static function beforeRestore(Request $request, string $table, array $context): void
    {
        // No return needed (or return Request/array if required).
    }

    public static function afterRestore(Request $request, string $table, array $context): void
    {
        // No return needed.
    }
}
```

## Registration Pattern B: Trigger Class Auto-discovery

```php
use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Types\RecordTableType;

final class UserTriggers
{
    #[RecordTrigger('beforeCreate', description: 'Normalize payload')]
    public static function normalize(Request $request, string $table, array $context): array
    {
        return ['name' => trim((string) $request->input('name'))];
    }

    #[RecordTrigger('afterCreate', description: 'Dispatch welcome event')]
    public static function afterCreate(Request $request, string $table, array $context): void
    {
        // dispatch event / job
    }
}

new RecordTableType(
    table: 'users',
    triggers: [
        UserTriggers::class,
    ],
)
```

## Registration Pattern C: Global Hooks

```php
// config/record.php
'global_triggers' => [
    'beforeCreate' => new RecordTableTriggerType(
        class: \App\Record\Triggers\GlobalTriggers::class,
        functionName: 'beforeCreate',
    ),
    'afterUpdate' => new RecordTableTriggerType(
        class: \App\Record\Triggers\GlobalTriggers::class,
        functionName: 'afterUpdate',
    ),
],
```

## Execution Order

- `before*`: global hook runs before table hook.
- `after*`: table hook runs before global hook.

## Context Quick Map

- `beforeRead` list: context includes `type=index`, `tenant_id`.
- `beforeCreate`: context includes `tenant_id`.
- `beforeUpdate`: context includes `id`, `tenant_id`.
- `beforeDelete`: context includes `id`, `tenant_id`, `record`.
- `afterDelete`: adds `soft_deleted` and `response`.
- `beforeRestore`/`afterRestore`: includes `id`, `tenant_id`, `record`, restore metadata.

## Best Practices

1. Keep `before*` hooks deterministic and side-effect light.
2. Put external side effects (notifications/webhooks) in `after*` hooks.
3. Prefer validators for user-facing `422` rule errors.
4. Use `request_context` for middleware-derived data (tenant, user, request_id).
5. Keep hook classes domain-scoped (`App\Record\{Domain}\*`) for maintainability.

## Related Docs

- [Configuration, Validation, and Triggers](/guide/api-config-validation-triggers)
- [Record Type Config Examples](/guide/feature-record-types-config-examples)
