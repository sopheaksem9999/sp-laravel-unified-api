<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Sopheak\Core\Console\SyncRecordColumnsCommand;
use Sopheak\Core\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An apostrophe or a bracket inside a comment or a string must not throw off
 * the constructor and `columns:` matching: such files were skipped silently.
 */
class SyncRecordColumnsParsingTest extends TestCase
{
    public function test_comments_and_strings_with_quotes_and_brackets_do_not_stop_the_sync(): void
    {
        $content = <<<'PHP'
<?php

use Sopheak\Core\Types\RecordTableType;

// The table's config (see docs).
return new RecordTableType(
    pmsName: 'product',
    // `columns` is filled by the sync; don't hand-write it (really).
    columns: [
        'old' => ['type' => 'text', 'default' => "'[x]'::text"],
    ],
    // A bundle's components ] ) [ (
    columnWriteDisabled: ['item_kind'],
    functions: [
        'f' => ['description' => "it's (odd) ]"],
    ],
);
PHP;
        $path = tempnam(sys_get_temp_dir(), 'record_table_');
        file_put_contents($path, $content);

        $command = new SyncRecordColumnsCommand();
        $command->setLaravel($this->app);

        $output = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        (new ReflectionMethod($command, 'updateConfigFile'))->invoke($command, $path, 'products', ['id' => ['type' => 'bigint', 'nullable' => false]], false);

        $updated = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringNotContainsString('Could not', $output->fetch());
        $this->assertStringContainsString("'id' => new RecordColumnType(type: RecordColumnTypeEnum::BIGINT, nullable: false)", $updated);
        $this->assertStringNotContainsString("'old' =>", $updated);
        $this->assertStringContainsString("// A bundle's components ] ) [ (", $updated);
        $this->assertStringContainsString("'f' => ['description' => \"it's (odd) ]\"]", $updated);
        $this->assertSame(1, preg_match_all('/^\s*columns:/m', $updated));

        $loaded = eval('?>' . $updated);
        $this->assertSame(['type' => 'bigint', 'nullable' => false], $loaded->columns['id']);
    }

    /** @return array<string, array{0: string}> */
    public static function layouts(): array
    {
        $head = "<?php\n\nuse Sopheak\\Core\\Types\\RecordTableType;\n\nreturn new RecordTableType(\n    pmsName: 'booking',\n";
        $old = "    columns: [\n        'old' => ['type' => 'text'],\n    ],\n";

        return [
            'columns in the middle' => [$head . "    softDeletes: true,\n" . $old . "    columnWriteDisabled: ['x'],\n    columnIndexes: [],\n    relationships: [],\n    functions: [],\n);\n"],
            'columns first' => [$head . $old . "    columnHiddens: ['p'],\n    permissions: ['read' => ['view:booking']],\n);\n"],
            'columns last' => [$head . "    columnHiddens: ['p'],\n    permissions: ['read' => ['view:booking']],\n" . $old . ");\n"],
            'no columns yet' => [$head . "    columnWriteDisabled: ['x'],\n    columnIndexes: [],\n    relationships: [],\n);\n"],
        ];
    }

    /** @dataProvider layouts */
    #[DataProvider('layouts')]
    public function test_columns_stay_where_they_were_and_other_arguments_keep_their_order(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'record_table_');
        file_put_contents($path, $content);

        $command = new SyncRecordColumnsCommand();
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        (new ReflectionMethod($command, 'updateConfigFile'))->invoke($command, $path, 'bookings', ['id' => ['type' => 'bigint', 'nullable' => false]], false);
        $updated = (string) file_get_contents($path);
        unlink($path);

        $names = static fn(string $code): array => (function () use ($code): array {
            preg_match_all('/^    ([A-Za-z]+):/m', $code, $m);

            return $m[1];
        })();

        $before = array_values(array_diff($names($content), ['columns']));
        $after = $names($updated);
        $this->assertSame(1, array_count_values($after)['columns']);
        $this->assertSame($before, array_values(array_diff($after, ['columns'])), 'other arguments keep their order');

        if (in_array('columns', $names($content), true)) {
            $this->assertSame(array_search('columns', $names($content), true), array_search('columns', $after, true), 'columns keeps its position');
        } else {
            $this->assertSame('pmsName', $after[array_search('columns', $after, true) - 1] ?? null);
            $this->assertSame('columnWriteDisabled', $after[array_search('columns', $after, true) + 1] ?? null);
        }

        $this->assertStringContainsString('RecordColumnType(type: RecordColumnTypeEnum::BIGINT', $updated);
        $this->assertNotFalse(@eval('?>' . $updated), 'the file still loads');
    }
}
