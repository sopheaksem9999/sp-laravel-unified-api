<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolResult;

/** @internal */
class McpValueObjectsTest extends TestCase
{
    /** @test */
    public function a_definition_serialises_the_legacy_keys_first_then_the_additive_ones(): void
    {
        $definition = new ToolDefinition(
            name: 'list_widgets',
            description: 'List records from widgets',
            inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'],
            action: 'list',
            table: 'widgets',
            title: 'List widgets',
            annotations: ['readOnlyHint' => true],
        );

        $this->assertSame(
            ['name', 'description', 'inputSchema', 'outputSchema', 'title', 'annotations'],
            array_keys($definition->toWireArray())
        );
        $this->assertSame('widgets', $definition->table);
    }

    /** @test */
    public function a_definition_without_title_or_annotations_serialises_only_the_legacy_keys(): void
    {
        $definition = new ToolDefinition('x', 'd', [], [], 'schema');

        $this->assertSame(['name', 'description', 'inputSchema', 'outputSchema'], array_keys($definition->toWireArray()));
    }

    /** @test */
    public function an_error_result_is_an_is_error_envelope_with_the_message(): void
    {
        $this->assertSame(
            ['isError' => true, 'content' => [['type' => 'text', 'text' => 'boom']]],
            ToolResult::error('boom')->toWireArray()
        );
    }

    /** @test */
    public function an_ok_result_carries_structured_content_and_a_text_copy(): void
    {
        $wire = ToolResult::ok(['a' => 1], ['a' => 1])->toWireArray();

        $this->assertSame(['a' => 1], $wire['structuredContent']);
        $this->assertSame('text', $wire['content'][0]['type']);
        $this->assertSame(['a' => 1], json_decode((string) $wire['content'][0]['text'], true));
    }

    /** @test */
    public function a_tool_error_defaults_to_the_internal_error_code(): void
    {
        $this->assertSame(-32603, (new ToolError('x'))->getCode());
        $this->assertSame(-32002, (new ToolError('Forbidden', -32002))->getCode());
    }
}
