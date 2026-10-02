<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Sopheak\Core\Mcp\Guidance\ApiReference;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\TestCase;

/**
 * The shared reference in sp_api_get_api_guidance (spec §6.6: M1–M4, C1, C2,
 * C5, C7, C8, P). Each block is derived from config, routes and the registry.
 */
class McpGuidanceReferenceTest extends TestCase
{
    use BuildsGuidanceFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGuidanceFixture();
    }

    /** @return array<string, mixed> */
    private function guidance(): array
    {
        return app(SchemaTools::class)->apiGuidance();
    }

    public function test_headers_name_the_configured_tenant_header_only_with_tenancy(): void
    {
        $this->assertSame(['Authorization', 'Accept', 'Content-Type'], array_keys($this->guidance()['headers']));

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_header', 'X-Org');
        $headers = $this->guidance()['headers'];

        $this->assertArrayHasKey('X-Org', $headers);
        $this->assertArrayNotHasKey('X-Tenant-ID', $headers);
    }

    public function test_query_syntax_covers_the_hidden_features(): void
    {
        $syntax = $this->guidance()['querySyntax'];

        foreach (['filters', 'relationshipFilters', 'negation', 'modifiers', 'grouped', 'substring', 'selectVsWith', 'search', 'trashed'] as $key) {
            $this->assertArrayHasKey($key, $syntax, $key);
        }

        $this->assertStringContainsString('do not add %', $syntax['substring']);
        $this->assertStringContainsString('filter[', $syntax['filters']);
        $this->assertStringNotContainsString('%acme%', json_encode($this->guidance()['querySyntaxExamples']));
    }

    public function test_search_and_trash_syntax_appear_only_when_some_table_supports_them(): void
    {
        Config::set('record.tables', [
            'plain' => new RecordTableType(table: 'plain', pmsName: 'plain'),
        ]);
        SchemaRegistryUtils::refresh();

        $syntax = $this->guidance()['querySyntax'];

        $this->assertArrayNotHasKey('search', $syntax);
        $this->assertArrayNotHasKey('trashed', $syntax);
    }

    public function test_operator_catalogue_follows_the_driver(): void
    {
        $sqlite = array_column($this->guidance()['operators']['catalogue'], 'name');
        $this->assertContains('ilike', $sqlite);
        foreach (['regex', 'match', 'fts', 'cs'] as $foreign) {
            $this->assertNotContains($foreign, $sqlite);
        }

        $pgsql = array_column((new ApiReference())->build('pgsql')['operators']['catalogue'], 'name');
        foreach (['fts', 'cs', 'regex', 'adj'] as $operator) {
            $this->assertContains($operator, $pgsql);
        }

        $this->assertSame('pgsql', (new ApiReference())->build('pgsql')['operators']['driver']);
    }

    public function test_negation_guidance_lists_exactly_what_can_be_negated(): void
    {
        $operators = $this->guidance()['operators'];
        $catalogue = array_column($operators['catalogue'], 'name');

        $this->assertContains('eq', $operators['negatable']);
        $this->assertContains('contains', $operators['notNegatable']);
        $this->assertContains('starts_with', $operators['notNegatable']);
        $this->assertSame([], array_intersect($operators['negatable'], $operators['notNegatable']));
        $this->assertEqualsCanonicalizing($catalogue, [...$operators['negatable'], ...$operators['notNegatable']]);
        $this->assertStringContainsString('not.', $operators['negation']);
        $this->assertStringContainsString('ignored', $operators['negation'], 'a not. prefix on an operator without one is dropped by the API');
    }

    public function test_pagination_reports_live_limits_and_options(): void
    {
        Config::set('record.limit_max', 77);
        Config::set('record.per_page_max', 55);
        $pagination = $this->guidance()['pagination'];

        $this->assertSame(77, $pagination['limit']['max']);
        $this->assertSame(55, $pagination['page']['max']);
        $this->assertStringContainsString('skip_total=true', $pagination['totals']['skip']);
        $this->assertStringContainsString('add_total=true', $pagination['totals']['add']);
        $this->assertStringContainsString('cursor_column', $pagination['cursor']['note']);
        $this->assertStringContainsString('direction', $pagination['cursor']['syntax']);
    }

    public function test_boundary_cursors_follow_their_config(): void
    {
        Config::set('record.pagination.cursor.boundary_cursors', true);
        $this->assertStringContainsString('boundary_cursors=false', $this->guidance()['pagination']['boundaryCursors']);

        Config::set('record.pagination.cursor.boundary_cursors', false);
        $this->assertStringContainsString('not computed', $this->guidance()['pagination']['boundaryCursors']);
    }

    public function test_headers_text_says_a_missing_block_means_nothing_to_send(): void
    {
        $this->assertStringContainsString('no headers block', $this->guidance()['headers']['Authorization']);
    }

    public function test_the_validation_note_lives_in_the_guidance(): void
    {
        $this->assertStringContainsString('validation.defaults.enabled', $this->guidance()['validation']['defaults']);
    }

    public function test_rate_limits_follow_the_registered_limiters(): void
    {
        RateLimiter::for('api-writes', fn() => Limit::perMinute(7));

        $limits = $this->guidance()['rateLimits'];

        $this->assertSame(7, $limits['api-writes']['limit']);
        $this->assertSame('api-reads', $limits['api-reads']['group']);
        $this->assertStringContainsString('Retry-After', $limits['onThrottle']);
    }

    public function test_docs_urls_are_the_registered_routes(): void
    {
        $docs = $this->guidance()['docs'];

        $this->assertSame('/api/docs/openapi.json', $docs['openapi']);
        $this->assertSame('/api/docs/llms.txt', $docs['llms']);
        $this->getJson($docs['openapi'])->assertSuccessful();
    }

    public function test_docs_are_omitted_when_their_routes_are_not_registered(): void
    {
        // The routes were registered under the "api" prefix at boot; pointing the
        // config elsewhere must not make the guidance invent URLs that 404.
        Config::set('record.api_prefix', 'elsewhere');

        $this->assertArrayNotHasKey('docs', $this->guidance());
    }

    public function test_docs_are_omitted_when_the_app_makes_them_private(): void
    {
        Config::set('record.api_docs.is_private', true);

        $this->assertArrayNotHasKey('docs', $this->guidance());
    }

    public function test_realtime_only_when_enabled(): void
    {
        $this->assertArrayNotHasKey('realtime', $this->guidance());

        Config::set('record.broadcast_events', true);
        Config::set('record.broadcast_tables', ['invoices']);
        $realtime = $this->guidance()['realtime'];

        $this->assertSame('private-tenant.{tenantId}', $realtime['channel']);
        $this->assertStringContainsString("Echo.private('tenant.42')", $realtime['listen']);
        $this->assertSame('tenant.global', $realtime['noTenant']);
        $this->assertStringContainsString('{table}.{action}', $realtime['event']);
        $this->assertSame(['invoices'], $realtime['tables']);

        Config::set('record.broadcast_tables', []);
        $this->assertSame('all', $this->guidance()['realtime']['tables']);
    }

    public function test_recommendations_are_true_for_this_app(): void
    {
        Config::set('queue.default', 'sync');
        Config::set('record.cache.enabled', false);
        Config::set('record.bulk_max', 33);
        Config::set('record.limit_max', 44);
        $text = json_encode($this->guidance()['recommendations']);

        $this->assertStringNotContainsStringIgnoringCase('fts', $text, 'no full-text advice on sqlite');
        $this->assertStringNotContainsString('async', $text, 'no async advice without a queue');
        $this->assertStringContainsString('up to 33 items', $text);
        $this->assertStringContainsString('max 44', $text);
        $this->assertStringNotContainsString('cache', strtolower($text), 'cache advice only when the cache is on');

        Config::set('queue.default', 'database');
        Config::set('record.cache.enabled', true);
        $text = json_encode($this->guidance()['recommendations']);
        $this->assertStringContainsString('async=true', $text);
        $this->assertStringContainsString('cache', strtolower($text));

        Config::set('record.bulk_operations', false);
        $this->assertStringNotContainsString('bulk endpoints', json_encode($this->guidance()['recommendations']));

        $pgsql = json_encode((new ApiReference())->build('pgsql')['recommendations']);
        $this->assertStringContainsString('fts', $pgsql);
    }

    public function test_recommendations_quote_the_live_limits(): void
    {
        RateLimiter::for('api-reads', fn() => Limit::perMinute(321));

        $this->assertStringContainsString('api-reads 321 per 60 s', json_encode($this->guidance()['recommendations']));
    }

    public function test_nested_write_rules_are_stated(): void
    {
        $rules = implode(' ', $this->guidance()['nestedWrites']['rules']);

        foreach (['_delete', 'transaction', 'audit log', 'permission', 'canCreate', 'bare id'] as $needle) {
            $this->assertStringContainsString($needle, $rules, $needle);
        }
    }

    public function test_the_wire_value_of_every_guidance_key_has_its_declared_type(): void
    {
        $declared = null;
        foreach ((new ToolCatalog())->schema() as $tool) {
            if ('sp_api_get_api_guidance' === $tool->name) {
                $declared = $tool->outputSchema['properties'];
            }
        }

        // A JSON client validates structuredContent as it arrives, where an empty
        // PHP array is `[]` and not `{}`.
        Config::set('record.broadcast_events', true);
        $wire = json_decode((string) json_encode($this->guidance()), false, 512, JSON_THROW_ON_ERROR);

        foreach ($declared as $key => $schema) {
            if (!property_exists($wire, $key)) {
                continue;
            }

            $expected = match ($schema['type']) {
                'object' => is_object($wire->{$key}),
                'array' => is_array($wire->{$key}),
                'string' => is_string($wire->{$key}),
                default => true,
            };
            $this->assertTrue($expected, sprintf("'%s' is declared %s but arrives as %s", $key, $schema['type'], get_debug_type($wire->{$key})));
        }
    }

    public function test_modules_is_left_out_when_no_module_is_enabled(): void
    {
        $this->assertArrayNotHasKey('modules', $this->guidance());
    }

    public function test_output_schema_declares_every_key_the_guidance_emits(): void
    {
        $schema = ToolCatalog::class;
        $definition = null;
        foreach ((new ToolCatalog())->schema() as $tool) {
            if ('sp_api_get_api_guidance' === $tool->name) {
                $definition = $tool->outputSchema;
            }
        }

        $this->assertIsArray($definition, $schema);
        Config::set('record.broadcast_events', true);
        $guidance = $this->guidance();

        foreach (array_keys($guidance) as $key) {
            $this->assertArrayHasKey($key, $definition['properties'], $key);
        }

        foreach ($definition['required'] as $key) {
            $this->assertArrayHasKey($key, $guidance, $key);
        }
    }
}
