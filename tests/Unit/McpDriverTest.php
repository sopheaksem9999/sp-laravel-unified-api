<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Laravel\Mcp\Server\McpServiceProvider;
use RuntimeException;
use Sopheak\Core\Mcp\McpDriver;
use Sopheak\Core\Tests\Concerns\SkipsOnLaravelMcpDriver;
use Sopheak\Core\Tests\TestCase;

/** @internal */
class McpDriverTest extends TestCase
{
    use SkipsOnLaravelMcpDriver;

    /** @test */
    public function the_default_driver_is_legacy(): void
    {
        $this->skipOnLaravelMcpDriver('this asserts the default (legacy) driver, which the matrix run replaces');
        $this->assertSame('legacy', McpDriver::current());
        $this->assertFalse(McpDriver::isLaravel());
    }

    /** @test */
    public function an_unknown_value_falls_back_to_legacy(): void
    {
        config(['record.mcp.driver' => 'typo']);

        $this->assertSame('legacy', McpDriver::current());
    }

    /** @test */
    public function the_laravel_driver_is_active_only_when_an_endpoint_is_enabled(): void
    {
        config(['record.mcp.driver' => 'laravel', 'record.mcp.enabled' => false, 'sp-api-mcp.enabled' => false]);
        $this->assertTrue(McpDriver::isLaravel());
        $this->assertFalse(McpDriver::isActive());

        config(['sp-api-mcp.enabled' => true]);
        $this->assertTrue(McpDriver::isActive());
    }

    /** @test */
    public function a_missing_package_is_a_clear_error_when_the_driver_is_active(): void
    {
        config(['record.mcp.driver' => 'laravel', 'record.mcp.enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer require laravel/mcp');

        McpDriver::assertInstalled(static fn(): bool => false);
    }

    /** @test */
    public function a_missing_package_is_fine_when_the_driver_is_not_active(): void
    {
        config(['record.mcp.driver' => 'legacy', 'record.mcp.enabled' => true]);

        McpDriver::assertInstalled(static fn(): bool => false);

        $this->assertTrue(true);
    }

    /** @test */
    public function the_legacy_driver_loads_no_laravel_mcp_server_method_class(): void
    {
        $this->skipOnLaravelMcpDriver('this asserts the default (legacy) driver, which the matrix run replaces');
        $count = static fn(): int => count(array_filter(get_declared_classes(), static fn(string $c): bool => str_starts_with($c, 'Sopheak\\Core\\Mcp\\Servers')));

        $before = $count();
        $this->postJson('/api/mcp/message', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        // Classes loaded by earlier tests persist in the process, so compare before/after.
        $this->assertSame($before, $count());
    }

    /** @test */
    public function oauth_is_enabled_only_on_the_laravel_driver_with_the_flag(): void
    {
        config(['record.mcp.driver' => 'legacy', 'record.mcp.oauth' => true, 'record.mcp.enabled' => true]);
        $this->assertFalse(McpDriver::oauthEnabled());

        config(['record.mcp.driver' => 'laravel', 'record.mcp.oauth' => false]);
        $this->assertFalse(McpDriver::oauthEnabled());

        config(['record.mcp.oauth' => true]);
        $this->assertTrue(McpDriver::oauthEnabled());
    }

    /** @test */
    public function oauth_without_passport_is_a_clear_error(): void
    {
        config(['record.mcp.driver' => 'laravel', 'record.mcp.oauth' => true, 'record.mcp.enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('record.mcp.oauth requires laravel/passport');

        McpDriver::assertOAuthAvailable(static fn(): bool => false);
    }

    /** @test */
    public function oauth_with_passport_present_passes(): void
    {
        config(['record.mcp.driver' => 'laravel', 'record.mcp.oauth' => true, 'record.mcp.enabled' => true]);

        McpDriver::assertOAuthAvailable(static fn(): bool => true);

        $this->assertTrue(true);
    }

    /** @test */
    public function the_legacy_driver_does_not_register_laravel_mcps_provider(): void
    {
        $this->skipOnLaravelMcpDriver('this asserts the default (legacy) driver, which the matrix run replaces');

        $this->assertNull($this->app->getProvider(McpServiceProvider::class));
    }
}
