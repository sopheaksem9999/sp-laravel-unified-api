<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\File;
use Sopheak\Core\Support\RecordConfigLoader;
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

    /**
     * `autoloaded => true` switches off RecordConfigService's runtime scan --
     * and that runtime scan is the only thing in the package that ever reads
     * `record.table_config_path`. So in a published install the loader call at
     * the bottom of config/sp-record.php is the SOLE reader of the table
     * config directory, and if it names a directory of its own, a client who
     * changes `table_config_path` loses every table config in the directory
     * they pointed at (while MakeRecordTableCommand, SyncRecordColumnsCommand
     * and GenerateRecordTablesFromDatabaseCommand keep writing into it).
     *
     * This test edits the file the way a client does -- it rewrites the single
     * quoted `'records/tables'` literal, asserting there is exactly one so the
     * edit cannot be ambiguous -- copies it into a scratch config directory
     * alongside a table config in the NEW directory, and requires it the way
     * Laravel's config loader would.
     *
     * It is written against the bug, not the fix: it makes the same one-token
     * edit whether the file binds the path to a variable or hardcodes it in
     * two places, so hardcoding the loader's directory again (the pre-fix
     * shape, `RecordConfigLoader::tables(__DIR__ . '/records/tables')`) leaves
     * the edit applying to `table_config_path` alone and the `widgets`
     * assertion below fails.
     *
     * @test
     */
    public function a_custom_table_config_path_is_honored_by_the_autoloaded_scan(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../config/sp-record.php');

        $this->assertSame(
            1,
            substr_count($source, "'records/tables'"),
            "config/sp-record.php must carry exactly one quoted 'records/tables' literal so the "
            . 'table config directory has a single place a client can change it'
        );

        $custom = 'records/mytables';
        $edited = str_replace("'records/tables'", "'" . $custom . "'", $source);

        $directory = sys_get_temp_dir() . '/sp_record_config_path_' . uniqid('', true);
        File::ensureDirectoryExists($directory . '/' . $custom);

        try {
            file_put_contents(
                $directory . '/' . $custom . '/widgets.php',
                '<?php return new \Sopheak\Core\Types\RecordTableType(table: "widgets");'
            );
            file_put_contents($directory . '/sp-record.php', $edited);

            RecordConfigLoader::flush();
            $config = require $directory . '/sp-record.php';

            $this->assertSame($custom, $config['table_config_path']);
            $this->assertArrayHasKey(
                'widgets',
                $config['tables'],
                'the autoloaded scan must read the directory named by table_config_path, not a '
                . 'separately hardcoded one -- otherwise a client with a custom table_config_path '
                . 'silently loses every table config and every CRUD route it defined'
            );
        } finally {
            File::deleteDirectory($directory);
            RecordConfigLoader::flush();
        }
    }
}
