<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Only /bulk/upsert was ever emitted, so /bulk/create, /bulk/update and
 * /bulk/delete were missing from the spec and therefore from every exported
 * Postman/Bruno collection — nobody could generate a working client for them,
 * which is largely why the documented request shape went unverified for so long.
 *
 * @internal
 */
class OpenApiBulkPathsTest extends TestCase
{
    private function spec(bool $bulkEnabled = true, bool $canDelete = true): array
    {
        Config::set('record.bulk_operations', $bulkEnabled);
        Config::set('record.tables', ['widgets' => new RecordTableType(
            table: 'widgets',
            pmsName: 'widget',
            hasTenantId: false,
            canDelete: $canDelete,
            canUpsert: true,
            public: new RecordTablePublic(read: true, write: true),
            columns: [
                'id' => ['type' => 'integer', 'nullable' => false],
                'name' => ['type' => 'string', 'nullable' => false],
            ],
        )]);
        SchemaRegistryUtils::refresh();

        return OpenApiService::generateInternal();
    }

    /** @test */
    public function all_bulk_paths_are_documented(): void
    {
        $paths = $this->spec()['paths'] ?? [];

        foreach (['create', 'update', 'delete', 'upsert'] as $operation) {
            $this->assertArrayHasKey(
                '/api/widgets/bulk/' . $operation,
                $paths,
                sprintf('bulk/%s must appear in the OpenAPI spec', $operation)
            );
        }
    }

    /** @test */
    public function bulk_bodies_advertise_both_the_envelope_and_the_bare_array(): void
    {
        $paths = $this->spec()['paths'] ?? [];

        $create = $paths['/api/widgets/bulk/create']['post']['requestBody']['content']['application/json']['schema'] ?? [];
        $this->assertArrayHasKey('oneOf', $create);
        $this->assertSame(['data'], $create['oneOf'][0]['required'] ?? null);
        $this->assertSame('array', $create['oneOf'][1]['type'] ?? null);

        $delete = $paths['/api/widgets/bulk/delete']['post']['requestBody']['content']['application/json']['schema'] ?? [];
        $this->assertSame(['ids'], $delete['oneOf'][0]['required'] ?? null);
    }

    /** @test */
    public function bulk_paths_disappear_when_bulk_operations_are_disabled(): void
    {
        $paths = $this->spec(bulkEnabled: false)['paths'] ?? [];

        foreach (['create', 'update', 'delete'] as $operation) {
            $this->assertArrayNotHasKey('/api/widgets/bulk/' . $operation, $paths);
        }
    }

    /** @test */
    public function bulk_delete_is_omitted_when_the_table_cannot_be_deleted(): void
    {
        $paths = $this->spec(canDelete: false)['paths'] ?? [];

        $this->assertArrayNotHasKey('/api/widgets/bulk/delete', $paths);
        $this->assertArrayHasKey('/api/widgets/bulk/create', $paths);
    }
}
