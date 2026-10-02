<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Guidance;

/**
 * Thirteen actions of one endpoint repeat the same record schema; each
 * repetition cost an agent about 500 tokens. This keeps the first occurrence
 * of an identical, sizeable subtree inline and turns every later occurrence
 * into `{"$ref": "#/json/pointer"}` pointing at it — the standard JSON
 * reference form, resolved against the same response.
 */
final class SchemaDeduper
{
    /** Schema subtrees smaller than this are cheaper inline than as a reference. */
    private const MIN_BYTES = 120;

    /** An operator list is worth a reference once it outgrows the reference itself. */
    private const MIN_OPERATOR_BYTES = 50;

    /**
     * @param array<string, array<string, mixed>> $actions
     * @return array<string, array<string, mixed>>
     */
    public static function actions(array $actions): array
    {
        $seen = [];

        foreach ($actions as $name => $action) {
            foreach ([['request', 'payload'], ['request', 'payload', 'items'], ['response', 'dataSchema'], ['response', 'dataSchema', 'items']] as $path) {
                $node = self::get($action, $path);
                if (!is_array($node)) {
                    continue;
                }

                if (isset($node['$ref'])) {
                    continue;
                }

                $key = (string) json_encode($node);
                $pointer = '#/actions/' . $name . '/' . implode('/', $path);
                if (strlen($key) < self::MIN_BYTES) {
                    continue;
                }

                if (isset($seen[$key])) {
                    $action = self::set($action, $path, ['$ref' => $seen[$key]]);
                } else {
                    $seen[$key] = $pointer;
                }
            }

            $actions[$name] = $action;
        }

        return $actions;
    }

    /**
     * Fields of the same type family list identical operators; the first
     * entry spells them out and the rest point at it.
     *
     * @param array<int, array{field: string, operators: array<int, string>}> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function filters(array $filters): array
    {
        $seen = [];
        foreach ($filters as $index => $filter) {
            $key = (string) json_encode($filter['operators']);
            if (strlen($key) < self::MIN_OPERATOR_BYTES) {
                continue;
            }

            if (isset($seen[$key])) {
                $filters[$index]['operators'] = ['$ref' => $seen[$key]];
            } else {
                $seen[$key] = '#/filters/' . $index . '/operators';
            }
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<int, string> $path
     */
    private static function get(array $tree, array $path): mixed
    {
        foreach ($path as $segment) {
            if (!is_array($tree) || !array_key_exists($segment, $tree)) {
                return null;
            }

            $tree = $tree[$segment];
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<int, string> $path
     * @return array<string, mixed>
     */
    private static function set(array $tree, array $path, mixed $value): array
    {
        $head = array_shift($path);
        $tree[$head] = [] === $path ? $value : self::set((array) $tree[$head], $path, $value);

        return $tree;
    }
}
