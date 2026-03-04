<?php

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Http\Controllers\CoreRecordController;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/*
|--------------------------------------------------------------------------
| SP Laravel API Routes
|--------------------------------------------------------------------------
|
| Here are the API routes for the SP Laravel API package.
| These routes provide dynamic CRUD operations for database tables
| and audit management functionality.
| 
| The route prefix is configurable via config('record.api_prefix').
| Default: 'api' (can be customized to 'api/v1', 'api/v2', etc.)
|
*/

/*
|--------------------------------------------------------------------------
| Public API Routes (Authorization handled by CoreRecordController)
|--------------------------------------------------------------------------
*/

Route::prefix(RecordConfigService::apiPrefix())->middleware(['api', 'request.id'])->group(function (): void {

    $tableWhere = '[a-zA-Z0-9_\-]+';
    $configuredTables = array_keys(RecordConfigService::getTableConfig());
    $configuredTables = array_values(array_filter($configuredTables, static fn($value): bool => is_string($value) && $value !== ''));
    if (!empty($configuredTables)) {
        $escaped = array_map(static fn(string $table): string => preg_quote($table, '/'), $configuredTables);
        $tableWhere = '(?:' . implode('|', $escaped) . ')';
    }

    $globalFunctionWhere = '(?!)';
    $configuredGlobalFunctions = array_keys(RecordConfigService::globalFunctions());
    $configuredGlobalFunctions = array_values(array_filter($configuredGlobalFunctions, static fn ($value): bool => is_string($value) && $value !== ''));
    if (!empty($configuredGlobalFunctions)) {
        $escaped = array_map(static function (string $functionName): string {
            $escapedFunction = preg_quote($functionName, '/');

            return (string) preg_replace('/\\\\\{[^\\\\\}]+\\\\\}/', '\\\\d+', $escapedFunction);
        }, $configuredGlobalFunctions);

        $globalFunctionWhere = '(?:' . implode('|', $escaped) . ')';
    }

    $openApiSchemaResponse = function () {
        try {
            SchemaRegistryUtils::refresh();
            $json = OpenApiService::generateInternal();
        } catch (\Throwable $throwable) {
            return response()->json([
                'error' => 'Failed to generate OpenAPI specification',
                'message' => $throwable->getMessage(),
            ], RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }

        return response()
            ->json($json)
            ->header('Content-Type', 'application/vnd.oai.openapi+json; charset=utf-8');
    };

    $llmsMdxResponse = fn() => response(OpenApiService::generateLlmMdx(), 200, [
        'Content-Type' => 'text/markdown; charset=utf-8',
    ]);

    Route::prefix('docs')->group(function () use ($openApiSchemaResponse, $llmsMdxResponse): void {
        Route::get('openapi', $openApiSchemaResponse);
        Route::get('openapi.json', $openApiSchemaResponse);
        Route::get('llms.mdx', $llmsMdxResponse);
        Route::get('llms.txt', $llmsMdxResponse);
    });

    /*
    |--------------------------------------------------------------------------
    | Global RPC Functions (not table-specific)
    |--------------------------------------------------------------------------
    */
    if (!empty($configuredGlobalFunctions)) {
        if (!empty(RecordConfigService::rpcPrefix())) {
            Route::prefix(RecordConfigService::rpcPrefix())->group(function () use ($globalFunctionWhere): void {
                Route::match(['get', 'post', 'put', 'patch', 'delete'], '{functionName}', [CoreRecordController::class, 'executeGlobalFunction'])
                    ->where('functionName', $globalFunctionWhere)
                    ->middleware('throttle:api-functions');
            });
        } else {
            Route::match(['get', 'post', 'put', 'patch', 'delete'], '{functionName}', [CoreRecordController::class, 'executeGlobalFunction'])
                ->where('functionName', $globalFunctionWhere)
                ->middleware('throttle:api-functions');
        }
    }

 
    /*
    |--------------------------------------------------------------------------
    | Standard CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::get('{table}', [CoreRecordController::class, 'listRecords'])->where('table', $tableWhere)->middleware('throttle:api-reads');
    Route::get('{table}/{id}', [CoreRecordController::class, 'getRecordById'])->where('table', $tableWhere)->middleware('throttle:api-reads');
    Route::post('{table}', [CoreRecordController::class, 'createRecord'])->where('table', $tableWhere)->middleware('throttle:api-writes');
    Route::match(['put', 'patch'], '{table}/{id}', [CoreRecordController::class, 'updateRecord'])->where('table', $tableWhere)->middleware('throttle:api-writes');
    Route::delete('{table}/{id}', [CoreRecordController::class, 'destroyRecord'])->where('table', $tableWhere)->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Upsert Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/upsert', [CoreRecordController::class, 'upsertRecord'])->where('table', $tableWhere)->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Advanced CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/{id}/restore', [CoreRecordController::class, 'restoreRecord'])->where('table', $tableWhere)->middleware('throttle:api-writes');
    Route::delete('{table}/{id}/force', [CoreRecordController::class, 'forceDeleteRecord'])->where('table', $tableWhere)->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Bulk Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/bulk', [CoreRecordController::class, 'bulkRecord'])->where('table', $tableWhere)->middleware('throttle:api-writes');
    Route::post('{table}/bulk/create', [CoreRecordController::class, 'bulkRecordCreate'])->where('table', $tableWhere)->middleware('throttle:api-writes');
    Route::post('{table}/bulk/update', [CoreRecordController::class, 'bulkRecordUpdate'])->where('table', $tableWhere)->middleware('throttle:api-writes');
    Route::post('{table}/bulk/delete', [CoreRecordController::class, 'bulkRecordDelete'])->where('table', $tableWhere)->middleware('throttle:api-writes');
    Route::post('{table}/bulk/upsert', [CoreRecordController::class, 'bulkRecordUpsert'])->where('table', $tableWhere)->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Table-specific RPC Functions
    |--------------------------------------------------------------------------
    */
    if (!empty(RecordConfigService::rpcPrefix())) {
        Route::match(['get', 'post', 'put', 'patch', 'delete'], '{table}/' . RecordConfigService::rpcPrefix() . '/{functionName}', [CoreRecordController::class, 'executeTableFunction'])
            ->where(['table' => $tableWhere, 'functionName' => '.*'])
            ->middleware('throttle:api-functions');
    } else {
        Route::match(['get', 'post', 'put', 'patch', 'delete'], '{table}/{functionName}', [CoreRecordController::class, 'executeTableFunction'])
            ->where(['table' => $tableWhere, 'functionName' => '(?!\d+$).+'])
            ->middleware('throttle:api-functions');
    }


});
