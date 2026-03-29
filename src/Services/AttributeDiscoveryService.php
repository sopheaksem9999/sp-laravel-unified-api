<?php

namespace Sopheak\Core\Services;

use Generator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use SplFileInfo;
use ReflectionClass;
use ReflectionException;
use Sopheak\Core\Attributes\RecordRelationship;
use Sopheak\Core\Attributes\RecordTable;
use Sopheak\Core\Types\RecordTableType;
use Illuminate\Support\Str;

/**
 * Discovers Eloquent models annotated with #[RecordTable] and #[RecordRelationship]
 * and converts them to RecordTableType instances.
 *
 * File-based config (config/records/tables/*.php or config/record.php tables array)
 * always takes precedence over attribute-based config on conflict.
 *
 * Discovery is driven by the `sp-laravel-api.attribute_discovery.paths` config key,
 * which defaults to `['app/Models']`.
 */
class AttributeDiscoveryService
{
    /**
     * Discover all attribute-annotated models in the configured paths and return
     * a map of [tableKey => RecordTableType].
     *
     * @return array<string, RecordTableType>
     */
    public static function discover(): array
    {
        $paths = (array) config('sp-laravel-api.attribute_discovery.paths', ['app/Models']);
        $discovered = [];

        foreach ($paths as $path) {
            $absolutePath = str_starts_with((string) $path, '/') ? $path : base_path($path);
            if (!is_dir($absolutePath)) {
                continue;
            }

            foreach (self::phpFilesIn($absolutePath) as $file) {
                $class = self::classFromFile($file);
                if ($class === null) {
                    continue;
                }

                try {
                    $reflection = new ReflectionClass($class);
                } catch (ReflectionException) {
                    continue;
                }

                $tableAttr = self::getTableAttribute($reflection);
                if (!$tableAttr instanceof RecordTable) {
                    continue;
                }

                $tableKey = self::resolveTableKey($reflection, $tableAttr);
                $discovered[$tableKey] = self::buildTableType($reflection, $tableAttr, $tableKey);
            }
        }

        return $discovered;
    }

    /**
     * Recursively collect all .php files under a directory.
     *
     * @return Generator<string>
     */
    private static function phpFilesIn(string $directory): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getRealPath();
            }
        }
    }

    /**
     * Derive a fully-qualified class name from a file path.
     *
     * Uses `token_get_all` to extract the `namespace` and `class` declarations
     * without loading (eval-ing) the file.
     */
    private static function classFromFile(string $filePath): ?string
    {
        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = '';
        $class = '';

        $tokens = token_get_all($contents);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                // Collect namespace tokens
                $ns = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }

                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) {
                        $ns .= $tokens[$j][1];
                    }
                }

                $namespace = $ns;
            }

            if ($tokens[$i][0] === T_CLASS) {
                // Skip 'class' keyword that appears inside an expression (e.g. ::class)
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }

                if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                    continue;
                }

                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break;
                    }
                }

                break;
            }
        }

        if ($class === '') {
            return null;
        }

        $fqcn = $namespace !== '' ? $namespace . '\\' . $class : $class;

        if (!class_exists($fqcn)) {
            return null;
        }

        return $fqcn;
    }

    /**
     * Extract the first #[RecordTable] attribute from a reflection class, or null.
     */
    private static function getTableAttribute(ReflectionClass $reflection): ?RecordTable
    {
        $attrs = $reflection->getAttributes(RecordTable::class);
        if (empty($attrs)) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    /**
     * Derive the table registry key from the model.
     *
     * Priority: attribute->table → snake_plural of class name
     */
    private static function resolveTableKey(ReflectionClass $reflection, RecordTable $attr): string
    {
        if ($attr->table !== null) {
            return $attr->table;
        }

        return Str::snake(Str::pluralStudly($reflection->getShortName()));
    }

    /**
     * Build a RecordTableType from a ReflectionClass and its #[RecordTable] attribute.
     */
    private static function buildTableType(ReflectionClass $reflection, RecordTable $attr, string $tableKey): RecordTableType
    {
        $relationships = self::collectRelationships($reflection);

        $pmsName = $attr->pmsName ?? Str::snake($reflection->getShortName());

        return new RecordTableType(
            table: $attr->table ?? $tableKey,
            pmsName: $pmsName,
            hasTenantId: $attr->hasTenantId,
            softDeletes: $attr->softDeletes,
            disableAuditLog: $attr->disableAuditLog,
            disableCache: $attr->disableCache,
            disableBroadcast: $attr->disableBroadcast,
            canRead: $attr->canRead,
            canCreate: $attr->canCreate,
            canUpdate: $attr->canUpdate,
            canDelete: $attr->canDelete,
            canUpsert: $attr->canUpsert,
            isAuthRead: $attr->isAuthRead,
            isAuthWrite: $attr->isAuthWrite,
            primaryKey: $attr->primaryKey,
            relationships: $relationships,
        );
    }

    /**
     * Collect all #[RecordRelationship] attributes and convert them to the
     * array format expected by RecordTableType::$relationships.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function collectRelationships(ReflectionClass $reflection): array
    {
        $relationships = [];

        foreach ($reflection->getAttributes(RecordRelationship::class) as $attrRef) {
            /** @var RecordRelationship $rel */
            $rel = $attrRef->newInstance();

            $definition = ['type' => $rel->type];

            if ($rel->foreignKey !== null) {
                $definition['foreignKey'] = $rel->foreignKey;
            }

            if ($rel->relatedTable !== null) {
                $definition['table'] = $rel->relatedTable;
            }

            if ($rel->localKey !== null) {
                $definition['localKey'] = $rel->localKey;
            }

            if ($rel->ownerKey !== null) {
                $definition['ownerKey'] = $rel->ownerKey;
            }

            if ($rel->through !== null) {
                $definition['through'] = $rel->through;
            }

            if ($rel->throughForeignKey !== null) {
                $definition['throughForeignKey'] = $rel->throughForeignKey;
            }

            if ($rel->pivot !== null) {
                $definition['pivot'] = $rel->pivot;
            }

            if ($rel->pivotForeignKey !== null) {
                $definition['pivotForeignKey'] = $rel->pivotForeignKey;
            }

            if ($rel->pivotRelatedKey !== null) {
                $definition['pivotRelatedKey'] = $rel->pivotRelatedKey;
            }

            $relationships[$rel->name] = $definition;
        }

        return $relationships;
    }
}
