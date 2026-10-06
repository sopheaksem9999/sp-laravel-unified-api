<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use Laravel\Passport\Passport;
use Laravel\Mcp\Server;
use RuntimeException;

/**
 * Which implementation serves the MCP endpoints. The opt-in is explicit: apps
 * often have laravel/mcp installed only as a dev dependency of laravel/boost,
 * so detecting it would make development and production behave differently.
 */
final class McpDriver
{
    public const LEGACY = 'legacy';

    public const LARAVEL = 'laravel';

    public static function current(): string
    {
        return self::LARAVEL === config('record.mcp.driver', self::LEGACY) ? self::LARAVEL : self::LEGACY;
    }

    public static function isLaravel(): bool
    {
        return self::LARAVEL === self::current();
    }

    public static function isActive(): bool
    {
        return self::isLaravel() && ((bool) config('record.mcp.enabled', false) || (bool) config('sp-api-mcp.enabled', false));
    }

    /**
     * OAuth 2.1 discovery for MCP clients that require it (claude.ai and ChatGPT
     * connectors). Opt-in, and only meaningful on the `laravel` driver.
     */
    public static function oauthEnabled(): bool
    {
        return self::isLaravel() && (bool) config('record.mcp.oauth', false);
    }

    /**
     * @param null|callable(string): bool $classExists
     */
    public static function assertOAuthAvailable(?callable $classExists = null): void
    {
        $classExists ??= class_exists(...);

        if (self::oauthEnabled() && !$classExists(Passport::class)) {
            throw new RuntimeException('record.mcp.oauth requires laravel/passport. Run: composer require laravel/passport');
        }
    }

    /**
     * @param null|callable(string): bool $classExists
     */
    public static function assertInstalled(?callable $classExists = null): void
    {
        $classExists ??= class_exists(...);

        if (self::isActive() && !$classExists(Server::class)) {
            throw new RuntimeException('record.mcp.driver is "laravel" but laravel/mcp is not installed. Run: composer require laravel/mcp');
        }
    }
}
