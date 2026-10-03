<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * A table that is not tenant-scoped can still embed rows of a tenant-scoped
 * one (`owners` -> `pets`). With no tenant resolved, those included rows used to
 * come back for every tenant, and a `tenant_id` the model slipped into
 * `queryParams` was taken as the tenant. Tool arguments never choose the
 * tenant, so such a call is refused until a tenant is resolved.
 *
 * @internal
 */
class McpTenantIncludesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('owners', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('pets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('owner_id');
            $t->string('tenant_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        DB::table('owners')->insert(['id' => 1, 'name' => 'Pat']);
        DB::table('pets')->insert([
            ['id' => 1, 'owner_id' => 1, 'tenant_id' => 't1', 'name' => 'MINE'],
            ['id' => 2, 'owner_id' => 1, 'tenant_id' => 't2', 'name' => 'THEIRS'],
        ]);

        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.enable_tenant_id', true);
        Config::set('record.mcp.read_only', false);
        Config::set('record.tables', [
            'owners' => new RecordTableType(
                table: 'owners',
                pmsName: 'owners',
                public: $public,
                relationships: ['pets' => new RecordHasManyType(table: 'pets', foreignKey: 'owner_id')],
            ),
            'pets' => new RecordTableType(table: 'pets', pmsName: 'pets', hasTenantId: true, public: $public),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<int, array<string, mixed>>
     */
    private function owners(array $queryParams): array
    {
        $result = (new ToolExecutor())->call('list_owners', ['queryParams' => $queryParams]);

        return $result->structuredContent['response']['data'];
    }

    /**
     * @param array<string, string[]|string> $queryParams
     */
    #[DataProvider('includeForms')]
    public function test_without_a_tenant_including_tenant_scoped_rows_is_refused(array $queryParams): void
    {
        try {
            $this->owners($queryParams);
            $this->fail('an include of tenant-scoped rows needs a tenant');
        } catch (ToolError $toolError) {
            $this->assertSame(-32001, $toolError->getCode());
            $this->assertStringContainsString('Tenant context is required', $toolError->getMessage());
            $this->assertStringContainsString('pets', $toolError->getMessage());
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function includeForms(): array
    {
        return [
            'select with a nested include' => [['select' => '*,pets(*)']],
            'select with columns in the include' => [['select' => 'id,name,pets(id,name)']],
            'with' => [['with' => 'pets(*)']],
            'with in a list' => [['with' => ['pets(*)']]],
            'an aliased include' => [['select' => '*,pets:anything(*)']],
            'a grouped relationship filter' => [['or' => '(pets.name.eq.THEIRS,name.eq.Pat)']],
            'a relationship filter' => [['pets.name' => 'eq.THEIRS']],
            'a tenant the model picked' => [['select' => '*,pets(*)', 'tenant_id' => 't2']],
        ];
    }

    public function test_without_a_tenant_the_table_itself_still_reads_normally(): void
    {
        $this->assertSame(['Pat'], array_column($this->owners([]), 'name'));
        $this->assertSame(['Pat'], array_column($this->owners(['select' => 'id,name']), 'name'));
        // A bare `with` name is a column, not an include, so it embeds nothing.
        $this->assertSame(['Pat'], array_column($this->owners(['with' => 'pets']), 'name'));
    }

    public function test_with_the_request_tenant_only_that_tenants_rows_are_included_whatever_the_model_asks_for(): void
    {
        request()->attributes->set('resolved_tenant_id', 't1');

        $own = $this->owners(['select' => '*,pets(*)']);
        $picked = $this->owners(['select' => '*,pets(*)', 'tenant_id' => 't2']);

        $this->assertSame(['MINE'], array_column($own[0]['pets'], 'name'));
        $this->assertSame(['MINE'], array_column($picked[0]['pets'], 'name'), 'a tenant_id argument does not move the scope');
    }

    public function test_with_the_request_tenant_a_relationship_filter_sees_only_that_tenant(): void
    {
        request()->attributes->set('resolved_tenant_id', 't1');

        $this->assertSame([], $this->owners(['pets.name' => 'eq.THEIRS']));
        $this->assertSame([], $this->owners(['or' => '(pets.name.eq.THEIRS,name.eq.Nobody)']));
        $this->assertSame(['Pat'], array_column($this->owners(['pets.name' => 'eq.MINE']), 'name'));
    }

    public function test_query_params_sent_as_a_string_keep_relationship_filters(): void
    {
        request()->attributes->set('resolved_tenant_id', 't1');

        $result = (new ToolExecutor())->call('list_owners', ['queryParams' => 'pets.name=eq.NONE']);

        $this->assertSame([], $result->structuredContent['response']['data'], 'the filter applies, it is not dropped as `pets_name`');
    }
}
