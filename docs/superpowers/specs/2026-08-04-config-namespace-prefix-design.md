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

## The bridge

A `ConfigNamespaceBridge` invoked at the top of
`CoreSpLaravelApiProvider::register()`, running in two passes around the
existing `mergeConfigFrom` calls.

### Pass A — adopt, before the merges

For each old/new pair: if the client has the old namespace and does **not**
have the new one, copy old to new.

```php
if ($config->has($old) && !$config->has($new)) {
    $config->set($new, $config->get($old));
}
```

After this pass, a client's published `record.php` *is* `sp-record`, and the
subsequent `mergeConfigFrom` calls layer package defaults underneath it with
their usual client-wins semantics.

### Pass B — mirror, after the merges

For each pair, copy the fully-resolved new namespace back onto the old one:

```php
$config->set($old, $config->get($new));
```

Both namespaces now hold the identical resolved array. Client code calling
`config('record.tables')` or `config('audit.enabled')` keeps working forever,
whether or not the client ever renames a file.

### Ordering is load-bearing

Pass A **must** run before the `mergeConfigFrom` calls. `config/sp-permissions.php`
evaluates `RecordConfigService::idType()` at merge time, which reads
`sp-record.id_type`. If the bridge has not yet adopted the client's
`record.php`, that read returns the default and the permissions tables are
declared with the wrong primary key type.

This is the same load-order property the `id_type` feature already depends on,
documented in `2026-08-04-configurable-id-type-design.md`: `record.php` is
loaded by Laravel's `LoadConfiguration` bootstrapper before any provider
registers, so its values are available during `register()`.

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

The roughly 175 `config('<old>.*')` call sites in `src/` are rewritten to the
`sp-*` names.

Additionally, `RecordConfigService` accessors read the new name with the old as
fallback:

```php
config('sp-record.id_type') ?? config('record.id_type') ?? 'integer'
```

The boot-time bridge cannot see runtime `config()->set()` calls, which both the
package's own test suite and some client code perform. The accessor fallback
covers that case; the bridge covers file-level config. Both are needed.

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

1. **No client config at all.** Package defaults load under `sp-*`. Old
   namespaces mirror correctly.
2. **Client has only old-named files.** Values are adopted into `sp-*`, package
   code reads them correctly, and `config('record.*')` still resolves.
3. **Client has only new-named files.** Works directly; old namespaces mirror.
4. **Client has both.** New wins; old is ignored; the notice says so.
5. **The `id_type` ordering case.** A client with only `record.php` setting
   `id_type` to `uuid` must produce uuid columns in `sp-permissions`' table
   config — proving Pass A ran before the merges.
6. **Runtime `config()->set()`** on an old name is still honored by
   `RecordConfigService` accessors.
7. **`config:cache` compatibility.** The bridge runs inside `register()`, which
   is skipped when config is cached; confirm the cached payload already holds
   both namespaces resolved.
8. **Deprecation notice** fires once per boot, names the right files, and is
   suppressible.
9. The existing 463-test suite passes unchanged.

## Files affected

- `config/record.php` → `config/sp-record.php` (and four siblings)
- `src/Config/ConfigNamespaceBridge.php` — new
- `src/CoreSpLaravelApiProvider.php` — invoke the bridge; update
  `mergeConfigFrom` and `publishes` paths
- `src/Services/RecordConfigService.php` — accessor fallbacks
- ~175 `config()` call sites across `src/`
- `docs/` — an upgrade note explaining that nothing is required
- `CHANGELOG.md`
