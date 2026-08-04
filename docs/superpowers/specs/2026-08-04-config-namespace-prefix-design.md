---
title: "Prefixing Package Config Namespaces with sp-"
description: "Design for renaming the five unprefixed package config files to sp-* names, with a bidirectional bridge that keeps the old names working indefinitely."
keywords:
  - config namespace
  - sp prefix
  - backward compatibility
  - deprecation
  - config bridge
  - mergeConfigFrom
date: 2026-08-04
status: approved
---

# Prefixing Package Config Namespaces with `sp-`

## Problem

Five of this package's seven config files use generic, unnamespaced names:

`record.php`, `permissions.php`, `audit.php`, `attachments.php`, `webhooks.php`

Nothing about those filenames says they belong to this package. A client
opening `config/` cannot tell which files are theirs, which came from
`vendor:publish`, or which package owns them. Names this generic also risk
colliding with the client's own configuration or with another package.

The remaining two are already correct: `sp-laravel-api.php` and
`sp-api-mcp.php`. Environment variables are already consistently `SP_*` — the
only exceptions, `DB_READ_CONNECTION` and `DB_WRITE_CONNECTION`, are
deliberately generic Laravel-style names and stay as they are.

So the gap is exactly five filenames.

## Why this is not a simple rename

Laravel derives a config namespace from the filename, so renaming the file
necessarily renames the namespace. `config/record.php` becomes
`config('sp-record.*')`. Every client who has published these files, and every
line of client code reading them, is affected.

The two groups of files break differently, and both failure modes are silent:

**`record.php` is publish-only.** `CoreSpLaravelApiProvider::register()` calls
`mergeConfigFrom` for six configs but never for `record.php`. The client's
published file *is* the entire config. Rename the namespace without a bridge
and `config('record.tables')` resolves to nothing: every table definition
disappears and the API 404s on everything.

**The other four are merged.** The package's own defaults would load under the
new name while the client's published customizations sat ignored under the old
one. Nothing errors. The application runs with package defaults silently
substituted for the client's settings, which is worse than a hard failure
because it can reach production unnoticed.

## Decision

Rename all five, and keep the old names working **indefinitely**, deprecated.
No client is ever required to act. A client who never migrates sees no
behavioral change; a client who migrates gets clean, obviously-ours filenames.

| Old | New |
|---|---|
| `record.php` | `sp-record.php` |
| `permissions.php` | `sp-permissions.php` |
| `audit.php` | `sp-audit.php` |
| `attachments.php` | `sp-attachments.php` |
| `webhooks.php` | `sp-webhooks.php` |

The `sp-` prefix (hyphen, not underscore) matches the existing
`sp-laravel-api.php` and `sp-api-mcp.php`.

## Amendment: the direction is inverted

The original draft made `sp-*` the internal namespace and rewrote every
`config()` call in `src/` to match. Planning found that unworkable, for a reason
worth recording.

### What the bootstrap order actually is

From `vendor/orchestra/testbench-core/src/Concerns/CreatesApplication.php`:

```
line 534   RegisterProviders::bootstrap()    <- providers' register() runs here
line 544   $this->getEnvironmentSetUp($app)  <- tests set config here
line 565   BootProviders::bootstrap()        <- providers' boot() runs here
```

A bridge in `register()` therefore runs **before** any test-supplied config. A
bridge in `boot()` runs after `getEnvironmentSetUp()` but still before the test
body and `setUp()`.

### Why that sinks the original design

`tests/TestCase.php` blanks `record.tables`, `attachments.tables` and
`webhooks.tables`, and across the suite there are **355 `config()->set()` calls
on these five namespaces in 63 files**. Only 11 files use `getEnvironmentSetUp`;
the rest set config in `setUp()` or the test body, both of which run after
`boot()`.

If the package read `sp-record.tables` while tests set `record.tables`, no
bridge placement rescues it. Every one of those 355 call sites would have to
change, and any client doing the same at runtime would break silently.

### The inversion

**Keep the existing namespaces internally canonical. Rename only the files.**

`mergeConfigFrom($path, $key)` takes the key independently of the path, so the
package can ship `config/sp-attachments.php` and still merge it under
`attachments`:

```php
$this->mergeConfigFrom(__DIR__ . '/../config/sp-attachments.php', 'attachments');
```

Package code continues reading `config('record.*')`, `config('attachments.*')`
and so on — unchanged. Tests are unchanged. The 164 call sites in `src/` are
unchanged.

What the client sees is the only thing that changes: `vendor:publish` now writes
`config/sp-record.php` instead of `config/record.php`, so their `config/`
directory says plainly which files belong to this package. That was the entire
goal.

## The bridge

Two passes, now much smaller.

### Pass A — adopt, in `register()`, before the merges

