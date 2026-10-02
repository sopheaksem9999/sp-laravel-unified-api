<?php

declare(strict_types=1);

namespace Laravel\Passport;

/**
 * Test-only stand-in for laravel/passport, which this package does not require.
 * laravel/mcp's OAuth discovery routes only touch `Passport::$scopes` and
 * `Passport::tokensCan()` when they are registered; everything else needs a real
 * Passport install (documented as a manual check). Declared only when the real
 * class is absent.
 */
if (!class_exists(Passport::class)) {
    class Passport
    {
        /** @var array<string, string> */
        public static array $scopes = [];

        /**
         * @param array<string, string> $scopes
         */
        public static function tokensCan(array $scopes): void
        {
            self::$scopes = $scopes;
        }
    }
}
