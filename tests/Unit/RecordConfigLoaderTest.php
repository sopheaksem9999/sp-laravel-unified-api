<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use Sopheak\Core\Support\RecordConfigLoader;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class RecordConfigLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        RecordConfigLoader::flush();
        $this->dir = sys_get_temp_dir() . '/rcl-' . getmypid();
        $this->cleanup();
        mkdir($this->dir . '/tables', 0777, true);
        mkdir($this->dir . '/global-functions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        RecordConfigLoader::flush();
        parent::tearDown();
    }

    /** @test */
    public function it_keys_a_record_table_type_file_by_its_filename(): void
    {
        file_put_contents(
            $this->dir . '/tables/widgets.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "widgets");'
        );

        $tables = RecordConfigLoader::tables($this->dir . '/tables');

        $this->assertArrayHasKey('widgets', $tables);
        $this->assertInstanceOf(RecordTableType::class, $tables['widgets']);
    }

    /** @test */
    public function it_merges_array_returning_files_by_their_own_keys(): void
    {
        file_put_contents(
            $this->dir . '/tables/bundle.php',
            '<?php return ["alpha" => new \Sopheak\Core\Types\RecordTableType(table: "alpha"), '
            . '"beta" => new \Sopheak\Core\Types\RecordTableType(table: "beta")];'
        );

        $tables = RecordConfigLoader::tables($this->dir . '/tables');

        $this->assertArrayHasKey('alpha', $tables);
        $this->assertArrayHasKey('beta', $tables);
        $this->assertArrayNotHasKey('bundle', $tables);
    }

    /** @test */
    public function it_returns_an_empty_array_for_a_missing_directory(): void
    {
        $this->assertSame([], RecordConfigLoader::tables($this->dir . '/nope'));
    }

    /** @test */
    public function it_prefixes_global_function_names_with_their_file_group(): void
    {
        file_put_contents(
            $this->dir . '/global-functions/auth.php',
            '<?php return ["login" => ["type" => "closure"]];'
        );

        $functions = RecordConfigLoader::globalFunctions($this->dir . '/global-functions');

        $this->assertArrayHasKey('auth/login', $functions);
        $this->assertArrayNotHasKey('login', $functions);
    }

    /** @test */
    public function it_leaves_an_already_pathed_function_name_unprefixed(): void
    {
        file_put_contents(
            $this->dir . '/global-functions/auth.php',
            '<?php return ["media/upload" => ["type" => "closure"]];'
        );

        $functions = RecordConfigLoader::globalFunctions($this->dir . '/global-functions');

        $this->assertArrayHasKey('media/upload', $functions);
        $this->assertArrayNotHasKey('auth/media/upload', $functions);
    }

    /** @test */
    public function it_skips_non_string_and_empty_function_names(): void
    {
        file_put_contents(
            $this->dir . '/global-functions/auth.php',
            '<?php return [0 => ["type" => "closure"], "" => ["type" => "closure"], "ok" => ["type" => "closure"]];'
        );

        $functions = RecordConfigLoader::globalFunctions($this->dir . '/global-functions');

        $this->assertSame(['auth/ok'], array_keys($functions));
    }

    /** @test */
    public function it_scans_every_directory_it_is_given(): void
    {
        mkdir($this->dir . '/globalFunctions', 0777, true);
        file_put_contents($this->dir . '/globalFunctions/a.php', '<?php return ["one" => []];');
        file_put_contents($this->dir . '/global-functions/b.php', '<?php return ["two" => []];');

        $functions = RecordConfigLoader::globalFunctions(
            $this->dir . '/globalFunctions',
            $this->dir . '/global-functions'
        );

        $this->assertArrayHasKey('a/one', $functions);
        $this->assertArrayHasKey('b/two', $functions);
    }

    /** @test */
    public function it_memoizes_by_directory(): void
    {
        file_put_contents(
            $this->dir . '/tables/widgets.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "widgets");'
        );
        RecordConfigLoader::tables($this->dir . '/tables');

        file_put_contents(
            $this->dir . '/tables/gadgets.php',
            '<?php return new \Sopheak\Core\Types\RecordTableType(table: "gadgets");'
        );

        $this->assertArrayNotHasKey(
            'gadgets',
            RecordConfigLoader::tables($this->dir . '/tables'),
            'a second call must be served from the memo'
        );
        $this->assertArrayHasKey(
            'gadgets',
            (function (): array {
                RecordConfigLoader::flush();
                return RecordConfigLoader::tables($this->dir . '/tables');
            })()
        );
    }

    private function cleanup(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }

        rmdir($this->dir);
    }
}
