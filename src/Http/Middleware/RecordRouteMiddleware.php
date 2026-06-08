<?php

declare(strict_types=1);

namespace Sopheak\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Symfony\Component\HttpFoundation\Response;

class RecordRouteMiddleware
{
    public function handle(Request $request, Closure $next, string $action): mixed
    {
        $middlewares = $this->resolveMiddlewares($request, $action);
        if ([] === $middlewares) {
            return $next($request);
        }

        return app(Pipeline::class)
            ->send($request)
            ->through($middlewares)
            ->then(static fn(Request $pipedRequest): Response => $next($pipedRequest));
    }

    private function resolveMiddlewares(Request $request, string $action): array
    {
        $table = (string) ($request->route('table') ?? '');
        $map = RecordConfigService::middlewareMap();

        $globalMap = is_array($map['default'] ?? null) ? $map['default'] : [];
        $tableMap = [];
        if ('' !== $table) {
            $tableMaps = is_array($map['tables'] ?? null) ? $map['tables'] : [];
            $tableMap = is_array($tableMaps[$table] ?? null) ? $tableMaps[$table] : [];
        }

        // Per-function middleware: if set on the function, it replaces middleware_map entirely.
        // Only applies to function actions; all other actions fall through to the map below.
        if ('global_function' === $action || 'table_function' === $action) {
            $functionName = (string) ($request->route('functionName') ?? '');
            $functionType = $this->resolveFunctionConfig($action, $table, $functionName);
            if ($functionType instanceof RecordFunctionType && null !== $functionType->middleware) {
                return $this->sanitizeMiddlewares(
                    $this->normalizeMiddlewares($functionType->middleware)
                );
            }
        }

        $middlewares = array_merge(
            $this->resolveMapForAction($globalMap, $action),
            $this->resolveMapForAction($tableMap, $action)
        );

        return $this->sanitizeMiddlewares($middlewares);
    }

    /**
     * @param array<string, mixed> $map
     */
    private function resolveMapForAction(array $map, string $action): array
    {
        $group = $this->resolveActionGroup($action);

        return array_merge(
            $this->normalizeMiddlewares($map['*'] ?? []),
            $this->normalizeMiddlewares($map[$group] ?? []),
            $this->normalizeMiddlewares($map[$action] ?? [])
        );
    }

    private function resolveFunctionConfig(string $action, string $table, string $functionName): ?RecordFunctionType
    {
        if ('global_function' !== $action && 'table_function' !== $action) {
            return null;
        }

        // $table is unused for global_function. For table_function with empty $table,
        // getTable() returns null and the registry falls back to [], causing a map fallback.
        /** @var array<string, mixed> $registry */
        $registry = 'global_function' === $action
            ? RecordConfigService::globalFunctions()
            : (SchemaRegistryUtils::getTable($table)?->functions ?? []);

        $raw = null;

        // Step 1: exact match
        if (isset($registry[$functionName])) {
            $raw = $registry[$functionName];
        } else {
            // Step 2: pattern match — e.g. config key 'order/{id}' matches request value 'order/42'
            foreach ($registry as $configuredKey => $config) {
                $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', (string) $configuredKey);
                $pattern = '/^' . str_replace('/', '\/', $pattern) . '$/';

                if (preg_match($pattern, $functionName)) {
                    $raw = $config;
                    break;
                }
            }
        }

        if (null === $raw) {
            return null;
        }

        // Step 3: resolve to RecordFunctionType.
        // Most commonly $raw is already a RecordFunctionType instance (direct construction).
        if ($raw instanceof RecordFunctionType) {
            return $raw;
        }

        if (is_string($raw) && class_exists($raw)) {
            $instance = new $raw();
            if ($instance instanceof RecordFunctionInterface) {
                return $instance->toFunctionType();
            }

            if ($instance instanceof RecordFunctionType) {
                return $instance;
            }
        }

        // Plain-array configs fall through to middleware_map (not supported by this feature)
        return null;
    }

    private function resolveActionGroup(string $action): string
    {
        return match ($action) {
            'list', 'show' => 'read',
            'create', 'update', 'delete', 'restore', 'force_delete', 'upsert', 'bulk', 'bulk_create', 'bulk_update', 'bulk_delete', 'bulk_upsert' => 'write',
            'table_function', 'global_function' => 'function',
            default => 'misc',
        };
    }

    private function normalizeMiddlewares(mixed $middlewares): array
    {
        if (is_string($middlewares)) {
            $middlewares = [$middlewares];
        }

        if (!is_array($middlewares)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $middleware): string => is_string($middleware) ? trim($middleware) : '', $middlewares),
            static fn(string $middleware): bool => '' !== $middleware
        ));
    }

    private function sanitizeMiddlewares(array $middlewares): array
    {
        $result = [];
        /** @var Router $router */
        $router = app('router');
        $aliases = $router->getMiddleware();
        foreach ($middlewares as $middleware) {
            if (!is_string($middleware)) {
                continue;
            }

            [$name, $params] = array_pad(explode(':', $middleware, 2), 2, null);
            $resolved = $aliases[$name] ?? $name;
            if (is_string($params) && '' !== $params) {
                $resolved .= ':' . $params;
            }

            if (str_starts_with((string) $resolved, RecordRouteMiddleware::class)) {
                continue;
            }

            if (str_starts_with($middleware, 'record.route.middleware')) {
                continue;
            }

            $result[] = $resolved;
        }

        return array_values(array_unique($result));
    }
}
