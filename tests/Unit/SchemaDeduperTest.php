<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Mcp\Guidance\SchemaDeduper;

class SchemaDeduperTest extends TestCase
{
    private const BIG = ['type' => 'object', 'properties' => ['alpha' => ['type' => 'string'], 'beta' => ['type' => 'integer'], 'gamma' => ['type' => 'string', 'format' => 'date']]];

    public function test_the_first_copy_stays_and_later_copies_point_at_it(): void
    {
        $actions = SchemaDeduper::actions([
            'list' => ['response' => ['dataSchema' => ['type' => 'array', 'items' => self::BIG]]],
            'read' => ['response' => ['dataSchema' => self::BIG]],
            'create' => ['request' => ['payload' => self::BIG], 'response' => ['dataSchema' => self::BIG]],
            'bulkCreate' => ['request' => ['payload' => ['type' => 'array', 'maxItems' => 5, 'items' => self::BIG]]],
        ]);

        $this->assertSame(self::BIG, $actions['list']['response']['dataSchema']['items']);
        $this->assertSame(['$ref' => '#/actions/list/response/dataSchema/items'], $actions['read']['response']['dataSchema']);
        $this->assertSame(['$ref' => '#/actions/list/response/dataSchema/items'], $actions['create']['request']['payload']);
        $this->assertSame(['$ref' => '#/actions/list/response/dataSchema/items'], $actions['create']['response']['dataSchema']);
        $this->assertSame(['$ref' => '#/actions/list/response/dataSchema/items'], $actions['bulkCreate']['request']['payload']['items']);
    }

    public function test_a_wrapper_identical_to_an_earlier_one_is_referenced_whole(): void
    {
        $wrapper = ['type' => 'array', 'items' => self::BIG];
        $actions = SchemaDeduper::actions([
            'list' => ['response' => ['dataSchema' => $wrapper]],
            'bulkCreate' => ['request' => ['payload' => $wrapper]],
        ]);

        $this->assertSame(['$ref' => '#/actions/list/response/dataSchema'], $actions['bulkCreate']['request']['payload']);
    }

    public function test_small_or_different_subtrees_are_left_alone(): void
    {
        $small = ['type' => 'object'];
        $actions = SchemaDeduper::actions([
            'a' => ['request' => ['payload' => $small], 'response' => ['dataSchema' => self::BIG]],
            'b' => ['request' => ['payload' => $small], 'response' => ['dataSchema' => self::BIG + ['required' => ['alpha']]]],
        ]);

        $this->assertSame($small, $actions['b']['request']['payload']);
        $this->assertArrayNotHasKey('$ref', $actions['b']['response']['dataSchema']);
    }

    public function test_actions_without_schemas_pass_through(): void
    {
        $actions = ['restore' => ['method' => 'POST', 'request' => ['payload' => null]]];

        $this->assertSame($actions, SchemaDeduper::actions($actions));
    }

    public function test_operator_lists_are_referenced_by_position(): void
    {
        $text = ['eq', 'neq', 'in', 'not_in', 'like', 'ilike', 'contains', 'starts_with', 'ends_with'];
        $filters = SchemaDeduper::filters([
            ['field' => 'a', 'operators' => $text],
            ['field' => 'b', 'operators' => ['eq']],
            ['field' => 'c', 'operators' => $text],
            ['field' => 'd', 'operators' => ['eq']],
        ]);

        $this->assertSame($text, $filters[0]['operators']);
        $this->assertSame(['$ref' => '#/filters/0/operators'], $filters[2]['operators']);
        $this->assertSame(['eq'], $filters[3]['operators'], 'a short list is cheaper inline');
    }
}
