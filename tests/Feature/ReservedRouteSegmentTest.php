<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The dynamic `{table}` routes must not swallow path segments this package
 * registers as literals.
 *
 * Reported 2026-08-14: `GET /api/v1/mcp/schema` returned
 * `Dynamic Table [mcp] not found.` because only `POST mcp/schema` is
 * registered, so the GET fell through to `{table}/{id}` and the route binding
 * reported a missing table. The message pointed at an unregistered route when
 * the real problem was a method mismatch, and it cost the reporter a round trip
 * across two environments before they filed it.
 */
class ReservedRouteSegmentTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Registered before the route file is evaluated, so the literal
        // mcp/schema route exists and a wrong-verb request can reach 405.
        $app['config']->set('sp-api-mcp.enabled', true);
        $app['config']->set('sp-api-mcp.token', null);
        $app['config']->set('record.tables', [
            'mcp_logs' => new RecordTableType(
                table: 'mcp_logs',
                pmsName: 'mcp_logs',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('mcp_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('message');
            $table->timestamps();
        });

        Config::set('record.cache.enabled', false);
        SchemaRegistryUtils::refresh();
    }

    /**
     * The reported case: a real path with the wrong verb must not be reported
     * as a missing dynamic table.
     */
    public function test_wrong_verb_on_a_reserved_segment_is_not_reported_as_a_missing_table(): void
    {
        $response = $this->getJson('/api/mcp/schema');

        $this->assertStringNotContainsString(
            'Dynamic Table [mcp] not found',
            (string) $response->getContent(),
            'GET on a POST-only MCP route must not be misreported as an unknown dynamic table'
        );
        $this->assertSame(
            405,
            $response->getStatusCode(),
            'the path exists but not for this verb, so Laravel should answer 405 Method Not Allowed'
        );
    }

    /** The registered verb must still work. */
    public function test_the_reserved_segment_still_serves_its_own_route(): void
    {
        $response = $this->postJson('/api/mcp/schema', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertNotSame(404, $response->getStatusCode(), 'POST mcp/schema must still route to the MCP controller');
        $this->assertStringNotContainsString('Dynamic Table', (string) $response->getContent());
    }

    /** `docs` is a literal prefix too and must be excluded on the same grounds. */
    public function test_docs_segment_is_not_treated_as_a_dynamic_table(): void
    {
        $response = $this->getJson('/api/docs/not-a-real-doc');

        $this->assertStringNotContainsString(
            'Dynamic Table [docs] not found',
            (string) $response->getContent()
        );
    }

    /**
     * The exclusion is anchored on the segment boundary, so a table whose name
     * merely STARTS with a reserved word keeps working.
     */
    public function test_a_table_named_after_a_reserved_prefix_still_routes(): void
    {
        $this->getJson('/api/mcp_logs')->assertStatus(200);
    }

    /** A genuinely unknown table must keep its helpful message. */
    public function test_an_unknown_table_still_reports_a_missing_dynamic_table(): void
    {
        $response = $this->getJson('/api/definitely_not_a_table/1');

        $response->assertStatus(404);
        $this->assertStringContainsString(
            'Dynamic Table [definitely_not_a_table] not found',
            (string) $response->getContent(),
            'the diagnostic for a real typo must survive'
        );
    }
}
