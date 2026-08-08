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
     * @param string|null $directory Directory to scan. Defaults to the
     *                               application's config_path(). Overridable so
     *                               tests can point at a directory they own
     *                               rather than writing fixtures into the real
     *                               config directory.
     *
     * @return array<string, string> old filename => new filename
     */
    public static function deprecatedFiles(?string $directory = null): array
    {
        $directory ??= config_path();
        $found = [];

        foreach (self::RENAMES as $canonical => $published) {
            if (is_file($directory . '/' . $canonical . '.php')) {
                $found[$canonical . '.php'] = $published . '.php';
            }
        }

        return $found;
    }

    /**
     * Old-named config files whose `sp-*` counterpart is ALSO present.
     *
     * A subset of deprecatedFiles(). For these, adopt() has already folded the
     * `sp-*` file over the canonical namespace with array_replace_recursive,
     * so the new-named file wins every key both files set and the old file is
     * no longer fully in effect — the exact opposite of the "it keeps working"
     * promise that does hold for a lone old-named file. `sp-laravel-api:setup`
     * used to produce this state on every run, which is why it is worth
     * distinguishing rather than lumping in with the ordinary notice.
     *
     * @param string|null $directory Directory to scan. Defaults to the
     *                               application's config_path().
     *
     * @return array<string, string> old filename => new filename
     */
    public static function supersededFiles(?string $directory = null): array
    {
        $directory ??= config_path();

        return array_filter(
            self::deprecatedFiles($directory),
            static fn(string $new): bool => is_file($directory . '/' . $new)
        );
    }
}
