<?php

namespace Sopheak\Core\Tests\Unit;

use Exception;
use ReflectionMethod;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use PDO;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Sopheak\Core\Console\SyncRecordColumnsCommand;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Http\Controllers\CoreRecordController;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Types\RecordValidationType;


class BasicTest extends TestCase
{
    /** @test */
    public function it_can_run_basic_test(): void
    {
        $this->assertTrue(true);
    }

    /** @test */
    public function it_has_laravel_application(): void
    {
        $this->assertNotNull($this->app);
    }

    /** @test */
    public function it_can_access_config(): void
    {
        $this->assertIsArray(config('record.tables'));
    }

    /** @test */
    public function it_enables_function_cache_by_default(): void
    {
        $function = new RecordFunctionType(
            httpMethod: 'GET',
            class: TestTriggerHandler::class,
            functionName: 'handle'
        );

        $this->assertFalse($function->disableCache);

        $fromArray = RecordFunctionType::fromArray([
            'httpMethod' => 'GET',
            'class' => TestTriggerHandler::class,
            'functionName' => 'handle',
        ]);

        $this->assertFalse($fromArray->disableCache);
    }

    /** @test */
    public function it_serves_openapi_json_without_authentication(): void
    {
        $matchedRoute = app('router')->getRoutes()->match(Request::create('/api/docs/openapi', 'GET'));
        $this->assertSame('Closure', $matchedRoute->getActionName());

        $testResponse = $this->getJson('/api/docs/openapi');

        $testResponse
            ->assertStatus(200)
            ->assertJsonStructure(['openapi', 'info', 'paths', 'components']);
    }

    /** @test */
    public function it_serves_ai_friendly_openapi_json_endpoint(): void
    {
        $testResponse = $this->get('/api/docs/openapi.json');

        $testResponse
            ->assertStatus(200)
            ->assertJsonStructure(['openapi', 'info', 'paths', 'components']);

        $this->assertStringContainsString('application/vnd.oai.openapi+json', (string) $testResponse->headers->get('content-type'));
    }

    /** @test */
    public function it_serves_docs_group_openapi_json_endpoint(): void
    {
        $testResponse = $this->get('/api/docs/openapi.json');

        $testResponse
            ->assertStatus(200)
            ->assertJsonStructure(['openapi', 'info', 'paths', 'components']);
    }

    /** @test */
    public function it_serves_llms_mdx_endpoint_for_ai_agents(): void
    {
        $testResponse = $this->get('/api/docs/llms.mdx');

        $testResponse->assertStatus(200);
        $this->assertStringContainsString('text/markdown', (string) $testResponse->headers->get('content-type'));
        $this->assertStringContainsString('/api/docs/openapi.json', (string) $testResponse->getContent());
    }

    /** @test */
    public function it_serves_docs_group_llms_mdx_endpoint_for_ai_agents(): void
    {
        $testResponse = $this->get('/api/docs/llms.mdx');

        $testResponse->assertStatus(200);
        $this->assertStringContainsString('text/markdown', (string) $testResponse->headers->get('content-type'));
        $this->assertStringContainsString('/api/docs/openapi.json', (string) $testResponse->getContent());
    }

    /** @test */
    public function it_throws_when_table_trigger_class_does_not_exist(): void
    {
        $service = new RecordService();
        $request = Request::create('/test', 'GET');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Table trigger class 'App\\DoesNotExist\\Trigger' does not exist");

        $service->executeTableTrigger(
            [
                [
                    'class' => 'App\\DoesNotExist\\Trigger',
                    'functionName' => 'handle',
                ],
            ],
            [$request, 'users', []]
        );
    }

    /** @test */
    public function it_executes_table_trigger_and_merges_array_result_into_request(): void
    {
        $service = new RecordService();
        $request = Request::create('/test', 'GET');

        $params = $service->executeTableTrigger(
            [
                [
                    'class' => TestTriggerHandler::class,
                    'functionName' => 'handle',
                ],
            ],
            [$request, 'users', []]
        );

        $this->assertInstanceOf(Request::class, $params[0]);
        $this->assertSame('bar', $params[0]->get('foo'));
    }

    /** @test */
    public function it_executes_table_trigger_when_wrapped_in_array_of_objects(): void
    {
        $service = new RecordService();
        $request = Request::create('/test', 'GET');

        $params = $service->executeTableTrigger(
            [
                [
                    new RecordTableTriggerType(
                        class: TestTriggerHandler::class,
                        functionName: 'handle'
                    ),
                ],
            ],
            [$request, 'users', []]
        );

        $this->assertInstanceOf(Request::class, $params[0]);
        $this->assertSame('bar', $params[0]->get('foo'));
    }

