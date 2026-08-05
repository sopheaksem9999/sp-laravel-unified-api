<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Tests\TestCase;

/**
 * config/sp-record.php is publish-only: CoreSpLaravelApiProvider::register()
 * calls mergeConfigFrom() for `sp-laravel-api`, `attachments`, `webhooks`,
 * `audit`, `permissions`, and `sp-api-mcp` (src/CoreSpLaravelApiProvider.php,
 * lines 54-59) but never for `record`. So nothing in this suite ever
 * `require`s or evaluates config/sp-record.php, and its loader wiring -- the
 * RecordConfigLoader calls and the `autoloaded` flag -- had zero coverage
 * from any other test until this one. (This was confirmed during review: with
 * one of the two RecordConfigLoader::globalFunctions() directory arguments
 * removed from config/sp-record.php, the full 520-test suite still passed.)
 *
 * Requiring the file directly here, the same way Laravel's own config loader
 * would in a client project, at least proves: the file parses and evaluates
 * without a fatal error; RecordConfigService::globalFunctionDirectoryNames()
 * -- the method both this file and RecordConfigService itself call -- exists
 * and is callable with the signature this file expects (a typo or signature
 * change here would throw when the file is required); and that `autoloaded`
 * really is `true`.
 *
 * What this test deliberately does NOT attempt: the package's own config/
 * directory (this file's __DIR__ when required directly here, as opposed to
 * a client's config/ directory once published) has no records/tables or
 * records/globalFunctions|global-functions subdirectories. Both
 * RecordConfigLoader::tables() and ::globalFunctions() return [] whenever the
 * directory they're given does not exist, so this test would return the same
 * empty result whether config/sp-record.php passed zero, one, or both of the
 * real global-function directory names -- dropping a spelling here would NOT
 * make this test fail. That specific regression is guarded instead by
 * RecordConfigAutoloadedTest::global_function_directory_names_contains_both_spellings(),
 * which asserts on RecordConfigService::globalFunctionDirectoryNames()
 * directly and needs no filesystem state to fail correctly. Do not read the
 * assertions below as proof that directory scanning itself works from this
 * file -- they only prove the file is wired together and does not crash.
 */
class RecordConfigFileTest extends TestCase
{
    /** @test */
    public function it_evaluates_without_error_and_reports_autoloaded_true(): void
    {
        $config = require __DIR__ . '/../../config/sp-record.php';

        $this->assertTrue($config['autoloaded']);
        $this->assertIsArray($config['tables']);
        $this->assertIsArray($config['global_functions']);
    }
}
