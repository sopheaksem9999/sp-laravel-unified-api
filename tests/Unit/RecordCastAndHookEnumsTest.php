<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use InvalidArgumentException;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Attributes\RecordValidator;
use Sopheak\Core\Enums\RecordCastEnum;
use Sopheak\Core\Enums\RecordColumnTypeEnum;
use Sopheak\Core\Enums\RecordTriggerHookEnum;
use Sopheak\Core\Enums\RecordValidatorHookEnum;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordCastType;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordTableType;

class EnumHookTriggerStub
{
    #[RecordTrigger(RecordTriggerHookEnum::BEFORE_CREATE, description: 'Enum beforeCreate trigger')]
    public static function onBeforeCreate(): void {}

    #[RecordTrigger(RecordTriggerHookEnum::AFTER_UPDATE)]
    public static function onAfterUpdate(): void {}
}

class EnumHookValidatorStub
{
    /**
     * @return array<string, string>
     */
    #[RecordValidator(RecordValidatorHookEnum::CREATE)]
    public static function validateCreate(): array
    {
        return ['name' => 'required|string'];
    }

    /**
     * @return array<string, string>
     */
    #[RecordValidator(RecordValidatorHookEnum::UPDATE)]
    public static function validateUpdate(): array
    {
        return ['name' => 'sometimes|string'];
    }
}

class CustomCastStub
{
    public static function get(mixed $value): string
    {
        return 'custom:' . $value;
    }

    public static function formatPrice(mixed $value): string
    {
        return '$' . number_format((float) $value, 2, '.', '');
    }
}

class RecordCastAndHookEnumsTest extends TestCase
{
    public function test_record_trigger_hook_enum_values_and_attribute_resolution(): void
    {
        $this->assertSame([
            'beforeRead',
            'afterRead',
            'beforeCreate',
            'afterCreate',
            'beforeUpdate',
            'afterUpdate',
            'beforeDelete',
            'afterDelete',
            'beforeRestore',
            'afterRestore',
        ], RecordTriggerHookEnum::values());

        $attr = new RecordTrigger(RecordTriggerHookEnum::BEFORE_CREATE, description: 'Test');
        $this->assertSame('beforeCreate', $attr->hook);
        $this->assertSame('Test', $attr->description);

        $table = new RecordTableType(
            table: 'orders',
            triggers: [EnumHookTriggerStub::class],
        );

        $this->assertIsArray($table->beforeCreate);
        $this->assertCount(1, $table->beforeCreate);
        $this->assertInstanceOf(RecordTableTriggerType::class, $table->beforeCreate[0]);
        $this->assertSame(EnumHookTriggerStub::class, $table->beforeCreate[0]->class);
        $this->assertSame('onBeforeCreate', $table->beforeCreate[0]->functionName);
        $this->assertSame('Enum beforeCreate trigger', $table->beforeCreate[0]->description);

        $this->assertIsArray($table->afterUpdate);
        $this->assertCount(1, $table->afterUpdate);
        $this->assertSame('onAfterUpdate', $table->afterUpdate[0]->functionName);
    }

    public function test_record_validator_hook_enum_values_and_attribute_resolution(): void
    {
        $this->assertSame(['create', 'update', 'delete'], RecordValidatorHookEnum::values());

        $attr = new RecordValidator(RecordValidatorHookEnum::UPDATE, description: 'Validate update');
        $this->assertSame('update', $attr->hook);
        $this->assertSame('Validate update', $attr->description);

        $table = new RecordTableType(
            table: 'orders',
            createValidator: EnumHookValidatorStub::class,
            triggers: [EnumHookValidatorStub::class],
        );

        $expectedCreate = [EnumHookValidatorStub::class, strval('validateCreate')];
        $expectedUpdate = [EnumHookValidatorStub::class, strval('validateUpdate')];
        $this->assertSame($expectedCreate, $table->createValidator);
        $this->assertSame($expectedUpdate, $table->updateValidator);
    }

    public function test_record_cast_enum_and_record_cast_type_in_column_and_response_service(): void
    {
        $colEnum = new RecordColumnType(type: RecordColumnTypeEnum::DECIMAL, cast: RecordCastEnum::FLOAT);
        $this->assertSame('float', $colEnum->cast);

        $colDecimalHelper = new RecordColumnType(type: RecordColumnTypeEnum::DECIMAL, cast: RecordCastEnum::decimal(4));
        $this->assertSame('decimal:4', $colDecimalHelper->cast);

        $castTypeDefault = new RecordCastType(class: CustomCastStub::class, description: 'Default get cast');
        $this->assertSame([CustomCastStub::class, strval('get')], $castTypeDefault->toCallable());
        $this->assertSame(CustomCastStub::class . '@get', $castTypeDefault->toCastString());

        $castTypeCustomMethod = new RecordCastType(
            class: CustomCastStub::class,
            functionName: 'formatPrice',
        );
        $this->assertSame([CustomCastStub::class, strval('formatPrice')], $castTypeCustomMethod->toCallable());
        $this->assertSame(CustomCastStub::class . '@formatPrice', $castTypeCustomMethod->toCastString());

        $colCustomCast = new RecordColumnType(type: RecordColumnTypeEnum::DECIMAL, cast: $castTypeCustomMethod);
        $this->assertSame(CustomCastStub::class . '@formatPrice', $colCustomCast->cast);

        $exported = var_export($castTypeCustomMethod, true);
        $restored = eval('return ' . $exported . ';');
        $this->assertInstanceOf(RecordCastType::class, $restored);
        $this->assertSame(CustomCastStub::class, $restored->class);
        $this->assertSame('formatPrice', $restored->functionName);

        $row = [
            'qty' => '12',
            'ratio' => '3.5',
            'active' => 1,
            'label' => 'abc',
            'price' => '19.5',
        ];
        $casts = [
            'qty' => RecordCastEnum::INT,
            'ratio' => RecordCastEnum::FLOAT,
            'active' => RecordCastEnum::BOOL,
            'label' => $castTypeDefault,
            'price' => $castTypeCustomMethod,
        ];

        $casted = RecordApiResponseService::applyCasts([$row], [], $casts);
        $this->assertSame(12, $casted[0]['qty']);
        $this->assertSame(3.5, $casted[0]['ratio']);
        $this->assertTrue($casted[0]['active']);
        $this->assertSame('custom:abc', $casted[0]['label']);
        $this->assertSame('$19.50', $casted[0]['price']);
    }

    public function test_record_cast_type_rejects_empty_class_or_function_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecordCastType(class: '   ');
    }

    public function test_custom_audit_log_accepts_record_table_trigger_type(): void
    {
        $auditTrigger = new RecordTableTriggerType(
            class: 'App\\Audit\\OrderAuditLogger',
            functionName: 'handle',
            description: 'Custom audit logger',
        );

        $table = new RecordTableType(
            table: 'orders',
            customAuditLog: $auditTrigger,
        );

        $this->assertInstanceOf(RecordTableTriggerType::class, $table->customAuditLog);
        $this->assertSame([
            'class' => 'App\\Audit\\OrderAuditLogger',
            'functionName' => 'handle',
            'description' => 'Custom audit logger',
        ], $table->customAuditLog->toArray());

        $restored = eval('return ' . var_export($table, true) . ';');
        $this->assertInstanceOf(RecordTableTriggerType::class, $restored->customAuditLog);
        $this->assertSame('App\\Audit\\OrderAuditLogger', $restored->customAuditLog->class);
        $this->assertSame('handle', $restored->customAuditLog->functionName);
    }
}

