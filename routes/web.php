<?php

use Illuminate\Contracts\View\View;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

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
Route::get('/api-docs', fn(): View|Factory => view('sp-laravel-api::scalar'));

Route::post('/api-docs/auth/login', function (Request $request) {
    $configuredEndpoint = trim((string) config('record.api_docs.login_api', '/api/login'));
    if ($configuredEndpoint === '') {
        return response()->json(['message' => 'Login endpoint is required'], 422);
    }

    // Prevent SSRF: reject requests attempting to target external endpoints
    $clientEndpoint = trim((string) $request->input('endpoint', ''));
    if ($clientEndpoint !== '') {
        $cleanClient = parse_url($clientEndpoint, PHP_URL_PATH) ?? $clientEndpoint;
        $cleanConfig = parse_url($configuredEndpoint, PHP_URL_PATH) ?? $configuredEndpoint;
        if (str_starts_with($clientEndpoint, 'http://') || str_starts_with($clientEndpoint, 'https://')) {
            $clientHost = parse_url($clientEndpoint, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            if ($clientHost !== $appHost) {
                return response()->json(['message' => 'Custom login endpoint is not allowed'], 422);
            }
        } elseif (ltrim((string) $cleanClient, '/') !== ltrim((string) $cleanConfig, '/')) {
            return response()->json(['message' => 'Custom login endpoint is not allowed'], 422);
        }
    }

    $username = (string) $request->input('username', '');
    $password = (string) $request->input('password', '');
    $requiredEmail = trim((string) config('record.api_docs.email', ''));

    if ($requiredEmail !== '' && strcasecmp($username, $requiredEmail) !== 0) {
        return response()->json(['message' => 'Unauthorized'], RecordApiJsonResponseEnum::UNAUTHORIZED->value);
    }

    if ($requiredEmail !== '') {
        $username = $requiredEmail;
    }

    if (!str_starts_with($configuredEndpoint, 'http://') && !str_starts_with($configuredEndpoint, 'https://')) {
        $endpoint = url('/' . ltrim($configuredEndpoint, '/'));
    } else {
        $endpoint = $configuredEndpoint;
    }

    /** @var \Illuminate\Http\Client\Response $response */
    $response = Http::acceptJson()->post($endpoint, [
        'email' => $username,
        'username' => $username,
        'password' => $password,
    ]);

    $payload = $response->json();
    if (!is_array($payload)) {
        $payload = [];
    }

    $tokenKey = (string) config('record.api_docs.access_token_key', 'access_token');
    $token = null;
    if (isset($payload[$tokenKey]) && is_string($payload[$tokenKey]) && $payload[$tokenKey] !== '') {
        $token = $payload[$tokenKey];
    } else {
        $stack = [$payload];
        $guard = 0;
        while ($stack !== [] && $guard < 200) {
            $guard++;
            $current = array_shift($stack);
            if (!is_array($current)) {
                continue;
            }
            if (isset($current[$tokenKey]) && is_string($current[$tokenKey]) && $current[$tokenKey] !== '') {
                $token = $current[$tokenKey];
                break;
            }
            foreach ($current as $item) {
                if (is_array($item)) {
                    $stack[] = $item;
                }
            }
        }
    }

    if (!$response->successful() || !is_string($token) || $token === '') {
        return response()->json([
            'message' => ($payload['message'] ?? $payload['error'] ?? 'Login failed'),
        ], $response->status() >= 400 ? $response->status() : 401);
    }

    $request->session()->put('sp_api_docs_access_token', $token);

    return response()->json([
        'access_token' => $token,
        'token_type' => (string) ($payload['token_type'] ?? 'Bearer'),
    ], 200);
})->middleware('web')->withoutMiddleware(VerifyCsrfToken::class);

Route::post('/api-docs/auth/logout', function (Request $request) {
    $request->session()->forget('sp_api_docs_access_token');
    return response()->json(['ok' => true], 200);
})->middleware('web')->withoutMiddleware(VerifyCsrfToken::class);

Route::get('/api-docs/openapi.json', function (Request $request) {
    if ((bool) config('record.api_docs.is_private', false)) {
        $token = (string) $request->session()->get('sp_api_docs_access_token', '');
        if (trim($token) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'error_code' => 10002,
            ], RecordApiJsonResponseEnum::UNAUTHORIZED->value);
        }
    }

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
})->middleware('web');
