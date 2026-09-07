<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Contracts\OpenApiDocumentContributorInterface;
use Sopheak\Core\Exceptions\OpenApiContributionException;
use Sopheak\Core\Services\OpenApiDocumentBuilder;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class OpenApiContributionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_documents_the_effective_package_broadcast_contract(): void
    {
        Config::set('record.broadcast_events', true);
        Config::set('record.broadcast_tables', ['invoices', 'audit_snapshots']);
        Config::set('sp-laravel-api.openapi.realtime.enabled', true);
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
            'audit_snapshots' => new RecordTableType(
                table: 'audit_snapshots',
                disableBroadcast: true,
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $spec = OpenApiService::generateInternal();

        $this->assertSame('1.0', $spec['x-sp-realtime']['version']);
        $this->assertSame('tenant.{tenantId}', $spec['x-sp-realtime']['channels'][0]['pattern']);
        $this->assertSame('{table}.{action}', $spec['x-sp-realtime']['channels'][0]['events'][0]['pattern']);
        $this->assertSame(
            ['invoices'],
            $spec['components']['schemas']['RecordMutated']['properties']['table']['enum'],
        );
        $this->assertSame(
            ['table', 'action', 'record', 'tenant_id', 'timestamp'],
            $spec['components']['schemas']['RecordMutated']['required'],
        );
    }

    /** @test */
    public function it_leaves_realtime_metadata_out_by_default(): void
    {
        Config::set('record.broadcast_events', true);
        Config::set('sp-laravel-api.openapi.realtime', [
            'enabled' => false,
            'channels' => [],
        ]);

        $spec = OpenApiService::generateInternal();

        $this->assertArrayNotHasKey('x-sp-realtime', $spec);
        $this->assertArrayNotHasKey('RecordMutated', $spec['components']['schemas']);
    }

    /** @test */
    public function it_appends_declared_application_documentation(): void
    {
        Config::set('sp-laravel-api.openapi.contributions', [
            'tags' => [['name' => 'Reports', 'description' => 'Application reporting routes.']],
            'paths' => ['/api/v1/reports/monthly' => $this->monthlyReportPathItem()],
            'components' => ['schemas' => ['MonthlyReport' => ['type' => 'object']]],
            'extensions' => ['x-client-documentation' => ['owner' => 'reports']],
            'contributors' => [],
        ]);

        $spec = OpenApiService::generateInternal();

        $this->assertArrayHasKey('/api/v1/reports/monthly', $spec['paths']);
        $this->assertSame(['Reports'], $spec['paths']['/api/v1/reports/monthly']['get']['tags']);
        $this->assertArrayHasKey('MonthlyReport', $spec['components']['schemas']);
        $this->assertSame(['owner' => 'reports'], $spec['x-client-documentation']);
    }

    /** @test */
    public function it_rejects_a_contribution_that_overwrites_a_package_path(): void
    {
        Config::set('record.api_prefix', 'api/v1');
        Config::set('record.tables', [
            'widgets' => new RecordTableType(
                table: 'widgets',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);
        Config::set('sp-laravel-api.openapi.contributions.paths', [
            '/api/v1/widgets' => $this->monthlyReportPathItem(),
        ]);
        SchemaRegistryUtils::refresh();

        $this->expectException(OpenApiContributionException::class);
        $this->expectExceptionMessage('paths./api/v1/widgets conflicts');

        OpenApiService::generateInternal();
    }

    /** @test */
    public function it_appends_an_explicit_application_realtime_channel(): void
    {
        Config::set('record.broadcast_events', true);
        Config::set('sp-laravel-api.openapi.realtime', [
            'enabled' => true,
            'channels' => [[
                'name' => 'booking-status',
                'pattern' => 'booking.{bookingId}',
                'private' => true,
                'parameters' => ['bookingId' => ['schema' => ['type' => 'string']]],
                'events' => [[
                    'name' => 'booking.status.updated',
                    'payload' => ['$ref' => '#/components/schemas/BookingStatusUpdated'],
                ]],
            ]],
        ]);
        Config::set('sp-laravel-api.openapi.contributions.components.schemas.BookingStatusUpdated', ['type' => 'object']);

        $spec = OpenApiService::generateInternal();

        $this->assertSame('booking-status', $spec['x-sp-realtime']['channels'][1]['name']);
    }

    /** @test */
    public function it_rejects_a_channel_with_undeclared_pattern_parameter(): void
    {
        Config::set('record.broadcast_events', true);
        Config::set('sp-laravel-api.openapi.realtime', [
            'enabled' => true,
            'channels' => [[
                'name' => 'booking-status',
                'pattern' => 'booking.{bookingId}',
                'private' => true,
                'parameters' => [],
                'events' => [['name' => 'booking.updated', 'payload' => ['type' => 'object']]],
            ]],
        ]);

        $this->expectException(OpenApiContributionException::class);
        $this->expectExceptionMessage('parameters must exactly match pattern placeholders');

        OpenApiService::generateInternal();
    }

    /** @test */
    public function it_resolves_a_class_string_contributor_through_the_container(): void
    {
        Config::set('sp-laravel-api.openapi.contributions.contributors', [TestReportContributor::class]);

        $spec = OpenApiService::generateInternal();

        $this->assertArrayHasKey('/api/v1/reports/from-contributor', $spec['paths']);
    }

    /** @test */
    public function it_rejects_a_contribution_with_an_unresolved_component_reference(): void
    {
        Config::set('sp-laravel-api.openapi.contributions.paths', [
            '/api/v1/reports/monthly' => [
                'get' => [
                    'summary' => 'Get monthly report',
                    'operationId' => 'getMonthlyReport',
                    'responses' => [
                        '200' => [
                            'description' => 'Monthly report.',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/MissingReport'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(OpenApiContributionException::class);
        $this->expectExceptionMessage('#/components/schemas/MissingReport');

        OpenApiService::generateInternal();
    }

    /** @return array<string, mixed> */
    private function monthlyReportPathItem(): array
    {
        return [
            'get' => [
                'tags' => ['Reports'],
                'summary' => 'Get monthly report',
                'operationId' => 'getMonthlyReport',
                'responses' => ['200' => ['description' => 'Monthly report.']],
            ],
        ];
    }
}

class TestReportContributor implements OpenApiDocumentContributorInterface
{
    public function contribute(OpenApiDocumentBuilder $document): void
    {
        $document->addPath('/api/v1/reports/from-contributor', [
            'get' => [
                'tags' => ['Reports'],
                'summary' => 'Get contributor report',
                'operationId' => 'getContributorReport',
                'responses' => ['200' => ['description' => 'Contributor report.']],
            ],
        ]);
    }
}
