<?php

namespace Sopheak\Core\Tests\Unit;

use Exception;
use ReflectionMethod;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
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
use Sopheak\Core\Utilities\RecordPayloadExtractor;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordTablePublic;
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
    public function it_requires_bearer_token_for_docs_endpoints_when_api_docs_is_private(): void
    {
        Config::set('record.api_docs.is_private', true);

        $this->get('/api/docs/openapi.json')->assertStatus(401);
        $this->get('/api/docs/llms.mdx')->assertStatus(401);

        $this->get('/api/docs/openapi.json', ['Authorization' => 'Bearer test-token'])->assertStatus(200);
        $this->get('/api/docs/llms.mdx', ['Authorization' => 'Bearer test-token'])->assertStatus(200);
    }

    /** @test */
    public function it_preserves_http_response_exception_payload_for_unauthenticated_requests(): void
    {
        Config::set('record.tables', [
            'secure_items' => new RecordTableType(
                table: 'secure_items',
                isAuthRead: true,
                public: new RecordTablePublic(read: false, write: false),
                columns: [
                    'id' => ['type' => 'integer'],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $response = $this->get('/api/secure_items');

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Unauthenticated');
        $response->assertJsonPath('error_code', 10000);
    }

    /** @test */
    public function it_handles_request_input_in_record_payload_extractor_without_array_key_exists_type_error(): void
    {
        Config::set('record.tables', [
            'companies' => new RecordTableType(
                table: 'companies',
                columns: [
                    'name' => ['type' => 'string'],
                    'updated_at' => ['type' => 'datetime'],
                    'updated_by' => ['type' => 'integer'],
                ],
                isAuthRead: false,
                isAuthWrite: false,
                public: new RecordTablePublic(read: true, write: true),
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $request = Request::create('/api/companies/1', 'PUT', [
            'name' => 'ACME Co',
        ]);

        $data = RecordPayloadExtractor::fromRequest(
            request: $request,
            isUpdate: true,
            recordTable: 'companies'
        );

        $this->assertSame('ACME Co', $data['name']);
        $this->assertArrayHasKey('updated_at', $data);
    }

    /** @test */
    public function it_auto_fills_created_by_id_updated_by_last_updated_by_and_last_updated_by_id_when_columns_exist(): void
    {
        auth('api')->setUser(new GenericUser(['id' => 77]));

        $schema = new RecordTableType(
            table: 'companies',
            columns: [
                'created_by_id' => ['type' => 'integer'],
                'updated_by' => ['type' => 'integer'],
                'last_updated_by' => ['type' => 'integer'],
                'last_updated_by_id' => ['type' => 'integer'],
                'updated_at' => ['type' => 'datetime'],
            ],
            overrideUserstamps: false,
            overrideTimestamps: false
        );

        $service = new RecordService();

        $createPayload = $service->applyTimestampsAndAuditFields([], $schema, false);
        $this->assertSame(77, $createPayload['created_by_id']);
        $this->assertSame(77, $createPayload['updated_by']);
        $this->assertSame(77, $createPayload['last_updated_by']);
        $this->assertSame(77, $createPayload['last_updated_by_id']);

        $updatePayload = $service->applyTimestampsAndAuditFields([], $schema, true);
        $this->assertSame(77, $updatePayload['created_by_id']);
        $this->assertSame(77, $updatePayload['updated_by']);
        $this->assertSame(77, $updatePayload['last_updated_by']);
        $this->assertSame(77, $updatePayload['last_updated_by_id']);
    }

    /** @test */
    public function it_shows_private_docs_login_form_when_api_docs_is_private(): void
    {
        Config::set('record.api_docs.is_private', true);
        Config::set('record.api_docs.access_token_key', 'access_token');
        Config::set('record.api_docs.login_api', '/v1/auth/login');

        $testResponse = $this->get('/api-docs');

        $testResponse
            ->assertStatus(200)
            ->assertSee('API Docs Login')
            ->assertSee('accessTokenKey')
            ->assertSee('loginApi')
            ->assertSee('extractAndPersistTokenFromPayload')
            ->assertSee('shouldAttachAuthForUrl')
            ->assertSee('syncScalarAuthTokenUi');
    }

    /** @test */
    public function it_keeps_api_docs_public_when_api_docs_config_is_missing(): void
    {
        config()->offsetUnset('record.api_docs');

        $testResponse = $this->get('/api-docs');

        $testResponse
            ->assertStatus(200)
            ->assertDontSee('API Docs Login')
            ->assertSee('Scalar.createApiReference');
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
    public function it_auto_casts_common_numeric_and_boolean_column_types_from_string_values(): void
    {
        $row = [
            'is_active' => '0',
            'quantity' => '12',
            'price' => '19.75',
            'rating' => '4.5',
        ];

        $columns = [
            'is_active' => ['type' => 'boolean'],
            'quantity' => ['type' => 'bigint'],
            'price' => ['type' => 'decimal(12,2)'],
            'rating' => ['type' => 'double precision'],
        ];

        $casted = RecordApiResponseService::applyCasts($row, $columns);

        $this->assertIsBool($casted['is_active']);
        $this->assertFalse($casted['is_active']);
        $this->assertIsInt($casted['quantity']);
        $this->assertSame(12, $casted['quantity']);
        $this->assertIsFloat($casted['price']);
        $this->assertSame(19.75, $casted['price']);
        $this->assertIsFloat($casted['rating']);
        $this->assertSame(4.5, $casted['rating']);
    }

    /** @test */
    public function it_allows_explicit_casting_to_override_inferred_column_type_casts(): void
    {
        $row = [
            'quantity' => '12',
            'is_active' => 'true',
        ];

        $columns = [
            'quantity' => ['type' => 'integer'],
            'is_active' => ['type' => 'bool'],
        ];

        $casted = RecordApiResponseService::applyCasts($row, $columns, [
            'quantity' => fn($value): string => 'Q-' . $value,
            'is_active' => 'string',
        ]);

        $this->assertSame('Q-12', $casted['quantity']);
        $this->assertSame('true', $casted['is_active']);
    }

    /** @test */
    public function it_uses_global_casting_when_table_casting_is_not_defined(): void
    {
        Config::set('record.casting', [
            'quantity' => 'integer',
        ]);

        $row = [
            'quantity' => '21',
        ];

        $casted = RecordApiResponseService::applyCasts($row, []);

        $this->assertIsInt($casted['quantity']);
        $this->assertSame(21, $casted['quantity']);
    }

    /** @test */
    public function it_prioritizes_record_table_type_casting_over_global_casting(): void
    {
        Config::set('record.casting', [
            'quantity' => 'integer',
        ]);

        $row = [
            'quantity' => '21',
        ];

        $casted = RecordApiResponseService::applyCasts($row, [], [
            'quantity' => 'string',
        ]);

        $this->assertIsString($casted['quantity']);
        $this->assertSame('21', $casted['quantity']);
    }

    /** @test */
    public function it_throws_clear_error_for_invalid_global_casting_configuration(): void
    {
        Config::set('record.casting', [
            'quantity' => ['invalid'],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid cast definition for column 'quantity' at record.casting.quantity");

        RecordApiResponseService::applyCasts(['quantity' => '21'], []);
    }

    /** @test */
    public function it_treats_datetime_class_name_string_as_builtin_datetime_cast(): void
    {
        $row = [
            'created_at' => '2026-01-20 10:11:12',
        ];

        $casted = RecordApiResponseService::applyCasts($row, [], [
            'created_at' => 'DateTime',
        ]);

        $this->assertIsString($casted['created_at']);
        $this->assertStringStartsWith('2026-01-20T10:11:12', $casted['created_at']);
    }

    /** @test */
    public function it_throws_clear_error_when_cast_class_does_not_define_get_method(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid cast class 'DateTimeImmutable' for column 'created_at' at RecordTableType::casting.created_at: class must define method get()");

        RecordApiResponseService::applyCasts(['created_at' => '2026-01-20 10:11:12'], [], [
            'created_at' => 'DateTimeImmutable',
        ]);
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
    public function it_supports_auth_flags_without_breaking_public_option(): void
    {
        Config::set('record.tables', [
            'auth_required' => new RecordTableType(
                table: 'auth_required',
                pmsName: 'auth_required',
                isAuthRead: true,
                isAuthWrite: true,
            ),
            'public_access' => new RecordTableType(
                table: 'public_access',
                pmsName: 'public_access',
                isAuthRead: false,
                isAuthWrite: false,
            ),
        ]);

        $this->assertFalse(PermissionUtils::isPublicAction('auth_required', 'read'));
        $this->assertFalse(PermissionUtils::isPublicAction('auth_required', 'create'));
        $this->assertTrue(PermissionUtils::isPublicAction('public_access', 'read'));
        $this->assertTrue(PermissionUtils::isPublicAction('public_access', 'create'));
    }

    /** @test */
    public function it_keeps_legacy_public_behavior_when_auth_flags_are_default_true(): void
    {
        Config::set('record.tables', [
            'mixed_access' => new RecordTableType(
                table: 'mixed_access',
                pmsName: 'mixed_access',
                public: new RecordTablePublic(read: true, write: false),
            ),
        ]);

        $this->assertTrue(PermissionUtils::isPublicAction('mixed_access', 'read'));
        $this->assertFalse(PermissionUtils::isPublicAction('mixed_access', 'create'));
    }

    /** @test */
    public function it_includes_error_debug_meta_when_x_debug_header_is_enabled(): void
    {
        Config::set('record.debug', false);
        Log::spy();

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
        Log::shouldHaveReceived('error')->once();
    }

    /** @test */
    public function it_hides_error_debug_meta_when_debug_is_disabled_and_no_header(): void
    {
        Config::set('record.debug', false);
        Log::spy();

        $request = Request::create('/api/test', 'GET');
        $request->attributes->set('request_id', 'req-no-debug');

        $this->app->instance('request', $request);

        $response = RecordApiResponseService::errorFromException(new Exception('No debug'));
        $payload = $response->getData(true);

        $this->assertSame(false, $payload['success']);
        $this->assertArrayNotHasKey('debug', $payload['meta']);
        Log::shouldNotHaveReceived('error');
    }

    /** @test */
    public function it_logs_error_wrapped_when_debug_is_enabled(): void
    {
        Config::set('record.debug', true);
        Log::spy();

        $request = Request::create('/api/test', 'POST');
        $request->attributes->set('request_id', 'req-debug-log');

        $this->app->instance('request', $request);

        $response = RecordApiResponseService::errorWrapped('Wrapped error', 500, ['field' => ['invalid']]);
        $payload = $response->getData(true);

        $this->assertSame(false, $payload['success']);
        $this->assertSame('req-debug-log', $payload['meta']['request_id']);
        Log::shouldHaveReceived('error')->once();
    }

    /** @test */
    public function it_resolves_tenant_from_request_attribute_before_header(): void
    {
        Config::set('record.enable_tenant_id', true);

        $service = new RecordService();
        $schema = new RecordTableType(table: 'invoices', hasTenantId: true);
        $request = Request::create('/api/invoices', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'header-tenant',
        ]);
        $request->attributes->set('resolved_tenant_id', 'attribute-tenant');

        $tenantId = $service->resolveTenantFromRequest($request, $schema);

        $this->assertSame('attribute-tenant', $tenantId);
    }

    /** @test */
    public function it_resolves_tenant_from_request_context_before_header(): void
    {
        Config::set('record.enable_tenant_id', true);

        $service = new RecordService();
        $schema = new RecordTableType(table: 'invoices', hasTenantId: true);
        $request = Request::create('/api/invoices', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'header-tenant',
        ]);
        $request->attributes->set('record_context', [
            'tenant_id' => 'context-tenant',
        ]);

        $tenantId = $service->resolveTenantFromRequest($request, $schema);

        $this->assertSame('context-tenant', $tenantId);
    }

    /** @test */
    public function it_does_not_resolve_tenant_from_input_by_default(): void
    {
        Config::set('record.enable_tenant_id', true);

        $service = new RecordService();
        $schema = new RecordTableType(table: 'invoices', hasTenantId: true);
        $request = Request::create('/api/invoices', 'POST', ['tenant_id' => 'input-tenant']);

        $tenantId = $service->resolveTenantFromRequest($request, $schema);

        $this->assertNull($tenantId);
    }

    /** @test */
    public function it_injects_request_context_into_trigger_context(): void
    {
        $service = new RecordService();
        $request = Request::create('/api/invoices', 'POST');
        $request->attributes->set('request_id', 'req-trigger-context');
        $request->attributes->set('resolved_tenant_id', 'ctx-tenant');

        $params = $service->executeTableTrigger(
            [
                [
                    'class' => TestTriggerContextHandler::class,
                    'functionName' => 'handle',
                ],
            ],
            [$request, 'invoices', ['type' => 'create']]
        );

        $this->assertInstanceOf(Request::class, $params[0]);
        $this->assertSame('1', $params[0]->get('ctx_present'));
        $this->assertSame('ctx-tenant', $params[0]->get('ctx_tenant'));
        $this->assertSame('invoices', $params[0]->get('ctx_table'));
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

class TestTriggerContextHandler
{
    public static function handle(Request $request, string $table, array $context): array
    {
        $requestContext = $context['request_context'] ?? [];

        return [
            'ctx_present' => isset($context['request_context']) ? '1' : '0',
            'ctx_tenant' => $requestContext['tenant_id'] ?? null,
            'ctx_table' => $requestContext['table'] ?? null,
        ];
    }
}
