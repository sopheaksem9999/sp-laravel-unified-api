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
        mkdir($this->dir . '/tables', 0o777, true);
        mkdir($this->dir . '/global-functions', 0o777, true);
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
        mkdir($this->dir . '/globalFunctions', 0o777, true);
        file_put_contents($this->dir . '/globalFunctions/a.php', '<?php return ["one" => []];');
        file_put_contents($this->dir . '/global-functions/b.php', '<?php return ["two" => []];');

        $functions = RecordConfigLoader::globalFunctions(
            $this->dir . '/globalFunctions',
            $this->dir . '/global-functions'
        );

        $this->assertArrayHasKey('a/one', $functions);
        $this->assertArrayHasKey('b/two', $functions);
    }

    /**
     * @test
     *
     * Both files declare the same "shared" key via an array return, so
     * array_merge() means whichever file is scanned last wins. Naming them so
     * "a_first" sorts before "z_second" makes that outcome depend on
     * phpFilesIn() actually sorting: with sort() (ascending), z_second.php is
     * applied last and "shared" ends up "from-z"; an unsorted or
     * reverse-sorted scan would leave "from-a" instead — in theory. In
     * practice, on this development machine, this fixture is written in the
     * same order it would be scanned in anyway (a_first before z_second), so
     * it does NOT actually detect a missing sort() here — see the sibling
     * test below, which does, and explains why this one doesn't.
     */
    public function it_processes_files_in_sorted_order_so_the_last_file_alphabetically_wins_a_key_conflict(): void
    {
        file_put_contents(
            $this->dir . '/tables/a_first.php',
            '<?php return ["shared" => new \Sopheak\Core\Types\RecordTableType(table: "from-a")];'
        );
        file_put_contents(
            $this->dir . '/tables/z_second.php',
            '<?php return ["shared" => new \Sopheak\Core\Types\RecordTableType(table: "from-z")];'
        );

        $tables = RecordConfigLoader::tables($this->dir . '/tables');

        $this->assertSame('from-z', $tables['shared']->table);
    }

    /**
     * @test
     *
     * Asserts the general sortedness property directly: files are scanned in
     * ascending pathname order regardless of the order they were written in.
     * Fixture files are written in a scrambled order (delta, alpha, charlie,
     * bravo) specifically so the assertion cannot be satisfied by accident —
     * unlike the sibling test above, whose two files happen to be written
     * already in sorted order.
     *
     * Mutation testing on this development machine, run twice, with results
     * that turned out not to match the "APFS enumerates by name" assumption
     * this test was originally written to route around:
     *
     * - `sort` -> `rsort`: fails both this test and the sibling above, as
     *   expected.
     * - `sort($files);` deleted outright: the sibling test above still
     *   passes (its two files coincidentally already enumerate in scan
     *   order — confirmed by probing RecursiveDirectoryIterator directly:
     *   for files named a_first/z_second it yields them in that same
     *   order), but THIS test fails. Directly probing the same iterator for
     *   files named delta/alpha/charlie/bravo (written in that order)
     *   returned them as bravo, charlie, alpha, delta — neither write order
     *   nor alphabetical order, i.e. this filesystem's native directory
     *   enumeration is not name-ordered in general; the sibling test's
     *   fixture just happens to land on a pair of names for which its
     *   incidental order matches. So on this machine sort() removal IS
     *   caught, by this test, precisely because its fixture does not rely on
     *   that coincidence. Whatever a given filesystem's native enumeration
     *   order actually is (name-ordered B-tree, hash-based readdir, or
     *   something else), a test whose write order already matches sort()
     *   order can accidentally rely on it lining up; this one is built not
     *   to.
     */
    public function it_orders_files_ascending_by_pathname_regardless_of_write_order(): void
    {
        foreach (['delta', 'alpha', 'charlie', 'bravo'] as $name) {
            file_put_contents(
                $this->dir . sprintf('/tables/%s.php', $name),
                sprintf(
                    '<?php return ["%s" => new \Sopheak\Core\Types\RecordTableType(table: "%s")];',
                    $name,
                    $name
                )
            );
        }

        $tables = RecordConfigLoader::tables($this->dir . '/tables');

        $this->assertSame(['alpha', 'bravo', 'charlie', 'delta'], array_keys($tables));
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
