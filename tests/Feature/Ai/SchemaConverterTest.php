<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Sopheak\Core\Mcp\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use RuntimeException;
use Sopheak\Core\Ai\SchemaConverter;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\TestCase;

/**
 * laravel/ai's own MCP bridge returns [] when a schema does not convert, which
 * makes a tool silently parameterless. Ours must throw instead (spec §7.2).
 *
 * @internal
 */
class SchemaConverterTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use UsesLaravelAi;

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();
        $this->buildGuidanceFixture();
    }

    public function test_every_catalog_tool_keeps_its_property_names_and_required_list(): void
    {
        $catalog = new ToolCatalog();
        $checked = 0;

        foreach ([...$catalog->schema(), ...$catalog->data(readOnly: false)] as $definition) {
            $properties = SchemaConverter::properties($definition->name, $definition->inputSchema);
            $converted = (new JsonSchemaTypeFactory())->object($properties)->toArray();

            $this->assertEqualsCanonicalizing(array_keys($definition->inputSchema['properties'] ?? []), array_keys($properties), $definition->name . ' properties');
            $this->assertEqualsCanonicalizing($definition->inputSchema['required'] ?? [], $converted['required'] ?? [], $definition->name . ' required');
            ++$checked;
        }

        $this->assertGreaterThan(20, $checked);
    }

    public function test_the_union_id_type_survives(): void
    {
        $definitions = array_column(array_map(static fn(ToolDefinition $d): array => ['name' => $d->name, 'definition' => $d], (new ToolCatalog())->data(readOnly: false)), 'definition', 'name');

        $id = SchemaConverter::properties('read_invoices', $definitions['read_invoices']->inputSchema)['id']->toArray();

        $this->assertSame(['string', 'integer'], $id['type']);
    }

    public function test_a_property_the_normalizer_drops_is_an_error_naming_the_tool(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Tool 'lossy_tool'");

        SchemaConverter::properties('lossy_tool', [
            'type' => 'object',
            'properties' => ['kept' => ['type' => 'string'], 'dropped' => true],
            'required' => ['kept'],
        ]);
    }

    public function test_a_non_object_root_is_an_error_naming_the_tool(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Tool 'scalar_tool'");

        SchemaConverter::properties('scalar_tool', ['type' => 'string']);
    }

    public function test_a_tool_without_parameters_has_none(): void
    {
        $this->assertSame([], SchemaConverter::properties('guidance', ['type' => 'object', 'additionalProperties' => false]));
    }

    public function test_open_objects_become_json_string_parameters(): void
    {
        // A provider turns an object with no declared properties into
        // {"type":"object","additionalProperties":false} (or rejects it), i.e. "send nothing".
        // Free-form objects therefore travel as JSON text and RecordTool decodes them.
        $definitions = array_column(array_map(static fn(ToolDefinition $d): array => ['name' => $d->name, 'definition' => $d], (new ToolCatalog())->data(readOnly: false)), 'definition', 'name');

        $create = SchemaConverter::properties('create_invoices', $definitions['create_invoices']->inputSchema);
        $list = SchemaConverter::properties('list_invoices', $definitions['list_invoices']->inputSchema);
        $update = SchemaConverter::properties('update_invoices', $definitions['update_invoices']->inputSchema);

        $this->assertSame('string', $create['payload']->toArray()['type']);
        $this->assertStringContainsString('JSON', $create['payload']->toArray()['description']);
        $this->assertSame('string', $create['queryParams']->toArray()['type']);
        $this->assertSame('string', $list['queryParams']->toArray()['type']);
        $this->assertStringContainsString("'operator.value'", $list['queryParams']->toArray()['description'], 'the catalog description is kept');
        $this->assertSame(['string', 'integer'], $update['id']->toArray()['type'], 'scalar unions are untouched');
        $this->assertSame(['payload'], (new JsonSchemaTypeFactory())->object($create)->toArray()['required']);
    }

    public function test_which_properties_travel_as_json_text(): void
    {
        $definitions = array_column(array_map(static fn(ToolDefinition $d): array => ['name' => $d->name, 'definition' => $d], (new ToolCatalog())->data(readOnly: false)), 'definition', 'name');

        $this->assertSame(['payload', 'queryParams'], SchemaConverter::jsonStringProperties($definitions['update_invoices']->inputSchema));
        $this->assertSame(['queryParams'], SchemaConverter::jsonStringProperties($definitions['list_invoices']->inputSchema));
        $this->assertSame([], SchemaConverter::jsonStringProperties(['type' => 'object', 'properties' => ['search' => ['type' => 'string']]]));
    }

    public function test_an_open_object_below_the_top_level_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Tool 'deep_tool'");
        $this->expectExceptionMessage('payload.meta');

        SchemaConverter::properties('deep_tool', [
            'type' => 'object',
            'properties' => ['payload' => ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]],
        ]);
    }

    public function test_a_loss_below_the_top_level_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Tool 'nested_lossy'");

        SchemaConverter::properties('nested_lossy', [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'object',
                    'properties' => ['kept' => ['type' => 'string'], 'dropped' => true],
                    'required' => ['kept'],
                ],
            ],
        ]);
    }
}
