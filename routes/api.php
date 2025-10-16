<?php

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Http\Controllers\Api\RecordController;

/*
|--------------------------------------------------------------------------
| SP Laravel API Routes
|--------------------------------------------------------------------------
|
| Here are the API routes for the SP Laravel API package.
| These routes provide dynamic CRUD operations for database tables.
| 
| The route prefix is configurable via config('record.api_prefix').
| Default: 'api' (can be customized to 'api/v1', 'api/v2', etc.)
|
*/

Route::prefix(config('record.api_prefix', 'api'))->middleware(['api', 'auth:api', 'request.id'])->group(function () {
    // Global functions (not table-specific)
    Route::match(['get', 'post', 'put', 'patch', 'delete'], '{functionName}', [RecordController::class, 'executeGlobalFunction'])
        ->where('functionName', '[a-zA-Z_][a-zA-Z0-9_]*')
        ->middleware('throttle:api-functions');

    // Table-specific RPC functions
    Route::match(['get', 'post', 'put', 'patch', 'delete'], '{table}/rpc/{functionName}', [RecordController::class, 'executeTableFunction'])
        ->where(['table' => '[a-zA-Z_][a-zA-Z0-9_]*', 'functionName' => '[a-zA-Z_][a-zA-Z0-9_]*'])
        ->middleware('throttle:api-functions');

    // Standard CRUD operations
    Route::get('{table}', [RecordController::class, 'index'])->middleware('throttle:api-reads');
    Route::get('{table}/{id}', [RecordController::class, 'show'])->middleware('throttle:api-reads');
    Route::post('{table}', [RecordController::class, 'store'])->middleware('throttle:api-writes');
    Route::match(['put', 'patch'], '{table}/{id}', [RecordController::class, 'update'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}', [RecordController::class, 'destroy'])->middleware('throttle:api-writes');

    Route::post('{table}/{id}/restore', [RecordController::class, 'restore'])->middleware('throttle:api-writes');
    Route::delete('{table}/{id}/force', [RecordController::class, 'forceDelete'])->middleware('throttle:api-writes');

    Route::post('{table}/bulk', [RecordController::class, 'bulk'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/create', [RecordController::class, 'bulkCreate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/update', [RecordController::class, 'bulkUpdate'])->middleware('throttle:api-writes');
    Route::post('{table}/bulk/delete', [RecordController::class, 'bulkDelete'])->middleware('throttle:api-writes');
});