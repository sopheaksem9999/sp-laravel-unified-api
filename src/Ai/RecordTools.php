<?php

declare(strict_types=1);

namespace Sopheak\Core\Ai;

use Laravel\Ai\Contracts\Tool;
use InvalidArgumentException;
use RuntimeException;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Laravel AI SDK tools for the package's tables, for an agent's `tools()`:
 *
 *     return [
 *         ...RecordTools::readOnly(['invoices', 'customers']),
 *         ...RecordTools::for('invoice_items')->only(['create', 'update']),
 *     ];
 *
 * They run through the same ToolExecutor as the MCP servers, so an agent gets
 * exactly the tenant isolation, permissions, viewOwn scoping, hidden-column
 * stripping and nested-write authorization an HTTP or MCP caller gets.
 *
 * Needs laravel/ai (PHP 8.3+); nothing in Sopheak\Core\Ai loads without it.
 */
final class RecordTools
{
    /** Function-name rule of the providers laravel/ai targets (laravel/ai itself enforces none). */
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * list, read, create, update and delete tools for the given tables. Actions
     * a table does not allow (its canRead/canCreate/canUpdate/canDelete flags)
     * are left out.
     *
     * @param string|list<string> $tables
     */
    public static function for(string|array $tables): RecordToolSet
    {
        self::assertInstalled();

        return RecordToolSet::of(self::definitionsFor((array) $tables));
    }

    /**
     * list and read tools only.
     *
     * @param string|list<string> $tables
     */
    public static function readOnly(string|array $tables): RecordToolSet
    {
        return self::for($tables)->only(['list', 'read']);
    }

    /**
     * The four schema-discovery tools (sp_api_*): API metadata, never records.
     */
    public static function schema(): RecordToolSet
    {
        self::assertInstalled();

        $definitions = (new ToolCatalog())->schema();
        array_map(self::assertName(...), $definitions);

        return RecordToolSet::of($definitions);
    }

    /**
     * @param null|callable(string): bool $interfaceExists
     */
    public static function assertInstalled(?callable $interfaceExists = null): void
    {
        $interfaceExists ??= interface_exists(...);

        if (!$interfaceExists(Tool::class)) {
            throw new RuntimeException('The AI SDK record tools need laravel/ai (PHP 8.3+). Run: composer require laravel/ai');
        }
    }

    /**
     * @param list<string> $tables
     * @return list<ToolDefinition>
     */
    private static function definitionsFor(array $tables): array
    {
        if ([] === $tables) {
            throw new InvalidArgumentException('Name at least one table.');
        }

        SchemaRegistryUtils::refresh();
        $registered = array_keys(array_filter(SchemaRegistryUtils::get(), static fn(mixed $config): bool => $config instanceof RecordTableType));

        foreach ($tables as $table) {
            if (!in_array($table, $registered, true)) {
                throw new InvalidArgumentException(sprintf("Unknown table '%s'. Registered tables: %s.", $table, implode(', ', $registered)));
            }
        }

        $catalog = (new ToolCatalog())->data(readOnly: false);
        $definitions = [];
        foreach ($tables as $table) {
            foreach ($catalog as $definition) {
                if ($definition->table === $table) {
                    self::assertName($definition);
                    $definitions[] = $definition;
                }
            }
        }

        return $definitions;
    }

    private static function assertName(ToolDefinition $definition): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $definition->name)) {
            throw new InvalidArgumentException(sprintf(
                "Tool name '%s'%s is not usable by AI providers: names must be 1-64 characters of letters, digits, '_' or '-'.",
                $definition->name,
                null === $definition->table ? '' : sprintf(" (table '%s')", $definition->table),
            ));
        }
    }
}
