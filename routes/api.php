<?php

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Http\Controllers\CoreRecordController;
use Sopheak\Core\Http\Controllers\AuditLogController;
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
    Route::prefix(RecordConfigService::rpcPrefix())->group(function (): void {
        Route::match(['get', 'post', 'put', 'patch', 'delete'], '{functionName}', [CoreRecordController::class, 'executeGlobalFunction'])
            ->where('functionName', '.*')
            ->middleware('throttle:api-functions');
    });

    /*
    |--------------------------------------------------------------------------
    | Table-specific RPC Functions
    |--------------------------------------------------------------------------
    */
    Route::match(['get', 'post', 'put', 'patch', 'delete'], '{table}/'.RecordConfigService::rpcPrefix().'/{functionName}', [CoreRecordController::class, 'executeTableFunction'])
        ->where(['table' => '[a-zA-Z0-9_\-]*', 'functionName' => '.*'])
        ->middleware('throttle:api-functions');

    if (RecordConfigService::auditEnabled()) {
        /*
        |--------------------------------------------------------------------------
        | Audit Log Operations
        |--------------------------------------------------------------------------
        */
        Route::get('audit/logs', [AuditLogController::class, 'getLogs'])->middleware('throttle:api-reads');
        Route::get('audit/stats', [AuditLogController::class, 'getStats'])->middleware('throttle:api-reads');
        Route::get('audit/timeline', [AuditLogController::class, 'getFieldTimeline'])->middleware('throttle:api-reads');
    }

    /*
    |--------------------------------------------------------------------------
    | Standard CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::get('{table}', [CoreRecordController::class, 'listRecords'])->middleware('throttle:api-reads');
    Route::get('{table}/{id}', [CoreRecordController::class, 'getRecordById'])->middleware('throttle:api-reads');
    Route::post('{table}', [CoreRecordController::class, 'createRecord'])->middleware('throttle:api-writes');
    Route::match(['put', 'patch'], '{table}/{id}', [CoreRecordController::class, 'updateRecord'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}', [CoreRecordController::class, 'destroyRecord'])->middleware('throttle:api-writes');


    /*
    |--------------------------------------------------------------------------
    | Advanced CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/{id}/restore', [CoreRecordController::class, 'restoreRecord'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}/force', [CoreRecordController::class, 'forceDeleteRecord'])->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Bulk Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/bulk', [CoreRecordController::class, 'bulkRecord'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/create', [CoreRecordController::class, 'bulkRecordCreate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/update', [CoreRecordController::class, 'bulkRecordUpdate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/delete', [CoreRecordController::class, 'bulkRecordDelete'])->middleware('throttle:api-writes');
});