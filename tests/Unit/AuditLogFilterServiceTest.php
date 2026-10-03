<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Interfaces\AuditLogFilterInterface;
use RuntimeException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogFilterService;
use Sopheak\Core\Tests\TestCase;

class AuditLogFilterServiceTest extends TestCase
{
    public function test_interface_class_and_explicit_binding_are_supported(): void
    {
        config(['audit.filter' => InterfaceFilter::class]);
        $service = new AuditLogFilterService();
        $this->assertFalse($service->shouldLog(AuditLogEventEnum::CREATED, 'widgets', [], null));
        $this->app->bind(AuditLogFilterInterface::class, InterfaceFilter::class);
        config(['audit.filter' => AuditLogFilterInterface::class]);
        $this->assertFalse($service->shouldLog(AuditLogEventEnum::CREATED, 'widgets', [], null));
        config(['audit.filter' => null]);
        $this->assertTrue($service->shouldLog(AuditLogEventEnum::CREATED, 'widgets', [], null));
    }

    public function test_class_method_is_resolved_with_dependencies(): void
    {
        $this->app->bind(FilterDependency::class, fn(): FilterDependency => new FilterDependency(false));
        config(['audit.filter' => [DependentFilter::class, 'decide']]);
        $this->assertFalse((new AuditLogFilterService())->shouldLog(AuditLogEventEnum::CREATED, 'widgets', [], null));
    }

    public function test_invalid_results_and_exceptions_retain_audit_with_sanitized_warning(): void
    {
        Log::spy();
        foreach (['invalid', 'throws', 'hidden', 'missing'] as $method) {
            config(['audit.filter' => [StaticFilter::class, $method]]);
            $this->assertTrue((new AuditLogFilterService())->shouldLog(AuditLogEventEnum::CREATED, 'widgets', ['secret' => 'private'], null));
        }

        Log::shouldHaveReceived('warning')->times(4)->withArgs(
            fn($message, $context): bool
            => array_keys($context) === ['reason', 'filter'] && !str_contains(json_encode($context), 'private')
        );
    }

    public function test_validation_does_not_construct_filter(): void
    {
        $this->assertNull(AuditLogFilterService::configurationError([DependentFilter::class, 'decide']));
        foreach ([false, [], ['MissingClass', 'method'], [StaticFilter::class, 'hidden'], StaticFilter::class] as $filter) {
            $this->assertNotNull(AuditLogFilterService::configurationError($filter));
        }
    }
}

class FilterDependency
{
    public function __construct(public bool $allow) {}
}

class DependentFilter
{
    public function __construct(private readonly FilterDependency $dependency) {}

    public function decide(): bool
    {
        return $this->dependency->allow;
    }
}

class StaticFilter
{
    protected static function hidden(): bool
    {
        return false;
    }

    public static function invalid(): mixed
    {
        return null;
    }

    public static function throws(): bool
    {
        throw new RuntimeException('private');
    }
}

class InterfaceFilter implements AuditLogFilterInterface
{
    public function shouldLog(AuditLogEventEnum $event, string $table, array $auditData, ?Authenticatable $user, array $context = []): bool
    {
        return false;
    }
}
