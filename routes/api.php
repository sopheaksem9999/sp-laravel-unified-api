<?php

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Http\Controllers\CoreRecordController;
use Sopheak\Core\Services\RecordConfigService;

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

    // OpenAPI Specification
    Route::get('docs/openapi', function () {
        $filePath = storage_path('openapi-schema.json');

        if (!file_exists($filePath)) {
            return response()->json([
                'error' => 'OpenAPI specification not found',
                'message' => 'Please run "php artisan sp-laravel-api:openapi" to generate the specification'
            ], RecordApiJsonResponseEnum::NOT_FOUND->value);
        }

        $content = file_get_contents($filePath);
        $json = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'error' => 'Invalid OpenAPI specification',
                'message' => 'The OpenAPI file contains invalid JSON'
            ], RecordApiJsonResponseEnum::SERVER_ERROR->value);
        }

        return response()->json($json)->header('Content-Type', 'application/json');
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
            ->where(['table' => $tableWhere, 'functionName' => '.*'])
            ->middleware('throttle:api-functions');
    }
});
