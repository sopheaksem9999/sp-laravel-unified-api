<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\ResolvesRefs;
use Sopheak\Core\Tests\TestCase;

/**
 * Every tool result used to carry its data twice, the second copy
 * pretty-printed. The text copy is compact JSON now, and sp_api_get_endpoint
 * can return just the actions an agent asks for (spec §6.6 "Size", §9).
 */
class McpResponseSizeTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;
    use ResolvesRefs;

    private const FULL_BUDGET = 40_000;

    private const SUBSET_BUDGET = 12_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGuidanceFixture();
    }

    /** @return array<string, mixed> */
    private function wire(string $tool, array $arguments): array
    {
        return (new ToolExecutor())->call($tool, $arguments)->toWireArray();
    }

    public function test_text_copy_is_the_compact_json_of_the_structured_copy(): void
    {
        foreach ([['sp_api_get_endpoint', ['endpoint' => 'invoices']], ['sp_api_get_api_guidance', []]] as [$tool, $arguments]) {
            $wire = $this->wire($tool, $arguments);
            $text = $wire['content'][0]['text'];

            $this->assertStringNotContainsString("\n", $text, $tool);
            $this->assertEquals($wire['structuredContent'], json_decode((string) $text, true), $tool);
            $this->assertSame(json_encode($wire['structuredContent'], JSON_UNESCAPED_SLASHES), $text, $tool);
        }
    }

    public function test_full_endpoint_response_stays_under_the_budget(): void
    {
        $size = strlen((string) json_encode($this->wire('sp_api_get_endpoint', ['endpoint' => 'invoices'])));

        $this->assertLessThan(self::FULL_BUDGET, $size, sprintf('sp_api_get_endpoint invoices is %d bytes', $size));
    }

    public function test_action_subset_stays_under_the_small_budget(): void
    {
        $wire = $this->wire('sp_api_get_endpoint', ['endpoint' => 'invoices', 'actions' => ['list', 'create']]);
        $size = strlen((string) json_encode($wire));

        $this->assertSame(['list', 'create'], array_keys($wire['structuredContent']['actions']));
        $this->assertLessThan(self::SUBSET_BUDGET, $size, sprintf('subset is %d bytes', $size));
        $this->assertArrayHasKey('fields', $wire['structuredContent'], 'only actions are filtered');
        $this->assertArrayHasKey('includes', $wire['structuredContent']);
    }

    public function test_repeated_schemas_are_written_once_and_referenced(): void
    {
        $endpoint = $this->wire('sp_api_get_endpoint', ['endpoint' => 'invoices'])['structuredContent'];
        $actions = $endpoint['actions'];

        $this->assertArrayHasKey('properties', $actions['list']['response']['dataSchema']['items'], 'the first copy stays inline');
        $this->assertSame(['$ref' => '#/actions/list/response/dataSchema/items'], $actions['read']['response']['dataSchema']);
        $this->assertSame(['$ref' => '#/actions/create/request/payload'], $actions['bulkCreate']['request']['payload']['items']);
        $this->assertSame(['$ref' => '#/filters/1/operators'], $endpoint['filters'][3]['operators']);

        $resolved = $this->resolveRefs($endpoint);
        $this->assertSame($resolved['actions']['list']['response']['dataSchema']['items'], $resolved['actions']['restore']['response']['dataSchema']);
        $this->assertSame($resolved['filters'][1]['operators'], $resolved['filters'][3]['operators']);
        $this->assertSame($resolved['actions']['create']['request']['payload'], $resolved['actions']['bulkCreate']['request']['payload']['items']);
    }

    public function test_a_subset_never_points_at_an_action_it_left_out(): void
    {
        $endpoint = $this->wire('sp_api_get_endpoint', ['endpoint' => 'invoices', 'actions' => ['read', 'delete']])['structuredContent'];

        $this->assertArrayHasKey('properties', $endpoint['actions']['read']['response']['dataSchema'], 'the first requested action keeps the schema inline');
        $this->assertSame(['$ref' => '#/actions/read/response/dataSchema'], $endpoint['actions']['delete']['response']['dataSchema']);
        $this->resolveRefs($endpoint);
    }

    public function test_unknown_action_names_are_an_error_listing_the_available_ones(): void
    {
        $wire = $this->wire('sp_api_get_endpoint', ['endpoint' => 'invoices', 'actions' => ['list', 'nope']]);

        $this->assertTrue($wire['isError']);
        $this->assertStringContainsString('nope', $wire['content'][0]['text']);
        $this->assertStringContainsString('Available: list, read', $wire['content'][0]['text']);
    }

    public function test_actions_must_be_a_list_of_strings(): void
    {
        $wire = $this->wire('sp_api_get_endpoint', ['endpoint' => 'invoices', 'actions' => 'list']);

        $this->assertTrue($wire['isError']);
        $this->assertStringContainsString('actions', $wire['content'][0]['text']);
    }

    public function test_input_schema_declares_actions(): void
    {
        $tool = (new ToolCatalog())->schema()[1];

        $this->assertSame('sp_api_get_endpoint', $tool->name);
        $this->assertSame('array', $tool->inputSchema['properties']['actions']['type']);
        $this->assertSame('string', $tool->inputSchema['properties']['actions']['items']['type']);
        $this->assertSame(['endpoint'], $tool->inputSchema['required']);
    }

    public function test_data_tool_text_is_compact_too(): void
    {
        DB::table('customers')->insert(['id' => 1, 'name' => 'Acme', 'email' => null]);

        $wire = $this->wire('list_customers', []);

        $this->assertStringNotContainsString("\n", $wire['content'][0]['text']);
        $this->assertSame($wire['structuredContent']['response'], json_decode((string) $wire['content'][0]['text'], true));
    }
}