A client who publishes the new `sp-record.php` gets it under the `sp-record`
namespace, which the package does not read. Fold it into the canonical one:

```php
if ($config->has($new)) {
    $config->set($old, array_replace_recursive(
        $config->get($old, []),
        $config->get($new)
    ));
}
```

New wins over old, matching the both-files-exist rule below. A client who has
only the old file needs nothing — it already loaded under the canonical name.

### Pass B — mirror, in `boot()`, after the merges

Copy the resolved canonical namespace onto the new one, so a client who has
migrated can read `config('sp-record.tables')` in their own code:

```php
$config->set($new, $config->get($old));
```

`boot()` rather than `register()` so the mirror reflects anything
`getEnvironmentSetUp()` changed.

### Ordering is still load-bearing

Pass A must run before the `mergeConfigFrom` calls. `config/sp-permissions.php`
evaluates `RecordConfigService::idType()` at merge time, which reads
`record.id_type`. If a client's published `sp-record.php` has not yet been
folded into `record`, that read returns the default and the permissions tables
are declared with the wrong primary key type.

This is the same load-order property the `id_type` feature depends on:
`record.php` (and now `sp-record.php`) is loaded by Laravel's
`LoadConfiguration` bootstrapper before any provider registers, so its values
are available during `register()`.

### Known limitation

A runtime `config()->set('sp-record.x', …)` issued after `boot()` does not
propagate to the canonical `record.x`. Runtime overrides must use the canonical
name. This is documented rather than solved: the canonical name is what all
existing code and tests already use, so the limitation only affects code written
against the new name *and* mutating config at runtime, which nothing does today.

### When both files exist

The new namespace wins entirely. The old file is ignored, and the deprecation
notice reports that it was ignored rather than merged.

Deep-merging two files the client edited independently would produce a
resolved config that matches neither file, which is painful to debug. A client
mid-migration should see one file win predictably and be told which.

### Deprecation notice

One log line per boot, naming each old-named file found and its new name.

- A log line, not an exception: the whole point is that nothing breaks.
- Once per boot, not per request.
- Suppressible via a config flag for clients who have decided not to migrate.

## Package internals

**Nothing changes.** This is the point of the inversion.

The 164 `config('record.*')` / `config('attachments.*')` / … call sites in
`src/` keep reading the canonical namespaces. `RecordConfigService` accessors are
untouched — no new-then-old fallback is needed, because the canonical name never
moves. The 355 `config()->set()` calls across 63 test files keep working
verbatim.

The only code changes are in `CoreSpLaravelApiProvider`: the `mergeConfigFrom`
source paths, the `publishes` map, and the two bridge passes.

## Directory autoloading in `sp-record.php`

The published `sp-record.php` gains explicit loading of the table and
global-function directories:

```php
use Sopheak\Core\Support\RecordConfigLoader;

return [
    // ...
    'tables'           => RecordConfigLoader::tables(__DIR__ . '/records/tables'),
    'global_functions' => RecordConfigLoader::globalFunctions(__DIR__ . '/records/global-functions'),
    'autoloaded'       => true,
];
```

### Why a helper rather than an inline loop

`RecordConfigService::tableConfigFiles()` and `globalFunctionConfigFiles()`
already scan these directories at runtime. Inlining an equivalent loop into the
published config file would duplicate that logic into a file the package can
never patch again, and it would lose behavior the existing loader has:

- Global functions are grouped by source filename
  (`$group = pathinfo($path, PATHINFO_FILENAME)`), and non-string or empty
  function names are skipped. A plain `array_merge` drops both.
- Two directory spellings are supported for global functions —
  `records/globalFunctions` and `records/global-functions`. The helper keeps
  both.

`RecordConfigLoader` is the existing logic extracted to a public, memoized
(per resolved directory path) support class. The service delegates to it, so
there is one implementation.

### What this actually buys: `config:cache`

The point is not the scanning — it is where the scanning happens. Evaluated
inside the config file, `php artisan config:cache` bakes the resolved tables
into the cached payload and production performs no filesystem I/O. Today the
service re-scans on every `getTableConfig()` call, of which there are 14 sites,
with no memoization, regardless of whether config is cached.

### The `autoloaded` flag

`'autoloaded' => true` tells `RecordConfigService` the directories have already
been read, so it skips its runtime scan.

This is what keeps the change backward compatible. A client still on the old
`record.php` has no such key, so the service scans exactly as it does today.
A client on the new `sp-record.php` gets the cached fast path with no double
work. No client action is required either way.

### Closure caveat — must be documented

