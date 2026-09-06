<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Interfaces\AuditLogFilterInterface;
use Throwable;

/** Stateless admission policy; never retains request data or filter instances. */
class AuditLogFilterService
{
    /**
     * Validate cache-safe configuration without constructing or invoking a filter.
     */
    public static function configurationError(mixed $filter): ?string
    {
        if ($filter === null) {
            return null;
        }

        if (is_string($filter)) {
            return is_a($filter, AuditLogFilterInterface::class, true)
                && (class_exists($filter) || ($filter === AuditLogFilterInterface::class && app()->bound($filter)))
                ? null : 'invalid_filter_class';
        }

        if (!is_array($filter) || !array_is_list($filter) || count($filter) !== 2
            || !is_string($filter[0]) || !is_string($filter[1])) {
            return 'invalid_filter_configuration';
        }

        if (!class_exists($filter[0]) || !method_exists($filter[0], $filter[1])) {
            return 'invalid_filter_method';
        }

        $method = new ReflectionMethod($filter[0], $filter[1]);
        return $method->isPublic() && !$method->isAbstract() ? null : 'invalid_filter_method';
    }

    /**
     * @param array<string, mixed> $auditData
     * @param array<string, mixed> $context
     */
    public function shouldLog(AuditLogEventEnum $event, string $table, array $auditData, ?Authenticatable $user, array $context = []): bool
    {
        $filter = config('audit.filter');
        if ($filter === null) {
            return true;
        }

        try {
            $error = self::configurationError($filter);
            if ($error !== null) {
                return $this->retain($error, $filter);
            }

            if (is_string($filter)) {
                $instance = app($filter);
                if (!$instance instanceof AuditLogFilterInterface) {
                    return $this->retain('invalid_filter_instance', $filter);
                }

                $result = $instance->shouldLog($event, $table, $auditData, $user, $context);
            } else {
                [$class, $method] = $filter;
                $target = (new ReflectionMethod($class, $method))->isStatic() ? $class : app($class);
                $result = call_user_func([$target, $method], $event, $table, $auditData, $user, $context);
            }

            return is_bool($result) ? $result : $this->retain('invalid_filter_result', $filter);
        } catch (Throwable) {
            return $this->retain('filter_exception', $filter);
        }
    }

    private function retain(string $reason, mixed $filter): bool
    {
        $identifier = is_string($filter) ? $filter : (is_array($filter) && count($filter) === 2
            && is_string($filter[0] ?? null) && is_string($filter[1] ?? null) ? implode('::', $filter) : 'invalid');
        // Never include arbitrary config values, callback errors, or payloads in diagnostics.
        if (!preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\:]*$/D', $identifier)) {
            $identifier = 'invalid';
        }

        try {
            Log::warning('sp-laravel-api: audit filter retained entry', ['reason' => $reason, 'filter' => $identifier]);
        } catch (Throwable) {
            // A failing logging channel must not turn an admission error into a failed business write.
        }

        return true;
    }
}
