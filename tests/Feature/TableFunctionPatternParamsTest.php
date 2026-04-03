<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class TableFunctionPatternParamsTest extends TestCase
{
    /** @test */
    public function it_matches_non_numeric_table_function_patterns_and_forwards_all_route_params(): void
    {
        Config::set('record.tables', [
            'attachments' => new RecordTableType(
                table: 'attachments',
                pmsName: 'attachments',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'string', 'nullable' => false],
                ],
                functions: [
                    'record/{table}/{record_id}/{attachment_id}' => new RecordFunctionType(
                        httpMethod: ['GET'],
                        class: TableFunctionPatternHandler::class,
                        functionName: 'handle',
                        isPublic: true
                    ),
                ]
            ),
        ]);

        SchemaRegistryUtils::refresh();

        $response = $this->getJson('/api/attachments/rpc/record/invoices/inv-001/550e8400-e29b-41d4-a716-446655440000');

        $response->assertOk();
        $response->assertJsonPath('data.table', 'invoices');
        $response->assertJsonPath('data.record_id', 'inv-001');
        $response->assertJsonPath('data.attachment_id', '550e8400-e29b-41d4-a716-446655440000');
    }
}

class TableFunctionPatternHandler
{
    public function handle(Request $request, string $table, string $recordId, string $attachmentId): array
    {
        return [
            'table' => $table,
            'record_id' => $recordId,
            'attachment_id' => $attachmentId,
            'method' => $request->method(),
        ];
    }
}
