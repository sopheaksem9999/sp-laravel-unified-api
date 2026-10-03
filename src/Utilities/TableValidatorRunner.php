<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Sopheak\Core\Types\RecordValidationType;

/**
 * Runs a table's validators (`createValidator`, `updateValidator`,
 * `deleteValidator`): a callable, a RecordValidationType, or a list of them.
 * Shared by the CRUD controller and the MCP / AI SDK tools so both validate a
 * write the same way.
 */
final class TableValidatorRunner
{
    /**
     * The first of a table's validators (createValidator / updateValidator /
     * deleteValidator) that fails for $request, or null when all pass.
     */
    public static function firstFailure(mixed $validatorConfig, Request $request, ?string $id): ?ValidatorContract
    {
        $validators = self::resolveValidatorConfigs($validatorConfig);

        foreach ($validators as $index => $validatorItem) {
            if (is_callable($validatorItem)) {
                $validator = self::invokeValidatorCallable($validatorItem, $request, $id);
                if ($validator->fails()) {
                    return $validator;
                }

                continue;
            }

            $config = self::resolveValidationType($validatorItem, $index);
            $validator = self::invokeValidationType($config, $request, $id);
            if ($validator->fails()) {
                return $validator;
            }
        }

        return null;
    }

    private static function resolveValidatorConfigs(mixed $validatorConfig): array
    {
        if (null === $validatorConfig) {
            return [];
        }

        if ($validatorConfig instanceof RecordValidationType || is_callable($validatorConfig)) {
            return [$validatorConfig];
        }

        if (is_array($validatorConfig)) {
            if (is_callable($validatorConfig)) {
                return [$validatorConfig];
            }

            if (self::isValidatorConfigArray($validatorConfig)) {
                return [$validatorConfig];
            }

            return self::flattenValidatorConfigs($validatorConfig);
        }

        throw new RuntimeException(sprintf(
            'Invalid validator configuration. Expected callable, %s, or array, got %s',
            RecordValidationType::class,
            get_debug_type($validatorConfig)
        ));
    }

    private static function flattenValidatorConfigs(array $items): array
    {
        $resolved = [];

        foreach ($items as $item) {
            if (null === $item) {
                continue;
            }

            if ($item instanceof RecordValidationType || is_callable($item)) {
                $resolved[] = $item;
                continue;
            }

            if (is_array($item)) {
                if (is_callable($item) || self::isValidatorConfigArray($item)) {
                    $resolved[] = $item;
                    continue;
                }

                $resolved = array_merge($resolved, self::flattenValidatorConfigs($item));
                continue;
            }

            throw new RuntimeException(sprintf(
                'Invalid validator configuration. Expected callable, %s, or array, got %s',
                RecordValidationType::class,
                get_debug_type($item)
            ));
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isValidatorConfigArray(array $config): bool
    {
        return isset($config['class']) || isset($config['functionName']);
    }

    private static function invokeValidatorCallable(callable $callback, Request $request, ?string $id): ValidatorContract
    {
        $validator = $callback($request, self::normalizeValidatorIdForCallable($callback, $id));
        if (!$validator instanceof ValidatorContract) {
            throw new RuntimeException('Validator callback must return a Validator instance');
        }

        return $validator;
    }

    private static function resolveValidationType(mixed $item, int|string $index): RecordValidationType
    {
        if ($item instanceof RecordValidationType) {
            return $item;
        }

        if (is_array($item) && self::isValidatorConfigArray($item)) {
            return RecordValidationType::fromArray($item);
        }

        throw new RuntimeException(sprintf(
            'Invalid validator item at index %s. Expected callable, %s, or array, got %s',
            (string) $index,
            RecordValidationType::class,
            get_debug_type($item)
        ));
    }

    private static function invokeValidationType(RecordValidationType $config, Request $request, ?string $id): ValidatorContract
    {
        $className = $config->class;
        $method = $config->functionName;

        if (!class_exists($className)) {
            throw new RuntimeException(sprintf("Validator class '%s' does not exist", $className));
        }

        if (!method_exists($className, $method)) {
            throw new RuntimeException(sprintf("Validator method '%s::%s' does not exist", $className, $method));
        }

        $callback = [$className, $method];
        $validator = call_user_func($callback, $request, self::normalizeValidatorIdForCallable($callback, $id));
        if (!$validator instanceof ValidatorContract) {
            throw new RuntimeException('Validator callback must return a Validator instance');
        }

        return $validator;
    }

    private static function normalizeValidatorIdForCallable(callable $callback, ?string $id): mixed
    {
        if (null === $id) {
            return null;
        }

        $reflection = self::reflectCallable($callback);
        if (!$reflection instanceof ReflectionFunctionAbstract) {
            return $id;
        }

        $parameter = $reflection->getParameters()[1] ?? null;
        if (null === $parameter) {
            return $id;
        }

        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $id;
        }

        return match ($type->getName()) {
            'int' => ctype_digit($id) ? (int) $id : $id,
            'float' => is_numeric($id) ? (float) $id : $id,
            'string' => $id,
            default => $id,
        };
    }

    private static function reflectCallable(callable $callback): ?ReflectionFunctionAbstract
    {
        if ($callback instanceof Closure || is_string($callback)) {
            return new ReflectionFunction($callback);
        }

        if (is_array($callback) && isset($callback[0], $callback[1])) {
            return new ReflectionMethod($callback[0], (string) $callback[1]);
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return new ReflectionMethod($callback, '__invoke');
        }

        return null;
    }
}
