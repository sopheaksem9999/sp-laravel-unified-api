<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class ResolutionTriggerStub
{
    #[RecordTrigger('afterCreate')]
    public static function created(): void {}

    #[RecordTrigger('columns')]
    public static function notAHook(): void {}
}

class RecordTableTypeResolutionTest extends TestCase
{
    public function test_attribute_triggers_are_not_duplicated_by_a_config_cache_round_trip(): void
    {
        $table = new RecordTableType(table: 't', triggers: [ResolutionTriggerStub::class]);
        $restored = eval('return ' . var_export($table, true) . ';');

        $this->assertCount(1, $table->afterCreate);
        $this->assertCount(1, $restored->afterCreate);
    }

    public function test_triggers_resolve_with_a_legacy_public_override(): void
    {
        $table = new RecordTableType(table: 't', public: new RecordTablePublic(read: true), triggers: [ResolutionTriggerStub::class]);

        $this->assertCount(1, $table->afterCreate);
        $this->assertFalse($table->isAuthRead);
        $this->assertTrue($table->isAuthWrite);
        $this->assertTrue($table->public->read);
        $this->assertFalse($table->public->write);
    }

    public function test_an_attribute_naming_a_non_hook_property_is_ignored(): void
    {
        $table = new RecordTableType(table: 't', columns: ['a' => ['type' => 'string']], triggers: [ResolutionTriggerStub::class]);

        $this->assertSame(['a' => ['type' => 'string']], $table->columns);
    }
}
