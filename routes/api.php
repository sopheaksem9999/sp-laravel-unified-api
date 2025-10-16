<?php

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Http\Controllers\Api\RecordController;
use Sopheak\Core\Http\Controllers\AuditController;

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
| Public API Routes (Authorization handled by RecordController)
|--------------------------------------------------------------------------
*/
Route::prefix(config('record.api_prefix', 'api'))->middleware(['api', 'request.id'])->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Table-specific RPC Functions
    |--------------------------------------------------------------------------
    */
    Route::match(['get', 'post', 'put', 'patch', 'delete'], '{table}/rpc/{functionName}', [RecordController::class, 'executeTableFunction'])
        ->where(['table' => '[a-zA-Z_][a-zA-Z0-9_]*', 'functionName' => '[a-zA-Z_][a-zA-Z0-9_]*'])
        ->middleware('throttle:api-functions');

    /*
    |--------------------------------------------------------------------------
    | Standard CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::get('{table}', [RecordController::class, 'index'])->middleware('throttle:api-reads');
    Route::get('{table}/{id}', [RecordController::class, 'show'])->middleware('throttle:api-reads');
    Route::post('{table}', [RecordController::class, 'store'])->middleware('throttle:api-writes');
    Route::match(['put', 'patch'], '{table}/{id}', [RecordController::class, 'update'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}', [RecordController::class, 'destroy'])->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Advanced CRUD Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/{id}/restore', [RecordController::class, 'restore'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}/force', [RecordController::class, 'forceDelete'])->middleware('throttle:api-writes');

    /*
    |--------------------------------------------------------------------------
    | Bulk Operations
    |--------------------------------------------------------------------------
    */
    Route::post('{table}/bulk', [RecordController::class, 'bulk'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/create', [RecordController::class, 'bulkCreate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/update', [RecordController::class, 'bulkUpdate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/delete', [RecordController::class, 'bulkDelete'])->middleware('throttle:api-writes');
});

/*
|--------------------------------------------------------------------------
| Authenticated API Routes (Always require authentication)
|--------------------------------------------------------------------------
*/
Route::prefix(config('record.api_prefix', 'api'))->middleware(['api', 'auth:api', 'request.id'])->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Audit Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('audit')->group(function () {
        // Get audit logs for a specific entity
        Route::get('logs', [AuditController::class, 'getLogs'])->middleware('throttle:api-reads');
        
        // Get audit statistics
        Route::get('stats', [AuditController::class, 'getStats'])->middleware('throttle:api-reads');
        
        // Get field timeline for a specific field
        Route::get('field-timeline', [AuditController::class, 'getFieldTimeline'])->middleware('throttle:api-reads');
        
        // Get field statistics for a specific field
        Route::get('field-stats', [AuditController::class, 'getFieldStats'])->middleware('throttle:api-reads');
        
        // Manually create an audit log entry
        Route::post('logs', [AuditController::class, 'createLog'])->middleware('throttle:api-writes');
        
        // Get specific audit log by ID
        Route::get('logs/{id}', [AuditController::class, 'show'])->middleware('throttle:api-reads');
        
        // Clean up old audit logs (admin only)
        Route::delete('cleanup', [AuditController::class, 'cleanup'])
            ->middleware(['throttle:api-writes', 'can:manage-audit-logs']);
    });

    /*
    |--------------------------------------------------------------------------
    | Global RPC Functions (not table-specific)
    |--------------------------------------------------------------------------
    */
    Route::match(['get', 'post', 'put', 'patch', 'delete'], '{functionName}', [RecordController::class, 'executeGlobalFunction'])
        ->where('functionName', '[a-zA-Z_][a-zA-Z0-9_]*')
        ->middleware('throttle:api-functions');
});