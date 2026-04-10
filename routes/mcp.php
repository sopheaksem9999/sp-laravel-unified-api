<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| Here are the Model Context Protocol (MCP) routes for the SP Laravel API package.
|
*/

$mcpPrefix = config('record.mcp.route_prefix', 'mcp');
$mcpMiddleware = config('record.mcp.middleware', []);

Route::prefix($mcpPrefix)->middleware($mcpMiddleware)->group(function () {
    Route::get('/sse', [\Sopheak\Core\Http\Controllers\McpHttpController::class, 'handleSse'])->name('mcp.sse');
    Route::post('/message', [\Sopheak\Core\Http\Controllers\McpHttpController::class, 'handlePost'])->name('mcp.message');
});
