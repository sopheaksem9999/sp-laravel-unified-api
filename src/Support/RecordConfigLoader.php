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
 *
 * Caching contract: each directory's file scan runs at most once per PHP
 * process — the result is memoized for the remaining lifetime of that
 * process, not re-read on every call. `flush()` clears the memo, and
 * `SchemaRegistryUtils::refresh()` / `clearAllCache()` call it as part of
 * their existing cache-invalidation sweep, which runs between tests and
 * whenever config-affecting state changes at runtime. Under a
 * short-lived worker model (classic PHP-FPM, one request per process) this
 * is invisible: the process that reads the file also dies with it, so a scan
 * is already effectively "once per request." Under a long-lived worker
 * (Laravel Octane, queue workers) a file added to, removed from, or edited in
 * one of these directories is **not** picked up mid-process — it takes a
 * worker restart (or an explicit `RecordConfigLoader::flush()` /
 * `SchemaRegistryUtils::refresh()`) for the change to be seen.
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
                    if (!is_string($functionName)) {
                        continue;
                    }

                    if ($functionName === '') {
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
            if (!$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
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
