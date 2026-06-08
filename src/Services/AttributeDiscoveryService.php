<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Generator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use SplFileInfo;
use ReflectionClass;
use ReflectionException;
use Sopheak\Core\Attributes\RecordFunction;
use Sopheak\Core\Attributes\RecordGlobalFunction;
use Sopheak\Core\Attributes\RecordRelationship;
use Sopheak\Core\Attributes\RecordTable;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableTriggerType;
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
        $discovered = [];
        $reflections = self::discoverClasses();

        // 1. Discover full tables
        foreach ($reflections as $reflection) {
            $tableAttr = self::getTableAttribute($reflection);
            if (!$tableAttr instanceof RecordTable) {
                continue;
            }

            $tableKey = self::resolveTableKey($reflection, $tableAttr);
            $discovered[$tableKey] = self::buildTableType($reflection, $tableAttr, $tableKey);
        }

        // 2. Discover standalone table functions (where table is specified in the attribute)
        foreach ($reflections as $reflection) {
            if (self::getTableAttribute($reflection) instanceof RecordTable) {
                continue; // Already processed above
            }

            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(RecordFunction::class) as $attrRef) {
                    /** @var RecordFunction $functionAttr */
                    $functionAttr = $attrRef->newInstance();
                    
                    if ($functionAttr->table === null) {
                        continue;
                    }

                    $tableKey = $functionAttr->table;
                    if (!isset($discovered[$tableKey])) {
                        $discovered[$tableKey] = new RecordTableType(table: $tableKey);
                    }

                    if (!is_array($discovered[$tableKey]->functions)) {
                        $discovered[$tableKey]->functions = [];
                    }

                    $name = $functionAttr->name ?? $method->getName();

                    $discovered[$tableKey]->functions[$name] = self::buildFunctionType(
                        className: $reflection->getName(),
                        methodName: $method->getName(),
                        name: $name,
                        httpMethod: $functionAttr->httpMethod,
                        isPublic: $functionAttr->isPublic,
                        pmsName: $functionAttr->pmsName,
                        disableCache: $functionAttr->disableCache,
                        cacheTTL: $functionAttr->cacheTTL,
                        description: $functionAttr->description,
                        querySchema: $functionAttr->querySchema,
                        payloadSchema: $functionAttr->payloadSchema,
                        responseSchema: $functionAttr->responseSchema,
                        clearCacheTables: $functionAttr->clearCacheTables,
                        middleware: $functionAttr->middleware,
                    );
                }
            }
        }

        return $discovered;
    }

    /**
     * Discover global functions declared via #[RecordGlobalFunction] attributes.
     *
     * @return array<string, RecordFunctionType>
     */
    public static function discoverGlobalFunctions(): array
    {
        $functions = [];

        foreach (self::discoverClasses() as $reflection) {
            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(RecordGlobalFunction::class) as $attrRef) {
                    /** @var RecordGlobalFunction $functionAttr */
                    $functionAttr = $attrRef->newInstance();
                    $name = $functionAttr->name ?? $method->getName();
                    $functions[$name] = self::buildFunctionType(
                        className: $reflection->getName(),
                        methodName: $method->getName(),
                        name: $name,
                        httpMethod: $functionAttr->httpMethod,
                        isPublic: $functionAttr->isPublic,
                        pmsName: $functionAttr->pmsName,
                        disableCache: $functionAttr->disableCache,
                        cacheTTL: $functionAttr->cacheTTL,
                        description: $functionAttr->description,
                        querySchema: $functionAttr->querySchema,
                        payloadSchema: $functionAttr->payloadSchema,
                        responseSchema: $functionAttr->responseSchema,
                        clearCacheTables: $functionAttr->clearCacheTables,
                        middleware: $functionAttr->middleware,
                    );
                }
            }
        }

        return $functions;
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
     * Discover reflection classes from configured attribute discovery paths.
     *
     * @return array<ReflectionClass>
     */
    private static function discoverClasses(): array
    {
        $paths = (array) config('sp-laravel-api.attribute_discovery.paths', ['app/Models']);
        $classes = [];

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
                    $classes[$class] = new ReflectionClass($class);
                } catch (ReflectionException) {
                    continue;
                }
            }
        }

        return array_values($classes);
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
        $triggers = self::collectTriggers($reflection);
        $functions = self::collectTableFunctions($reflection);

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
            functions: $functions,
            beforeRead: $triggers['beforeRead'] ?? null,
            afterRead: $triggers['afterRead'] ?? null,
            beforeCreate: $triggers['beforeCreate'] ?? null,
            afterCreate: $triggers['afterCreate'] ?? null,
            beforeUpdate: $triggers['beforeUpdate'] ?? null,
            afterUpdate: $triggers['afterUpdate'] ?? null,
            beforeDelete: $triggers['beforeDelete'] ?? null,
            afterDelete: $triggers['afterDelete'] ?? null,
            beforeRestore: $triggers['beforeRestore'] ?? null,
            afterRestore: $triggers['afterRestore'] ?? null,
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

    /**
     * Collect all #[RecordFunction] attributes from class methods and map to table functions.
     *
     * @return array<string, RecordFunctionType>
     */
    private static function collectTableFunctions(ReflectionClass $reflection): array
    {
        $functions = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(RecordFunction::class) as $attrRef) {
                /** @var RecordFunction $functionAttr */
                $functionAttr = $attrRef->newInstance();

                $name = $functionAttr->name ?? $method->getName();

                $functions[$name] = self::buildFunctionType(
                    className: $reflection->getName(),
                    methodName: $method->getName(),
                    name: $name,
                    httpMethod: $functionAttr->httpMethod,
                    isPublic: $functionAttr->isPublic,
                    pmsName: $functionAttr->pmsName,
                    disableCache: $functionAttr->disableCache,
                    cacheTTL: $functionAttr->cacheTTL,
                    description: $functionAttr->description,
                    querySchema: $functionAttr->querySchema,
                    payloadSchema: $functionAttr->payloadSchema,
                    responseSchema: $functionAttr->responseSchema,
                    clearCacheTables: $functionAttr->clearCacheTables,
                    middleware: $functionAttr->middleware,
                );
            }
        }

        return $functions;
    }

    /**
     * Build a RecordFunctionType from discovered function metadata.
     */
    private static function buildFunctionType(
        string $className,
        string $methodName,
        string $name,
        array|string $httpMethod,
        bool $isPublic,
        array|string|null $pmsName,
        bool $disableCache,
        ?int $cacheTTL,
        ?string $description,
        ?array $querySchema,
        ?array $payloadSchema,
        ?array $responseSchema,
        array|string|null $clearCacheTables,
        array|string|null $middleware
    ): RecordFunctionType {
        return new RecordFunctionType(
            httpMethod: $httpMethod,
            class: $className,
            functionName: $methodName,
            isPublic: $isPublic,
            pmsName: $pmsName,
            disableCache: $disableCache,
            cacheTTL: $cacheTTL,
            description: $description ?? sprintf('Attribute function: %s', $name),
            querySchema: $querySchema,
            payloadSchema: $payloadSchema,
            responseSchema: $responseSchema,
            clearCacheTables: $clearCacheTables,
            middleware: $middleware,
        );
    }

    /**
     * Collect all #[RecordTrigger] attributes from the class methods and group them by hook.
     *
     * @return array<string, array<RecordTableTriggerType>>
     */
    private static function collectTriggers(ReflectionClass $reflection): array
    {
        $triggers = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(RecordTrigger::class) as $attrRef) {
                /** @var RecordTrigger $triggerAttr */
                $triggerAttr = $attrRef->newInstance();

                $hook = $triggerAttr->hook;

                if (!isset($triggers[$hook])) {
                    $triggers[$hook] = [];
                }

                $triggers[$hook][] = new RecordTableTriggerType(
                    class: $reflection->getName(),
                    functionName: $method->getName(),
                    description: $triggerAttr->description
                );
            }
        }

        return $triggers;
    }
}
