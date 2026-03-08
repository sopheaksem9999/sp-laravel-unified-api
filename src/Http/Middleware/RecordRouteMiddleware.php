<?php

namespace Sopheak\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Sopheak\Core\Services\RecordConfigService;
use Symfony\Component\HttpFoundation\Response;

class RecordRouteMiddleware
{
    public function handle(Request $request, Closure $next, string $action): Response
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

        $middlewares = array_merge(
            $this->resolveMapForAction($globalMap, $action),
            $this->resolveMapForAction($tableMap, $action)
        );

        return $this->sanitizeMiddlewares($middlewares);
    }

    private function resolveMapForAction(array $map, string $action): array
    {
        $group = $this->resolveActionGroup($action);

        return array_merge(
            $this->normalizeMiddlewares($map['*'] ?? []),
            $this->normalizeMiddlewares($map[$group] ?? []),
            $this->normalizeMiddlewares($map[$action] ?? [])
        );
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