    /** @test */
    public function it_throws_http_response_exception_when_trigger_returns_json_response(): void
    {
        $service = new RecordService();
        $request = Request::create('/test', 'GET');

        try {
            $service->executeTableTrigger(
                [
                    [
                        'class' => TestTriggerResponseHandler::class,
                        'functionName' => 'handle',
                    ],
                ],
                [$request, 'users', []]
            );
            $this->fail('Expected HttpResponseException to be thrown.');
        } catch (HttpResponseException $httpResponseException) {
            $response = $httpResponseException->getResponse();
            $data = json_decode((string) $response->getContent(), true);

            $this->assertSame((int) RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $response->getStatusCode());
            $this->assertSame(false, $data['success']);
            $this->assertSame('Not allowed', $data['message']);
            $this->assertSame(['message' => 'Not allowed'], $data['errors']);
        }
    }

    /** @test */
    public function it_runs_table_validators_when_wrapped_in_array_of_objects(): void
    {
        $controller = new CoreRecordController(new RecordService());
        $request = Request::create('/test', 'POST', ['name' => 'Example']);

        $method = new ReflectionMethod(CoreRecordController::class, 'runTableValidators');

        $result = $method->invoke(
            $controller,
            [
                [
                    new RecordValidationType(
                        class: TestValidationHandler::class,
                        functionName: 'handle'
                    ),
                ],
            ],
            $request,
            null
        );

        $this->assertNull($result);
    }

