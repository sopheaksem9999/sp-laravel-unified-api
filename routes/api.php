<?php

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Http\Controllers\Api\CoreRecordController;
use Sopheak\Core\Http\Controllers\AuditLogController;

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
Route::prefix(config('record.api_prefix', 'api'))->middleware(['api', 'request.id'])->group(function (): void {

    Route::get('docs/openapi', function () {
        $filePath = storage_path('openapi-schema.json');

        if (!file_exists($filePath)) {
            return response()->json([
                'error' => 'OpenAPI specification not found',
                'message' => 'Please run "php artisan sp-laravel-api:openapi" to generate the specification'
            ], 404);
        }

        $content = file_get_contents($filePath);
        $json = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'error' => 'Invalid OpenAPI specification',
                'message' => 'The OpenAPI file contains invalid JSON'
            ], 500);
        }

        return response()->json($json)->header('Content-Type', 'application/json');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Table-specific RPC Functions
    |--------------------------------------------------------------------------
    */
    Route::match(['get', 'post', 'put', 'patch', 'delete'], '{table}/rpc/{functionName}', [CoreRecordController::class, 'executeTableFunction'])
        ->where(['table' => '[a-zA-Z_][a-zA-Z0-9_]*', 'functionName' => '[a-zA-Z_][a-zA-Z0-9_]*'])
        ->middleware('throttle:api-functions');

    /*
    |--------------------------------------------------------------------------
    | Standard CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::get('{table}', [CoreRecordController::class, 'index'])->middleware('throttle:api-reads');
    Route::get('{table}/{id}', [CoreRecordController::class, 'show'])->middleware('throttle:api-reads');
    Route::post('{table}', [CoreRecordController::class, 'store'])->middleware('throttle:api-writes');
    Route::match(['put', 'patch'], '{table}/{id}', [CoreRecordController::class, 'update'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}', [CoreRecordController::class, 'destroy'])->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Advanced CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/{id}/restore', [CoreRecordController::class, 'restore'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}/force', [CoreRecordController::class, 'forceDelete'])->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Bulk Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/bulk', [CoreRecordController::class, 'bulk'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/create', [CoreRecordController::class, 'bulkCreate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/update', [CoreRecordController::class, 'bulkUpdate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/delete', [CoreRecordController::class, 'bulkDelete'])->middleware('throttle:api-writes');
});

/*
|--------------------------------------------------------------------------
| Authenticated API Routes (Always require authentication)
|--------------------------------------------------------------------------
*/
Route::prefix(config('record.api_prefix', 'api'))->middleware([
    'api',
    'auth:'.config('sp-laravel-api.auth.guard', 'api'),
    'request.id',
])->group(function (): void {
    
    /*
    |--------------------------------------------------------------------------
    | Audit Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('audit')->group(function (): void {
        // Get audit logs for a specific entity
        Route::get('logs', [AuditLogController::class, 'getLogs'])->middleware('throttle:api-reads');
        
        // Get audit statistics
        Route::get('stats', [AuditLogController::class, 'getStats'])->middleware('throttle:api-reads');
        
        // Get field timeline for a specific field
        Route::get('field-timeline', [AuditLogController::class, 'getFieldTimeline'])->middleware('throttle:api-reads');
        
        // Get field statistics for a specific field
        Route::get('field-stats', [AuditLogController::class, 'getFieldStats'])->middleware('throttle:api-reads');
        
        // Manually create an audit log entry
        Route::post('logs', [AuditLogController::class, 'createLog'])->middleware('throttle:api-writes');
        
        // Get specific audit log by ID
        Route::get('logs/{id}', [AuditLogController::class, 'show'])->middleware('throttle:api-reads');
        
        // Clean up old audit logs (admin only)
        Route::delete('cleanup', [AuditLogController::class, 'cleanup'])
            ->middleware(['throttle:api-writes', 'can:manage-audit-logs']);
    });

    /*
    |--------------------------------------------------------------------------
    | Global RPC Functions (not table-specific)
    |--------------------------------------------------------------------------
    */
    Route::match(['get', 'post', 'put', 'patch', 'delete'], '{functionName}', [CoreRecordController::class, 'executeGlobalFunction'])
        ->where('functionName', '[a-zA-Z_][a-zA-Z0-9_]*')
        ->middleware('throttle:api-functions');
});
