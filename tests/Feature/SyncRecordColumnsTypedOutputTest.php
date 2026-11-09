<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use ReflectionMethod;
use Sopheak\Core\Console\SyncRecordColumnsCommand;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTableType;

class SyncRecordColumnsTypedOutputTest extends TestCase
{
    public function test_generated_columns_use_record_column_type_and_load_back_identically(): void
    {
        $columns = [
            'id' => ['type' => 'uuid', 'nullable' => false],
            'status' => ['type' => 'string', 'nullable' => false, 'default' => 'open', 'enum' => ['open', 'paid']],
            'qty' => ['type' => 'integer', 'nullable' => true, 'default' => null, 'udt_name' => 'int4'],
        ];

        $export = new ReflectionMethod(SyncRecordColumnsCommand::class, 'exportColumnsAsShortArray');
        $code = $export->invoke(new SyncRecordColumnsCommand(), $columns, '');

        $this->assertStringContainsString('new RecordColumnType(type: RecordColumnTypeEnum::', $code);

        $loaded = eval('use Sopheak\Core\Types\RecordColumnType; use Sopheak\Core\Enums\RecordColumnTypeEnum; return new \Sopheak\Core\Types\RecordTableType(table: "t", columns: ' . $code . ');');
        $this->assertInstanceOf(RecordTableType::class, $loaded);
        foreach ($columns as $name => $meta) {
            $this->assertSame(RecordColumnType::fromArray($meta)->toArray(), $loaded->columns[$name]);
        }
    }
}