    /** @test */
    public function it_runs_table_validators_with_callable_array_config(): void
    {
        $controller = new CoreRecordController(new RecordService());
        $request = Request::create('/test', 'POST', ['name' => 'Example']);

        $method = new ReflectionMethod(CoreRecordController::class, 'runTableValidators');

        $result = $method->invoke(
            $controller,
            TestValidationHandler::handle(...),
            $request,
            null
        );

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_error_when_any_validator_in_nested_array_fails(): void
    {
        $controller = new CoreRecordController(new RecordService());
        $request = Request::create('/test', 'POST', ['name' => 'Example']);

        $method = new ReflectionMethod(CoreRecordController::class, 'runTableValidators');

        $result = $method->invoke(
            $controller,
            [
                [
                    new RecordValidationType(
                        class: TestValidationHandler::class,
                        functionName: 'handle'
                    ),
                    new RecordValidationType(
                        class: TestFailValidationHandler::class,
                        functionName: 'handle'
                    ),
                ],
            ],
            $request,
            null
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame((int) RecordApiJsonResponseEnum::VALIDATION_ERROR->value, $result->status());
    }

    /** @test */
    public function it_builds_pgsql_composite_payload_from_array(): void
    {
        DB::shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('connection')->andReturnSelf();
        DB::shouldReceive('getPdo')->andReturn(new PDO('sqlite::memory:'));
        DB::shouldReceive('raw')->andReturnUsing(fn(string $sql): Expression => new Expression($sql));

        $payload = [
            'name' => 'Example',
            'location' => [
                'lat' => 11.5,
                'lng' => 104.9,
            ],
        ];

        $columns = [
            'location' => [
                'type' => 'USER-DEFINED',
                'udt_name' => 'geo_point',
                'udt_schema' => 'public',
                'compositeFields' => ['lat', 'lng'],
            ],
        ];

        $result = RecordUtils::applyCompositeTypes($payload, $columns);

        $this->assertInstanceOf(Expression::class, $result['location']);
        $this->assertSame('Example', $result['name']);
    }

    /** @test */
    public function it_builds_pgsql_composite_payload_from_json_string(): void
    {
        DB::shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('connection')->andReturnSelf();
        DB::shouldReceive('getPdo')->andReturn(new PDO('sqlite::memory:'));
        DB::shouldReceive('raw')->andReturnUsing(fn(string $sql): Expression => new Expression($sql));

        $payload = [
            'location' => json_encode(['lat' => 11.5, 'lng' => 104.9]),
        ];

        $columns = [
            'location' => [
                'type' => 'USER-DEFINED',
                'udt_name' => 'geo_point',
                'udt_schema' => 'public',
                'compositeFields' => ['lat', 'lng'],
            ],
        ];

        $result = RecordUtils::applyCompositeTypes($payload, $columns);

        $this->assertInstanceOf(Expression::class, $result['location']);
    }

    /** @test */
    public function it_builds_pgsql_composite_payload_from_object(): void
    {
        DB::shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('connection')->andReturnSelf();
        DB::shouldReceive('getPdo')->andReturn(new PDO('sqlite::memory:'));
        DB::shouldReceive('raw')->andReturnUsing(fn(string $sql): Expression => new Expression($sql));

        $payload = [
            'location' => (object) ['lat' => 11.5, 'lng' => 104.9],
        ];

        $columns = [
            'location' => [
                'type' => 'USER-DEFINED',
                'udt_name' => 'geo_point',
                'udt_schema' => 'public',
                'compositeFields' => ['lat', 'lng'],
            ],
        ];

        $result = RecordUtils::applyCompositeTypes($payload, $columns);

        $this->assertInstanceOf(Expression::class, $result['location']);
    }

    /** @test */
    public function it_converts_pgsql_composite_response_to_object(): void
    {
        SchemaRegistryUtils::clearAllCache();

        $schema = new RecordTableType(
            table: 'companies',
            hasTenantId: false,
            columns: [
                'bill_addr' => [
                    'type' => 'USER-DEFINED',
                    'udt_name' => 'billing_address',
                    'udt_schema' => 'public',
                    'compositeFields' => ['line1', 'line2'],
                ],
                'name' => ['type' => 'string'],
            ]
        );

        SchemaRegistryUtils::register('companies', $schema);

        DB::shouldReceive('getDriverName')->andReturn('pgsql');

        $record = (object) [
            'id' => 1,
            'name' => 'ACME',
            'bill_addr' => '(,"Main Street")',
        ];

        $converted = RecordApiResponseService::convertCompositeFields($record, 'companies');

        $this->assertSame(['line1' => null, 'line2' => 'Main Street'], $converted->bill_addr);
    }

    /** @test */
    public function it_exports_pgsql_defaults_without_escaped_single_quotes(): void
    {
        $command = new SyncRecordColumnsCommand();
        $method = new ReflectionMethod($command, 'exportValue');

        $result = $method->invoke($command, "nextval('purchase_orders_id_seq'::regclass)", '    ');

        $this->assertSame('"nextval(\'purchase_orders_id_seq\'::regclass)"', $result);
    }

    /** @test */
    public function it_does_not_add_extra_blank_lines_when_syncing_columns_multiple_times(): void
    {
        $command = new SyncRecordColumnsCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $method = new ReflectionMethod($command, 'updateConfigFile');

        $content = "<?php\n\nreturn new RecordTableType(\n    pmsName: 'purchaseOrder',\n    hasTenantId: true,\n    softDeletes: true,\n    public: new RecordTablePublic(read: true, write: true),\n    primaryKey: 'id',\n);\n";

        $path = tempnam(sys_get_temp_dir(), 'record_table_');
        file_put_contents($path, $content);

        $columns = [
            'id' => [
                'type' => 'bigint',
                'udt_name' => 'int8',
                'udt_schema' => 'pg_catalog',
                'nullable' => false,
                'key' => '',
                'default' => "nextval('purchase_orders_id_seq'::regclass)",
                'extra' => '',
            ],
        ];

        $method->invoke($command, $path, 'purchase_orders', $columns, false);
        $method->invoke($command, $path, 'purchase_orders', $columns, false);

        $updated = file_get_contents($path);
        if ($updated !== false) {
            $this->assertSame(1, preg_match_all('/\n\s*columns:/', $updated));
            $this->assertSame(0, preg_match("/\n\n\s*columns:/", $updated));
            $this->assertSame(1, preg_match('/\n\s*\],?\n\s*\);/', $updated));
        }

        unlink($path);
    }

    /** @test */
    public function it_handles_fulltext_indexes_from_record_table_type(): void
    {
        SchemaRegistryUtils::clearAllCache();

        $schema = new RecordTableType(
            table: 'articles',
            columnIndexes: [
                ['title', 'body'],
            ],
        );

        SchemaRegistryUtils::register('articles', $schema);
        QueryBuilderFiltersUtils::clearColumnCache();

        $method = new ReflectionMethod(QueryBuilderFiltersUtils::class, 'hasFullTextIndex');

        $this->assertTrue($method->invoke(null, 'articles', ['title', 'body']));
    }

    /** @test */
    public function it_supports_pgsql_text_types_for_searchable_columns(): void
    {
        SchemaRegistryUtils::clearAllCache();

        $schema = new RecordTableType(
            table: 'products',
            columns: [
                'id' => ['type' => 'bigint'],
                'name' => ['type' => 'character varying'],
                'meta_data' => ['type' => 'jsonb'],
            ],
        );

        SchemaRegistryUtils::register('products', $schema);
        QueryBuilderFiltersUtils::clearColumnCache();

        $method = new ReflectionMethod(QueryBuilderFiltersUtils::class, 'getSearchableColumns');

        $result = $method->invoke(null, 'products', ['id', 'name', 'meta_data']);

        $this->assertSame(['name', 'meta_data'], $result);
    }

    /** @test */
    public function it_supports_pgsql_numeric_types_for_searchable_columns(): void
    {
        SchemaRegistryUtils::clearAllCache();

        $schema = new RecordTableType(
            table: 'inventory',
            columns: [
                'id' => ['type' => 'bigint'],
                'qty_on_hand' => ['type' => 'numeric'],
                'name' => ['type' => 'character varying'],
            ],
        );

        SchemaRegistryUtils::register('inventory', $schema);
        QueryBuilderFiltersUtils::clearColumnCache();

        $method = new ReflectionMethod(QueryBuilderFiltersUtils::class, 'getNumericSearchableColumns');

        $result = $method->invoke(null, 'inventory', ['id', 'qty_on_hand', 'name']);

        $this->assertSame(['id', 'qty_on_hand'], $result);
    }

    /** @test */
    public function it_keeps_belongs_to_relationships_with_fk_in_audit_payload(): void
    {
        $service = new RecordService();
        $schema = new RecordTableType(
            relationships: [
                'roles' => [],
                'profile' => new RecordBelongsToType(table: 'profiles', foreignKey: 'profile_id'),
            ]
        );

        $method = new ReflectionMethod(RecordService::class, 'stripRelationshipAuditData');

        $result = $method->invoke($service, [
            'name' => 'Example',
            'roles' => [['id' => 1]],
            'profile' => ['id' => 2],
            'profile_id' => 2,
            'relationship' => ['ref_number' => 'REF-001'],
            'relationships' => ['other'],
        ], $schema, true);

        $this->assertSame([
            'name' => 'Example',
            'profile' => ['id' => 2],
            'profile_id' => 2,
        ], $result);
    }

    /** @test */
    public function it_maps_permission_for_string_pmsName(): void
    {
        Config::set('record.permission_separator', ':');
        Config::set('record.tables', [
            'departments' => (object) ['pmsName' => 'department'],
        ]);

        $perms = PermissionUtils::mapPermissions('departments', 'read');

        $this->assertSame(['view:department'], $perms);
        $this->assertSame('view:department', PermissionUtils::mapPermission('departments', 'read'));
    }

    /** @test */
    public function it_maps_permissions_for_array_pmsName(): void
    {
        Config::set('record.permission_separator', ':');
        Config::set('record.tables', [
            'departments' => (object) ['pmsName' => ['department', 'dept']],
        ]);

        $perms = PermissionUtils::mapPermissions('departments', 'read');

        $this->assertSame(['view:department', 'view:dept'], $perms);
        $this->assertSame('view:department', PermissionUtils::mapPermission('departments', 'read'));
    }

    /** @test */
    public function it_allows_public_action_when_public_is_true(): void
    {
        Config::set('record.tables', [
            'departments' => new RecordTableType(
                table: 'departments',
                pmsName: 'department',
                public: true,
            ),
        ]);

        $this->assertTrue(PermissionUtils::isPublicAction('departments', 'read'));
        $this->assertTrue(PermissionUtils::isPublicAction('departments', 'create'));
    }

    /** @test */
    public function it_includes_error_debug_meta_when_x_debug_header_is_enabled(): void
    {
        Config::set('record.debug', false);

        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_X_DEBUG' => 'true',
        ]);
        $request->attributes->set('request_id', 'req-debug-header');

        $this->app->instance('request', $request);

        $response = RecordApiResponseService::errorFromException(new Exception('Header debug error'));
        $payload = $response->getData(true);

        $this->assertSame(false, $payload['success']);
        $this->assertSame('req-debug-header', $payload['meta']['request_id']);
        $this->assertSame('Header debug error', $payload['meta']['debug']['exception_message'] ?? null);
    }

    /** @test */
    public function it_hides_error_debug_meta_when_debug_is_disabled_and_no_header(): void
    {
        Config::set('record.debug', false);

        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('request_id', 'req-no-debug');

        $this->app->instance('request', $request);

        $response = RecordApiResponseService::errorFromException(new Exception('No debug'));
        $payload = $response->getData(true);

        $this->assertSame(false, $payload['success']);
        $this->assertArrayNotHasKey('debug', $payload['meta']);
    }
}

class TestTriggerHandler
{
    public static function handle(Request $request, string $table, array $context): array
    {
        return ['foo' => 'bar'];
    }
}

class TestValidationHandler
{
    public static function handle(Request $request, ?string $id = null): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'name' => 'required',
        ]);
    }
}

class TestFailValidationHandler
{
    public static function handle(Request $request, ?string $id = null): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'blocked' => 'required',
        ]);
    }
}

class TestTriggerResponseHandler
{
    public static function handle(Request $request, string $table, array $context): JsonResponse
    {
        return RecordApiResponseService::validationError(['message' => 'Not allowed']);
    }
}
