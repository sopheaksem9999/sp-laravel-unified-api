---
title: "Config Namespace Prefix Implementation Plan"
description: "Five-task plan to rename the package's five unprefixed config files to sp-* names without changing any internal namespace, plus directory autoloading for sp-record.php."
keywords:
  - config namespace
  - sp prefix
  - backward compatibility
  - config bridge
  - RecordConfigLoader
  - implementation plan
date: 2026-08-04
---

# Config Namespace Prefix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the package's five unprefixed config files to `sp-*` so a client can tell at a glance which files in `config/` belong to this package, without breaking a single existing install.

**Architecture:** The internal config namespaces (`record`, `attachments`, `audit`, `permissions`, `webhooks`) stay canonical and unchanged. Only the shipped and published **filenames** change. `mergeConfigFrom($path, $key)` accepts its key independently of its path, so the package ships `config/sp-attachments.php` and still merges it under `attachments`. A two-pass `ConfigNamespaceBridge` folds a client's published `sp-*` file into the canonical namespace before the merges, and mirrors the resolved values back in `boot()` so migrated clients can read the new name.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Orchestra Testbench, PHPUnit 10/11, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-08-04-config-namespace-prefix-design.md`

## Global Constraints

- **The 486-test suite must pass with zero test edits.** If a task requires editing an existing test's config calls, the inversion has been implemented wrongly — stop and re-read the spec's "Amendment" section. This is the single most important signal in the plan.
- **Zero changes to the 164 `config('record.*' | 'attachments.*' | 'audit.*' | 'permissions.*' | 'webhooks.*')` call sites in `src/`.** They keep reading the canonical namespaces.
- `RecordConfigService` accessors get **no** new-then-old fallback. The canonical name never moves.
- A client who never renames anything sees no behavioral change, ever.
- `declare(strict_types=1);` at the top of every new PHP file.
- Verify with `vendor/bin/phpunit`, `composer analyse`, `composer docs:validate`. Do **not** gate on `composer format-check` — it reports 7 pre-existing unrelated files; only fix files you touch.

### Bootstrap ordering — read before writing any test

From `vendor/orchestra/testbench-core/src/Concerns/CreatesApplication.php`:

```text
line 534   RegisterProviders::bootstrap()    <- providers' register()  : bridge Pass A
line 544   $this->getEnvironmentSetUp($app)  <- tests set config here
line 565   BootProviders::bootstrap()        <- providers' boot()      : bridge Pass B
```

Pass A cannot see test config. Pass B can see `getEnvironmentSetUp()` but not `setUp()` or the test body. This is exactly why the canonical namespace must not move.

---

## File Structure

**Create:**
- `src/Support/RecordConfigLoader.php` — directory scanning, extracted from `RecordConfigService`, memoized per resolved path
- `src/Config/ConfigNamespaceBridge.php` — the two passes plus the deprecation notice
- `tests/Unit/RecordConfigLoaderTest.php`
- `tests/Feature/ConfigNamespaceBridgeTest.php`

**Rename (`git mv`, contents otherwise unchanged):**
- `config/record.php` → `config/sp-record.php`
- `config/permissions.php` → `config/sp-permissions.php`
- `config/audit.php` → `config/sp-audit.php`
- `config/attachments.php` → `config/sp-attachments.php`
- `config/webhooks.php` → `config/sp-webhooks.php`

**Modify:**
- `src/CoreSpLaravelApiProvider.php` — `mergeConfigFrom` source paths (keys unchanged), `publishes` map, both bridge invocations
- `src/Services/RecordConfigService.php` — delegate scanning to `RecordConfigLoader`, honor `autoloaded`
- `config/sp-record.php` — autoloading block
- `docs/getting-started/upgrade-0.4.80-to-0.4.82.md`, `docs/guide/features/feature-record-data-types.md`, `CHANGELOG.md`

---

## Task 1: `RecordConfigLoader`

Extract the directory scanning so both the config file and the service share one implementation. Pure refactor — no behavior change.

