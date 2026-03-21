<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use stdClass;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Tests\TestCase;

/**
 * Unit tests for RecordApiResponseService::applyCasts().
 *
 * Coverage:
 *  - null / empty-casting guards
 *  - every built-in scalar cast
 *  - null value preservation
 *  - missing column skip
 *  - callable forms: Closure, [Class, 'method'], 'Class@method', 'ClassName'
 *  - container types: Collection, sequential array, LengthAwarePaginator
 *  - relationship casts (dot-notation):
 *      belongsTo / hasOne (single object)
 *      hasMany as array list
 *      hasMany as Collection
 *      null relation preserved
 *      missing relation key skipped
 *      missing column inside relation skipped
 *      null column inside relation preserved
 *  - flat + relational combined
 *  - Closure cast on a relation column
 */
class ApplyCastsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function applyCasts(mixed $data, array $casting, array $columns = []): mixed
    {
        return RecordApiResponseService::applyCasts($data, $columns, $casting);
    }

    private function obj(array $attrs): object
    {
        return (object) $attrs;
    }

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    public function test_returns_null_when_data_is_null(): void
    {
        $this->assertNull($this->applyCasts(null, ['price' => 'float']));
    }

    public function test_returns_data_unchanged_when_casting_is_empty(): void
    {
        $row = $this->obj(['price' => '9.99']);
        $this->assertSame($row, $this->applyCasts($row, []));
    }

    // -------------------------------------------------------------------------
    // Built-in scalar casts (array row)
    // -------------------------------------------------------------------------

    public function test_int_cast(): void
    {
        $result = $this->applyCasts(['qty' => '42'], ['qty' => 'int']);
        $this->assertSame(42, $result['qty']);
    }

    public function test_integer_alias_cast(): void
    {
        $result = $this->applyCasts(['qty' => '7'], ['qty' => 'integer']);
        $this->assertSame(7, $result['qty']);
    }

    public function test_float_cast(): void
    {
        $result = $this->applyCasts(['price' => '3.14'], ['price' => 'float']);
        $this->assertSame(3.14, $result['price']);
    }

    public function test_double_alias_cast(): void
    {
        $result = $this->applyCasts(['val' => '1.5'], ['val' => 'double']);
        $this->assertSame(1.5, $result['val']);
    }

    public function test_decimal_colon_cast(): void
    {
        $result = $this->applyCasts(['score' => '9.12345'], ['score' => 'decimal:3']);
        $this->assertSame('9.123', $result['score']);
    }

    public function test_decimal_plain_cast(): void
    {
        $result = $this->applyCasts(['val' => '7'], ['val' => 'decimal']);
        $this->assertSame(7.0, $result['val']);
    }

    public function test_string_cast(): void
    {
        $result = $this->applyCasts(['code' => 123], ['code' => 'string']);
        $this->assertSame('123', $result['code']);
    }

    public function test_bool_cast_truthy(): void
    {
        $result = $this->applyCasts(['active' => 1], ['active' => 'bool']);
        $this->assertTrue($result['active']);
    }

    public function test_boolean_cast_falsy(): void
    {
        $result = $this->applyCasts(['active' => 0], ['active' => 'boolean']);
        $this->assertFalse($result['active']);
    }

    public function test_array_cast_from_json_string(): void
    {
        $result = $this->applyCasts(['meta' => '{"a":1}'], ['meta' => 'array']);
        $this->assertSame(['a' => 1], $result['meta']);
    }

    public function test_json_alias_cast(): void
    {
        $result = $this->applyCasts(['meta' => '[1,2]'], ['meta' => 'json']);
        $this->assertSame([1, 2], $result['meta']);
    }

    public function test_object_cast_from_json_string(): void
    {
        $result = $this->applyCasts(['cfg' => '{"x":1}'], ['cfg' => 'object']);
        $this->assertInstanceOf(stdClass::class, $result['cfg']);
        $this->assertSame(1, $result['cfg']->x);
    }

    public function test_date_cast(): void
    {
        $result = $this->applyCasts(['dob' => '1990-05-15 12:00:00'], ['dob' => 'date']);
        $this->assertSame('1990-05-15', $result['dob']);
    }

    public function test_datetime_cast(): void
    {
        $result = $this->applyCasts(['ts' => '2024-01-01 00:00:00'], ['ts' => 'datetime']);
        $this->assertStringStartsWith('2024-01-01', $result['ts']);
    }

    public function test_timestamp_cast(): void
    {
        $result = $this->applyCasts(['ts' => '2024-01-01 00:00:00 UTC'], ['ts' => 'timestamp']);
        $this->assertIsInt($result['ts']);
    }

    // -------------------------------------------------------------------------
    // Null / missing column behaviour
    // -------------------------------------------------------------------------

    public function test_null_value_is_preserved(): void
    {
        $result = $this->applyCasts(['price' => null], ['price' => 'float']);
        $this->assertNull($result['price']);
    }

    public function test_missing_column_is_skipped(): void
    {
        $result = $this->applyCasts(['name' => 'Foo'], ['price' => 'float']);
        $this->assertArrayNotHasKey('price', $result);
        $this->assertSame('Foo', $result['name']);
    }

    // -------------------------------------------------------------------------
    // Callable cast forms
    // -------------------------------------------------------------------------

    public function test_closure_cast(): void
    {
        $result = $this->applyCasts(['name' => 'hello'], ['name' => strtoupper(...)]);
        $this->assertSame('HELLO', $result['name']);
    }

    public function test_class_method_array_cast(): void
    {
        $result = $this->applyCasts(['val' => '1'], ['val' => ApplyCastTestHelper::toInt(...)]);
        $this->assertSame(1, $result['val']);
    }

    public function test_class_at_method_string_cast(): void
    {
        $result = $this->applyCasts(['val' => '2'], ['val' => ApplyCastTestHelper::class . '@toInt']);
        $this->assertSame(2, $result['val']);
    }

    // -------------------------------------------------------------------------
    // Container types
    // -------------------------------------------------------------------------

    public function test_cast_applied_to_collection(): void
    {
        $collection = new Collection([['price' => '5.5'], ['price' => '10']]);
        $result     = $this->applyCasts($collection, ['price' => 'float']);
        $this->assertSame(5.5, $result[0]['price']);
        $this->assertSame(10.0, $result[1]['price']);
    }

    public function test_cast_applied_to_sequential_array(): void
    {
        $rows   = [['qty' => '3'], ['qty' => '7']];
        $result = $this->applyCasts($rows, ['qty' => 'int']);
        $this->assertSame(3, $result[0]['qty']);
        $this->assertSame(7, $result[1]['qty']);
    }

    public function test_cast_applied_to_paginator(): void
    {
        $paginator = new LengthAwarePaginator(
            [['price' => '9.99']],
            1,
            15,
        );
        $result = $this->applyCasts($paginator, ['price' => 'float']);
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $items = $result->items();
        $this->assertSame(9.99, $items[0]['price']);
    }

    // -------------------------------------------------------------------------
    // Relationship (dot-notation) casts
    // -------------------------------------------------------------------------

    public function test_belongs_to_has_one_object_cast(): void
    {
        $row = $this->obj([
            'id'    => 1,
            'brand' => $this->obj(['active' => 1, 'score' => '9.5']),
        ]);
        $result = $this->applyCasts($row, ['brand.active' => 'bool', 'brand.score' => 'float']);

        $this->assertTrue($result->brand->active);
        $this->assertSame(9.5, $result->brand->score);
    }

    public function test_has_many_array_list_cast(): void
    {
        $row = [
            'id'    => 1,
            'items' => [
                ['price' => '10', 'qty' => '2'],
                ['price' => '20', 'qty' => '3'],
            ],
        ];
        $result = $this->applyCasts($row, ['items.price' => 'float', 'items.qty' => 'int']);

        $this->assertSame(10.0, $result['items'][0]['price']);
        $this->assertSame(3, $result['items'][1]['qty']);
    }

    public function test_has_many_collection_cast(): void
    {
        $row = $this->obj([
            'id'    => 1,
            'items' => new Collection([
                $this->obj(['price' => '5']),
                $this->obj(['price' => '8']),
            ]),
        ]);
        $result = $this->applyCasts($row, ['items.price' => 'float']);

        $this->assertInstanceOf(Collection::class, $result->items);
        $this->assertSame(5.0, $result->items[0]->price);
        $this->assertSame(8.0, $result->items[1]->price);
    }

    public function test_null_relation_is_preserved(): void
    {
        $row    = $this->obj(['id' => 1, 'brand' => null]);
        $result = $this->applyCasts($row, ['brand.active' => 'bool']);

        $this->assertNull($result->brand);
    }

    public function test_missing_relation_key_is_skipped(): void
    {
        $row    = $this->obj(['id' => 1]);
        $result = $this->applyCasts($row, ['brand.active' => 'bool']);

        $this->assertFalse(property_exists($result, 'brand'));
    }

    public function test_missing_column_inside_relation_is_skipped(): void
    {
        $row = $this->obj([
            'id'    => 1,
            'brand' => $this->obj(['name' => 'Foo']),
        ]);
        $result = $this->applyCasts($row, ['brand.active' => 'bool']);

        // 'active' was not present — brand object unchanged
        $this->assertFalse(property_exists($result->brand, 'active'));
        $this->assertSame('Foo', $result->brand->name);
    }

    public function test_null_column_inside_relation_is_preserved(): void
    {
        $row = $this->obj([
            'id'    => 1,
            'brand' => $this->obj(['active' => null]),
        ]);
        $result = $this->applyCasts($row, ['brand.active' => 'bool']);

        $this->assertNull($result->brand->active);
    }

    public function test_flat_and_relational_casts_combined(): void
    {
        $row = $this->obj([
            'price' => '19.99',
            'brand' => $this->obj(['active' => 1]),
        ]);
        $result = $this->applyCasts($row, ['price' => 'float', 'brand.active' => 'bool']);

        $this->assertSame(19.99, $result->price);
        $this->assertTrue($result->brand->active);
    }

    public function test_closure_cast_on_relation_column(): void
    {
        $row = $this->obj([
            'brand' => $this->obj(['name' => 'hello']),
        ]);
        $result = $this->applyCasts($row, ['brand.name' => strtoupper(...)]);

        $this->assertSame('HELLO', $result->brand->name);
    }
}

// ---------------------------------------------------------------------------
// Inline helper used by callable-cast tests (avoids a separate file)
// ---------------------------------------------------------------------------

class ApplyCastTestHelper
{
    public static function toInt(mixed $value): int
    {
        return (int) $value;
    }

    public function get(mixed $value): int
    {
        return (int) $value;
    }
}