`RecordTableType` accepts a `Closure` for `createValidator`, `updateValidator`
and `deleteValidator`, and `global_functions` supports `'type' => 'closure'`.
Closures are not `var_export`-able, so once these values live in the config
file, `php artisan config:cache` fails for any client using one, with Laravel's
opaque *"Your configuration files are not serializable."*

This is inherent to putting values in a config file, not a flaw in the helper.
Today closures work precisely because the scanning happens at runtime.

Two documented escape hatches:

1. Use `[MyValidator::class, 'validate']` instead of a closure. The package
   already supports the callable-array form and it is better practice — it
   survives config caching and is testable in isolation.
2. Remove the `RecordConfigLoader` calls and the `autoloaded` flag from
   `sp-record.php`, reverting to runtime scanning.

The upgrade note must state this plainly, because the failure appears at deploy
time rather than in development.

## Deliberately unchanged

**`sp-record.php` stays publish-only.** Adding a `mergeConfigFrom` for it would
be defensible in isolation, but it changes behavior for clients who have no
`record.php` today, and it alters the load-order guarantee that Pass A and the
`id_type` feature both rely on. That is a separate decision, not a side effect
of a rename.

**`record.table_config_path`, default `'records/tables'`.** That directory holds
client-authored table config files. It is client-owned, not a package artifact,
so an `sp-` prefix buys no "this is ours" clarity and would break the path for
every existing install.

**Environment variables.** Already `SP_*` throughout.

## Testing

1. **No client config at all.** Package defaults load under the canonical
   namespaces, exactly as today.
2. **Client has only old-named files.** Nothing changes — they already load
   under the canonical names. This is the case that must be bit-for-bit
   identical to current behavior.
3. **Client has only new-named files.** Pass A folds `sp-record` into `record`;
   package code resolves it correctly.
4. **Client has both.** New wins on conflicting keys; the notice says the old
   file was superseded.
5. **The `id_type` ordering case.** A client with only `sp-record.php` setting
   `id_type` to `uuid` must produce uuid columns in the permissions table
   config — proving Pass A ran before the merges. This is the test most likely
   to catch a regression, since it depends on the exact ordering.
6. **Mirror reflects `getEnvironmentSetUp`.** A value set on the canonical name
   in `getEnvironmentSetUp()` is visible on the `sp-*` name after boot, proving
   Pass B runs in `boot()` and not `register()`.
7. **`config:cache` compatibility.** Both passes are skipped when config is
   cached; confirm the cached payload already holds both namespaces resolved,
   and that a cached app behaves identically to an uncached one.
8. **Deprecation notice** fires once per boot, names the right files, and is
   suppressible.
9. The existing 486-test suite passes **unchanged** — no test edits. If any test
   needs editing, the inversion has been implemented wrongly; that is the
   signal to stop and re-read this section.

### Autoloading tests

10. `RecordConfigLoader::tables()` returns the same array the service's runtime
    scan returns, for a directory containing both `RecordTableType`-returning
    files and array-returning files.
11. `RecordConfigLoader::globalFunctions()` preserves filename grouping and
    skips non-string and empty function names, matching current behavior. Both
    `records/globalFunctions` and `records/global-functions` resolve.
12. With `'autoloaded' => true`, `RecordConfigService` does not re-scan — assert
    by pointing it at a directory whose contents changed after load.
13. Without the flag (old `record.php`), the runtime scan still happens.
14. `php artisan config:cache` succeeds for a config with no closures, and the
    cached payload contains the resolved tables.
15. `config:cache` fails loudly for a config containing a closure validator.
    Assert the failure so the caveat stays documented by a test rather than only
    by prose.

## Files affected

- `config/record.php` → `config/sp-record.php`, and the same for
  `permissions`, `audit`, `attachments`, `webhooks` — `git mv`, contents
  otherwise unchanged except for the autoloading block in `sp-record.php`
- `src/Config/ConfigNamespaceBridge.php` — new; the two passes
- `src/Support/RecordConfigLoader.php` — new; the directory-scanning logic
  extracted from `RecordConfigService`, memoized per path
- `src/CoreSpLaravelApiProvider.php` — `mergeConfigFrom` source paths (keys
  unchanged), `publishes` map, and the two bridge invocations
- `src/Services/RecordConfigService.php` — delegate scanning to
  `RecordConfigLoader`; honor the `autoloaded` flag. **No accessor changes.**
- `src/Console/SetupPackageCommand.php` — emit the loader calls when scaffolding
- `docs/getting-started/upgrade-0.4.80-to-0.4.82.md` — replace the "Coming next"
  placeholder with the real section
- `docs/` — the `config:cache` closure caveat
- `CHANGELOG.md`

**Explicitly not affected:** the 164 `config()` call sites in `src/`, and all
355 `config()->set()` calls in `tests/`. If a change to either becomes
necessary, the design has drifted back toward the rejected direction.