**Files:**
- Create: `src/Support/RecordConfigLoader.php`
- Modify: `src/Services/RecordConfigService.php`
- Test: `tests/Unit/RecordConfigLoaderTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `RecordConfigLoader::tables(string $directory): array`
  - `RecordConfigLoader::globalFunctions(string ...$directories): array`
  - `RecordConfigLoader::flush(): void` (test hygiene — clears the memo)

  Used by Tasks 3 and 4.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RecordConfigLoaderTest.php`. These assertions encode the semantics that must not drift — especially the group prefixing, which a naive `array_merge` would lose.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Support\RecordConfigLoader;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class RecordConfigLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        RecordConfigLoader::flush();
        $this->dir = sys_get_temp_dir() . '/rcl-' . getmypid();
        $this->cleanup();
        mkdir($this->dir . '/tables', 0777, true);
        mkdir($this->dir . '/global-functions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        RecordConfigLoader::flush();
        parent::tearDown();
    }

    /** @test */
    public function it_keys_a_record_table_type_file_by_its_filename(): void
    {
        file_put_contents(
            $this->dir . '/tables/widgets.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "widgets");'
        );

        $tables = RecordConfigLoader::tables($this->dir . '/tables');

        $this->assertArrayHasKey('widgets', $tables);
        $this->assertInstanceOf(RecordTableType::class, $tables['widgets']);
    }

    /** @test */
    public function it_merges_array_returning_files_by_their_own_keys(): void
    {
        file_put_contents(
            $this->dir . '/tables/bundle.php',
            '<?php return ["alpha" => new \Sopheak\Core\Types\RecordTableType(table: "alpha"), '
            . '"beta" => new \Sopheak\Core\Types\RecordTableType(table: "beta")];'
        );

        $tables = RecordConfigLoader::tables($this->dir . '/tables');

        $this->assertArrayHasKey('alpha', $tables);
        $this->assertArrayHasKey('beta', $tables);
        $this->assertArrayNotHasKey('bundle', $tables);
    }

    /** @test */
    public function it_returns_an_empty_array_for_a_missing_directory(): void
    {
        $this->assertSame([], RecordConfigLoader::tables($this->dir . '/nope'));
    }

    /** @test */
    public function it_prefixes_global_function_names_with_their_file_group(): void
    {
        file_put_contents(
            $this->dir . '/global-functions/auth.php',
            '<?php return ["login" => ["type" => "closure"]];'
        );

        $functions = RecordConfigLoader::globalFunctions($this->dir . '/global-functions');

        $this->assertArrayHasKey('auth/login', $functions);
        $this->assertArrayNotHasKey('login', $functions);
    }

    /** @test */
    public function it_leaves_an_already_pathed_function_name_unprefixed(): void
    {
        file_put_contents(
            $this->dir . '/global-functions/auth.php',
            '<?php return ["media/upload" => ["type" => "closure"]];'
        );

        $functions = RecordConfigLoader::globalFunctions($this->dir . '/global-functions');

        $this->assertArrayHasKey('media/upload', $functions);
        $this->assertArrayNotHasKey('auth/media/upload', $functions);
    }

    /** @test */
    public function it_skips_non_string_and_empty_function_names(): void
    {
        file_put_contents(
            $this->dir . '/global-functions/auth.php',
            '<?php return [0 => ["type" => "closure"], "" => ["type" => "closure"], "ok" => ["type" => "closure"]];'
        );

        $functions = RecordConfigLoader::globalFunctions($this->dir . '/global-functions');

        $this->assertSame(['auth/ok'], array_keys($functions));
    }

    /** @test */
    public function it_scans_every_directory_it_is_given(): void
    {
        mkdir($this->dir . '/globalFunctions', 0777, true);
        file_put_contents($this->dir . '/globalFunctions/a.php', '<?php return ["one" => []];');
        file_put_contents($this->dir . '/global-functions/b.php', '<?php return ["two" => []];');

        $functions = RecordConfigLoader::globalFunctions(
            $this->dir . '/globalFunctions',
            $this->dir . '/global-functions'
        );

        $this->assertArrayHasKey('a/one', $functions);
        $this->assertArrayHasKey('b/two', $functions);
    }

    /** @test */
    public function it_memoizes_by_directory(): void
    {
        file_put_contents(
            $this->dir . '/tables/widgets.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "widgets");'
        );
        RecordConfigLoader::tables($this->dir . '/tables');

        file_put_contents(
            $this->dir . '/tables/gadgets.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "gadgets");'
        );

        $this->assertArrayNotHasKey(
            'gadgets',
            RecordConfigLoader::tables($this->dir . '/tables'),
            'a second call must be served from the memo'
        );
        $this->assertArrayHasKey(
            'gadgets',
            (function (): array {
                RecordConfigLoader::flush();
                return RecordConfigLoader::tables($this->dir . '/tables');
            })()
        );
    }

    private function cleanup(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/RecordConfigLoaderTest.php`

Expected: FAIL with `Class "Sopheak\Core\Support\RecordConfigLoader" not found`.

- [ ] **Step 3: Create the loader**

Create `src/Support/RecordConfigLoader.php`. The bodies are lifted from
`RecordConfigService::tableConfigFiles()`, `globalFunctionConfigFiles()` and
`phpFilesInDirectory()` — copy the logic exactly, including the `sort()` and the
group-prefixing rules.

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sopheak\Core\Types\RecordTableType;

/**
 * Loads record table and global-function configs from directories.
 *
 * Extracted from RecordConfigService so the published config file and the
 * runtime service share one implementation. Evaluated inside a config file it
 * lets `php artisan config:cache` bake the result in; called from the service
 * it preserves the historical runtime-scanning behavior for clients who have
 * not re-published.
 *
 * Memoized per resolved directory path so the two callers do not double-scan.
 */
class RecordConfigLoader
{
    /** @var array<string, array<string, mixed>> */
    private static array $tableMemo = [];

    /** @var array<string, array<string, mixed>> */
    private static array $functionMemo = [];

    /**
     * @return array<string, mixed>
     */
    public static function tables(string $directory): array
    {
        $key = self::key($directory);

        if (array_key_exists($key, self::$tableMemo)) {
            return self::$tableMemo[$key];
        }

        $tables = [];

        if (is_dir($directory)) {
            foreach (self::phpFilesIn($directory) as $path) {
                $config = require $path;

                if ($config instanceof RecordTableType) {
                    $tables[pathinfo($path, PATHINFO_FILENAME)] = $config;
                    continue;
                }

                if (is_array($config)) {
                    $tables = array_merge($tables, $config);
                }
            }
        }

        return self::$tableMemo[$key] = $tables;
    }

    /**
     * Function names are prefixed with their file's basename unless the name
     * already contains a slash, so `auth.php` returning `login` yields
     * `auth/login` while `media/upload` is left alone.
     *
     * @return array<string, mixed>
     */
    public static function globalFunctions(string ...$directories): array
    {
        $key = self::key(implode('|', $directories));

        if (array_key_exists($key, self::$functionMemo)) {
            return self::$functionMemo[$key];
        }

        $functions = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (self::phpFilesIn($directory) as $path) {
                $config = require $path;

                if (!is_array($config)) {
                    continue;
                }

                $group = pathinfo($path, PATHINFO_FILENAME);

                foreach ($config as $functionName => $functionConfig) {
                    if (!is_string($functionName) || $functionName === '') {
                        continue;
                    }

                    $normalized = ltrim($functionName, '/');
                    $prefixed = str_contains($normalized, '/')
                        ? $normalized
                        : $group . '/' . $normalized;

                    $functions[$prefixed] = $functionConfig;
                }
            }
        }

        return self::$functionMemo[$key] = $functions;
    }

    /**
     * Clear the memo. Tests only.
     */
    public static function flush(): void
    {
        self::$tableMemo = [];
        self::$functionMemo = [];
    }

    /**
     * @return string[]
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    private static function key(string $raw): string
    {
        return $raw;
    }
}
```

- [ ] **Step 4: Delegate from the service**

In `src/Services/RecordConfigService.php`, replace the bodies of
`tableConfigFiles()` (line ~338) and `globalFunctionConfigFiles()` (line ~364)
with delegations, keeping the methods and their visibility so nothing else
changes:

```php
    private static function tableConfigFiles(): array
    {
        return RecordConfigLoader::tables(config_path(self::tableConfigPath()));
    }

    private static function globalFunctionConfigFiles(): array
    {
        return RecordConfigLoader::globalFunctions(...self::globalFunctionConfigDirectories());
    }
```

Add `use Sopheak\Core\Support\RecordConfigLoader;` to the imports. Delete
`phpFilesInDirectory()` if nothing else calls it — grep first. Keep
`globalFunctionConfigDirectories()`; it is now the caller's source of paths.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/RecordConfigLoaderTest.php`
Expected: PASS, 8 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 486 + 8 = 494. **Zero test edits.** A pure refactor that changes an existing test's expectations is not a pure refactor — stop and investigate.

- [ ] **Step 6: Commit**

```bash
git add src/Support/RecordConfigLoader.php src/Services/RecordConfigService.php tests/Unit/RecordConfigLoaderTest.php
git commit -m "refactor: extract directory scanning into RecordConfigLoader"
```

---

## Task 2: `ConfigNamespaceBridge`

The two passes, in isolation, before anything is renamed. Testable on its own.

**Files:**
- Create: `src/Config/ConfigNamespaceBridge.php`
- Test: `tests/Feature/ConfigNamespaceBridgeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ConfigNamespaceBridge::RENAMES` — `array<string, string>` mapping canonical name to published name
  - `ConfigNamespaceBridge::adopt(Repository $config): void` — Pass A
  - `ConfigNamespaceBridge::mirror(Repository $config): void` — Pass B
  - `ConfigNamespaceBridge::deprecatedFiles(): array` — old-named files present on disk

  Used by Task 3.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ConfigNamespaceBridgeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Config\Repository;
use Sopheak\Core\Config\ConfigNamespaceBridge;
use Sopheak\Core\Tests\TestCase;

class ConfigNamespaceBridgeTest extends TestCase
{
    /** @test */
    public function adopt_folds_a_published_new_file_into_the_canonical_namespace(): void
    {
        $config = new Repository(['sp-record' => ['id_type' => 'uuid']]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertSame('uuid', $config->get('record.id_type'));
    }

    /** @test */
    public function adopt_lets_the_new_file_win_over_the_old_one(): void
    {
        $config = new Repository([
            'record' => ['id_type' => 'integer', 'api_prefix' => 'api/v1'],
            'sp-record' => ['id_type' => 'uuid'],
        ]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertSame('uuid', $config->get('record.id_type'));
        $this->assertSame(
            'api/v1',
            $config->get('record.api_prefix'),
            'keys absent from the new file must survive from the old one'
        );
    }

    /** @test */
    public function adopt_leaves_the_canonical_namespace_alone_when_no_new_file_exists(): void
    {
        $config = new Repository(['record' => ['id_type' => 'integer']]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertSame(['id_type' => 'integer'], $config->get('record'));
    }

    /** @test */
    public function adopt_merges_nested_keys_rather_than_replacing_them(): void
    {
        $config = new Repository([
            'record' => ['cache' => ['enabled' => false, 'ttl' => 3600]],
            'sp-record' => ['cache' => ['enabled' => true]],
        ]);

        ConfigNamespaceBridge::adopt($config);

        $this->assertTrue($config->get('record.cache.enabled'));
        $this->assertSame(3600, $config->get('record.cache.ttl'));
    }

    /** @test */
    public function mirror_copies_the_resolved_canonical_namespace_to_the_new_name(): void
    {
        $config = new Repository(['record' => ['id_type' => 'uuid', 'tables' => ['a' => 1]]]);

        ConfigNamespaceBridge::mirror($config);

        $this->assertSame('uuid', $config->get('sp-record.id_type'));
        $this->assertSame(['a' => 1], $config->get('sp-record.tables'));
    }

    /** @test */
    public function mirror_covers_every_renamed_namespace(): void
    {
        $config = new Repository();
        foreach (ConfigNamespaceBridge::RENAMES as $canonical => $published) {
            $config->set($canonical, ['marker' => $canonical]);
        }

        ConfigNamespaceBridge::mirror($config);

        foreach (ConfigNamespaceBridge::RENAMES as $canonical => $published) {
            $this->assertSame(
                $canonical,
                $config->get($published . '.marker'),
                "{$published} must mirror {$canonical}"
            );
        }
    }

    /** @test */
    public function the_rename_map_covers_exactly_the_five_unprefixed_configs(): void
    {
        $this->assertSame(
            [
                'record' => 'sp-record',
                'permissions' => 'sp-permissions',
                'audit' => 'sp-audit',
                'attachments' => 'sp-attachments',
                'webhooks' => 'sp-webhooks',
            ],
            ConfigNamespaceBridge::RENAMES
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ConfigNamespaceBridgeTest.php`

Expected: FAIL with `Class "Sopheak\Core\Config\ConfigNamespaceBridge" not found`.

- [ ] **Step 3: Implement the bridge**

Create `src/Config/ConfigNamespaceBridge.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Config;

use Illuminate\Contracts\Config\Repository;

/**
 * Bridges the package's published config filenames to its canonical namespaces.
 *
 * The package's own config files are named sp-*.php so a client can see at a
 * glance which files in config/ belong to this package. The namespaces those
 * files feed are deliberately NOT renamed: mergeConfigFrom() takes its key
 * independently of its path, and the entire codebase plus every test already
 * reads the canonical names.
 *
 * Two passes:
 *  - adopt()  runs in register(), before the merges. Folds a client's published
 *             sp-*.php into the canonical namespace so the rest of the boot
 *             sequence — including config files that read config() at merge
 *             time — sees it.
 *  - mirror() runs in boot(), after the merges and after Testbench's
 *             getEnvironmentSetUp(). Copies the resolved canonical values onto
 *             the sp-* name so a migrated client can read either.
 */
class ConfigNamespaceBridge
{
    /**
     * Canonical namespace => published filename/namespace.
     *
     * @var array<string, string>
     */
    public const RENAMES = [
        'record' => 'sp-record',
        'permissions' => 'sp-permissions',
        'audit' => 'sp-audit',
        'attachments' => 'sp-attachments',
        'webhooks' => 'sp-webhooks',
    ];

    /**
     * Pass A. Must run before mergeConfigFrom().
     */
    public static function adopt(Repository $config): void
    {
        foreach (self::RENAMES as $canonical => $published) {
            if (!$config->has($published)) {
                continue;
            }

            $new = (array) $config->get($published);
            $old = (array) $config->get($canonical, []);

            $config->set($canonical, array_replace_recursive($old, $new));
        }
    }

    /**
     * Pass B. Must run in boot(), not register().
     */
    public static function mirror(Repository $config): void
    {
        foreach (self::RENAMES as $canonical => $published) {
            if (!$config->has($canonical)) {
                continue;
            }

            $config->set($published, $config->get($canonical));
        }
    }

    /**
     * Old-named config files still present in the client's config directory.
     *
     * Detected by file existence rather than by namespace, because the package
     * merges its own defaults into the canonical namespaces regardless — so
     * `$config->has('attachments')` is always true and says nothing about which
     * file the client published.
     *
     * @return array<string, string> old filename => new filename
     */
    public static function deprecatedFiles(): array
    {
        $found = [];

        foreach (self::RENAMES as $canonical => $published) {
            if (is_file(config_path($canonical . '.php'))) {
                $found[$canonical . '.php'] = $published . '.php';
            }
        }

        return $found;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Feature/ConfigNamespaceBridgeTest.php`
Expected: PASS, 7 tests.

Run: `vendor/bin/phpunit`
Expected: PASS, 494 + 7 = 501. Zero test edits — the bridge is not wired in yet.

- [ ] **Step 5: Commit**

```bash
git add src/Config/ConfigNamespaceBridge.php tests/Feature/ConfigNamespaceBridgeTest.php
git commit -m "feat: add ConfigNamespaceBridge for sp-* published config filenames"
```

---

## Task 3: Rename the files and wire the provider

The load-bearing task. Renames are mechanical; the ordering is not.

**Files:**
- Rename: the five `config/*.php` files
- Modify: `src/CoreSpLaravelApiProvider.php`
- Test: extend `tests/Feature/ConfigNamespaceBridgeTest.php`

**Interfaces:**
- Consumes: `ConfigNamespaceBridge::adopt/mirror/deprecatedFiles` from Task 2.
- Produces: no new PHP interfaces. Establishes the shipped filenames.

- [ ] **Step 1: Rename the five files**

```bash
git mv config/record.php config/sp-record.php
git mv config/permissions.php config/sp-permissions.php
git mv config/audit.php config/sp-audit.php
git mv config/attachments.php config/sp-attachments.php
git mv config/webhooks.php config/sp-webhooks.php
```

Do not edit their contents in this task.

- [ ] **Step 2: Run the suite to see what breaks**

Run: `vendor/bin/phpunit`

Expected: **many failures** — the provider still points at the old paths, so
`mergeConfigFrom` gets missing files. This is the expected intermediate state.
Record the failure count; Step 4 must return it to green.

- [ ] **Step 3: Wire the provider**

In `src/CoreSpLaravelApiProvider.php`, add the import:

```php
use Sopheak\Core\Config\ConfigNamespaceBridge;
```

Replace the body of `register()`'s merge block. **Pass A must be the first
statement**, before any `mergeConfigFrom` — `config/sp-permissions.php`
evaluates `RecordConfigService::idType()` at merge time, which reads
`record.id_type`:

```php
    public function register(): void
    {
        // Pass A: fold a client's published sp-*.php into the canonical
        // namespaces BEFORE the merges below, because sp-permissions.php reads
        // config('record.id_type') while it is being merged.
        ConfigNamespaceBridge::adopt($this->app['config']);

        $this->mergeConfigFrom(__DIR__ . '/../config/sp-laravel-api.php', 'sp-laravel-api');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-attachments.php', 'attachments');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-webhooks.php', 'webhooks');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-audit.php', 'audit');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-permissions.php', 'permissions');
        $this->mergeConfigFrom(__DIR__ . '/../config/sp-api-mcp.php', 'sp-api-mcp');

        $this->app->singleton('api.response', fn(): RecordApiResponseService => new RecordApiResponseService());
        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(QueryCacheService::class);
    }
```

Note the second argument of each `mergeConfigFrom` is **unchanged** — only the
paths move.

Then in `boot()`, update the publishes map and add Pass B as the first
statement:

```php
    public function boot(): void
    {
        // Pass B: mirror the resolved canonical values onto the sp-* names so a
        // migrated client can read either. In boot() rather than register() so
        // it reflects anything Testbench's getEnvironmentSetUp() changed.
        ConfigNamespaceBridge::mirror($this->app['config']);

        $this->publishes([
            __DIR__ . '/../config/sp-laravel-api.php' => config_path('sp-laravel-api.php'),
            __DIR__ . '/../config/sp-audit.php' => config_path('sp-audit.php'),
            __DIR__ . '/../config/sp-record.php' => config_path('sp-record.php'),
            __DIR__ . '/../config/sp-attachments.php' => config_path('sp-attachments.php'),
            __DIR__ . '/../config/sp-webhooks.php' => config_path('sp-webhooks.php'),
            __DIR__ . '/../config/sp-permissions.php' => config_path('sp-permissions.php'),
            __DIR__ . '/../config/sp-api-mcp.php' => config_path('sp-api-mcp.php'),
        ], 'sp-laravel-api-config');
```

Leave the rest of `boot()` untouched.

- [ ] **Step 4: Run the suite back to green**

Run: `vendor/bin/phpunit`

Expected: PASS, 501. **Zero test edits.** If a test needs its config calls
changed, the canonical namespace has moved somewhere it should not have — find
where and fix the source, not the test.

- [ ] **Step 5: Add the integration tests**

Append to `tests/Feature/ConfigNamespaceBridgeTest.php`:

```php
    /** @test */
    public function the_canonical_namespace_still_carries_package_defaults(): void
    {
        $this->assertIsArray(config('attachments.tables'));
        $this->assertNotNull(config('attachments.disk_public'));
    }

    /** @test */
    public function the_published_name_mirrors_the_canonical_one_after_boot(): void
    {
        $this->assertSame(config('record.api_prefix'), config('sp-record.api_prefix'));
        $this->assertSame(config('audit.enabled'), config('sp-audit.enabled'));
    }

    /** @test */
    public function a_value_set_in_environment_setup_reaches_the_mirrored_name(): void
    {
        // record.api_prefix is set to 'api' by TestCase::getEnvironmentSetUp,
        // which runs after register() and before boot(). Seeing it on the
        // mirrored name proves Pass B runs in boot(), not register().
        $this->assertSame('api', config('sp-record.api_prefix'));
    }
```

- [ ] **Step 6: Wire the deprecation notice**

`ConfigNamespaceBridge::deprecatedFiles()` exists but nothing calls it. Add to
`boot()`, immediately after the `mirror()` call:

```php
        foreach (ConfigNamespaceBridge::deprecatedFiles() as $old => $new) {
            if ((bool) config('sp-laravel-api.suppress_config_rename_notice', false)) {
                break;
            }

            Log::info(sprintf(
                'sp-laravel-api: config/%s is deprecated; rename it to config/%s. '
                . 'It keeps working — set sp-laravel-api.suppress_config_rename_notice to silence this.',
                $old,
                $new
            ));
        }
```

Add `use Illuminate\Support\Facades\Log;` if absent. Add the suppression key to
`config/sp-laravel-api.php` with a comment and a default of `false`.

Once per boot, not per request: `boot()` runs once per application instance.

- [ ] **Step 7: Test the notice**

Append to `tests/Feature/ConfigNamespaceBridgeTest.php`:

```php
    /** @test */
    public function no_notice_is_emitted_when_no_old_named_file_exists(): void
    {
        // Testbench's config_path() has no published files, so this asserts the
        // common case: a client with nothing to migrate gets no log noise.
        $this->assertSame([], ConfigNamespaceBridge::deprecatedFiles());
    }

    /** @test */
    public function deprecated_files_reports_old_names_present_on_disk(): void
    {
        $path = config_path('record.php');
        $created = false;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        if (!file_exists($path)) {
            file_put_contents($path, '<?php return [];');
            $created = true;
        }

        try {
            $this->assertSame(['record.php' => 'sp-record.php'], ConfigNamespaceBridge::deprecatedFiles());
        } finally {
            if ($created) {
                unlink($path);
            }
        }
    }
```

- [ ] **Step 8: Verify the ordering test actually detects the ordering**

Temporarily move the `ConfigNamespaceBridge::mirror(...)` call from `boot()` to
the end of `register()`. Run
`vendor/bin/phpunit tests/Feature/ConfigNamespaceBridgeTest.php`.

Expected: `a_value_set_in_environment_setup_reaches_the_mirrored_name` FAILS.

Move it back, confirm green, and report both outcomes. A test that passes with
the call in either place is not testing the ordering.

- [ ] **Step 9: Full verification**

Run: `vendor/bin/phpunit` — 506, all green.
Run: `composer analyse` — clean.

- [ ] **Step 8: Commit**

```bash
git add config/ src/CoreSpLaravelApiProvider.php tests/Feature/ConfigNamespaceBridgeTest.php
git commit -m "feat: ship package config files under sp-* names

The namespaces they feed are unchanged: mergeConfigFrom takes its key
independently of its path, so config/sp-attachments.php still merges under
'attachments'. No src/ call site and no test changes."
```

---

## Task 4: Directory autoloading in `sp-record.php`

**Files:**
- Modify: `config/sp-record.php`, `src/Services/RecordConfigService.php`
- Test: `tests/Feature/RecordConfigAutoloadedTest.php`

**Interfaces:**
- Consumes: `RecordConfigLoader::tables/globalFunctions` from Task 1.
- Produces: the `record.autoloaded` config key.

- [ ] **Step 1: Add the loader calls**

At the top of `config/sp-record.php`, after the opening `<?php`:

```php
use Sopheak\Core\Support\RecordConfigLoader;
```

Replace the existing `'tables' => [...]` and `'global_functions' => [...]`
entries with:

```php
    // Scanned here rather than at runtime so `php artisan config:cache` bakes
    // the result into the cached payload and production does no filesystem
    // scanning. `autoloaded` tells RecordConfigService to skip its own scan.
    //
    // NOTE: values reachable from here must be var_export()-able. A Closure
    // validator or a 'type' => 'closure' global function will make
    // `php artisan config:cache` fail. Use [MyValidator::class, 'method'] instead.
    'autoloaded' => true,
    'tables' => RecordConfigLoader::tables(__DIR__ . '/records/tables'),
    'global_functions' => RecordConfigLoader::globalFunctions(
        __DIR__ . '/records/globalFunctions',
        __DIR__ . '/records/global-functions',
    ),
```

Both global-function directory spellings are passed, matching
`RecordConfigService::globalFunctionConfigDirectories()`.

- [ ] **Step 2: Honor the flag in the service**

In `src/Services/RecordConfigService.php`, guard both scans:

```php
    private static function tableConfigFiles(): array
    {
        if ((bool) config('record.autoloaded', false)) {
            return [];
        }

        return RecordConfigLoader::tables(config_path(self::tableConfigPath()));
    }

    private static function globalFunctionConfigFiles(): array
    {
        if ((bool) config('record.autoloaded', false)) {
            return [];
        }

        return RecordConfigLoader::globalFunctions(...self::globalFunctionConfigDirectories());
    }
```

A client still on `record.php` has no `autoloaded` key, so the runtime scan
happens exactly as before.

- [ ] **Step 3: Write the test**

Create `tests/Feature/RecordConfigAutoloadedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Support\RecordConfigLoader;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class RecordConfigAutoloadedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordConfigLoader::flush();
    }

    /** @test */
    public function the_runtime_scan_runs_when_the_flag_is_absent(): void
    {
        config()->set('record.autoloaded', null);
        config()->set('record.tables', ['declared' => new RecordTableType(table: 'declared')]);

        $tables = RecordConfigService::getTableConfig();

        $this->assertArrayHasKey('declared', $tables);
    }

    /** @test */
    public function declared_tables_still_resolve_when_the_flag_is_set(): void
    {
        config()->set('record.autoloaded', true);
        config()->set('record.tables', ['declared' => new RecordTableType(table: 'declared')]);

        $tables = RecordConfigService::getTableConfig();

        $this->assertArrayHasKey(
            'declared',
            $tables,
            'the flag must skip only the directory scan, never config-declared tables'
        );
    }
}
```

- [ ] **Step 4: Test serializability, the caveat that bites at deploy time**

The whole point of `autoloaded` is `config:cache` support, and the whole risk is
that a `Closure` breaks it. Both need a test, because the failure appears at
deploy time rather than in development.

Append to `tests/Feature/RecordConfigAutoloadedTest.php`:

```php
    /** @test */
    public function a_loaded_table_config_survives_var_export(): void
    {
        $tables = ['widgets' => new RecordTableType(table: 'widgets', primaryKey: 'id')];

        $exported = var_export($tables, true);
        $restored = eval('return ' . $exported . ';');

        $this->assertInstanceOf(RecordTableType::class, $restored['widgets']);
        $this->assertSame('widgets', $restored['widgets']->table);
    }

    /** @test */
    public function a_closure_validator_cannot_be_config_cached(): void
    {
        // config:cache calls var_export() on the whole config array. A Closure
        // has no var_export representation, so Laravel fails with "Your
        // configuration files are not serializable." This test pins the caveat
        // documented in feature-record-data-types.md so it cannot quietly
        // become untrue.
        $table = new RecordTableType(
            table: 'widgets',
            createValidator: static fn (): array => ['name' => 'required'],
        );

        $this->expectException(\Throwable::class);

        var_export(['widgets' => $table], true);
    }
```

If `var_export()` on a Closure emits a warning rather than throwing on this PHP
version, assert on the warning instead — but do assert something. Report which
behavior you observed.

- [ ] **Step 5: Run and verify**

Run: `vendor/bin/phpunit tests/Feature/RecordConfigAutoloadedTest.php` — PASS, 4 tests.
Run: `vendor/bin/phpunit` — PASS, 510. Zero test edits.
Run: `composer analyse` — clean.

- [ ] **Step 6: Commit**

```bash
git add config/sp-record.php src/Services/RecordConfigService.php tests/Feature/RecordConfigAutoloadedTest.php
git commit -m "feat: autoload record table and global-function directories in sp-record.php"
```

---

## Task 5: Documentation

**Files:**
- Modify: `docs/getting-started/upgrade-0.4.80-to-0.4.82.md`,
  `docs/guide/features/feature-record-data-types.md`, `CHANGELOG.md`

- [ ] **Step 1: Replace the upgrade guide's placeholder**

`docs/getting-started/upgrade-0.4.80-to-0.4.82.md` ends with a "Coming next"
section describing this change as unshipped. Replace it with a real section
covering: the five renamed files; that no action is required because the old
filenames keep working; that `config('record.tables')` in client code is
unaffected because the namespace never moved; and how to migrate (publish the
new files, copy customizations across, delete the old ones).

- [ ] **Step 2: Document the `config:cache` closure caveat**

In `docs/guide/features/feature-record-data-types.md`, state that with
`autoloaded` enabled, table and global-function configs are evaluated inside the
config file, so `php artisan config:cache` must serialize them — and a `Closure`
validator or `'type' => 'closure'` global function makes it fail with
*"Your configuration files are not serializable."* Give both escape hatches:
use `[MyValidator::class, 'validate']`, or remove the loader calls and the
`autoloaded` flag.

- [ ] **Step 3: CHANGELOG**

Add a `### Changed` entry for the renames stating plainly that nothing breaks
and no action is required, and an `### Added` entry for the `autoloaded`
directory scanning with the `config:cache` caveat.

- [ ] **Step 4: Verify**

Run: `composer docs:validate` — must pass. Use site-absolute extensionless
links (`/guide/feature-record-data-types`); relative and `.md` links are
rejected.

Run: `vendor/bin/phpunit` — still 510. A docs-only task must not change the count.

- [ ] **Step 5: Commit**

```bash
git add docs/ CHANGELOG.md
git commit -m "docs: document the sp-* config filenames and directory autoloading"
```

---

## Verification Checklist

Paste actual output — do not assert from memory.

- [ ] `vendor/bin/phpunit` — 510 green (486 baseline + 8 + 7 + 5 + 4)
- [ ] `git diff --stat <base>..HEAD -- tests/` shows **only added files**, no modifications to existing tests
- [ ] `git diff <base>..HEAD -- src/ | grep -cE "^[-+].*config\('(record|attachments|audit|permissions|webhooks)\."` returns **0** — no canonical call site was rewritten
- [ ] `composer analyse` — clean
- [ ] `composer docs:validate` — clean
- [ ] `composer format-check` — still exactly 7, none from this branch
- [ ] `ls config/` shows all seven files `sp-` prefixed
- [ ] Pass B ordering was seen to fail when moved to `register()` (Task 3 Step 6)

## Known Limitations

A runtime `config()->set('sp-record.x', …)` after `boot()` does not propagate to
the canonical `record.x`. Runtime overrides must use the canonical name. Nothing
in the package or its tests does otherwise; this only affects client code
written against the new name *and* mutating config at runtime.

`config:cache` with a `Closure` validator fails once `autoloaded` is on. This is
inherent to evaluating configs inside a config file and is documented in Task 5
rather than solved.
