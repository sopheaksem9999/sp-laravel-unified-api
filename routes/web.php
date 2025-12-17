<?php

use Illuminate\Contracts\View\View;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Custom Scalar documentation route using our custom template
Route::get('/api-docs', fn(): View|Factory => view('sp-laravel-api::scalar'))->middleware(['web']);

// OpenAPI JSON specification endpoint
Route::get('/api/docs/openapi', function () {
    $filePath = storage_path('api-v2.json');
    
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
})->middleware(['web']);
