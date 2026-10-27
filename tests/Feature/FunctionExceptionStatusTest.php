<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FunctionStatusStub
{
    public function validation(Request $request): never
    {
        throw ValidationException::withMessages(['file' => ['The file field is required.']]);
    }

    public function unauthenticated(Request $request): never
    {
        throw new AuthenticationException('Unauthorized.');
    }

    public function forbidden(Request $request): never
    {
        throw new AuthorizationException('Forbidden.');
    }

    public function http(Request $request): never
    {
        throw new NotFoundHttpException('Nope');
    }

    public function coded(Request $request): never
    {
        throw new InvalidArgumentException('Bad thing', 409);
    }

    public function plain(Request $request): never
    {
        throw new RuntimeException('Boom');
    }

    public function badCode(Request $request): never
    {
        throw new RuntimeException('Boom', 7);
    }

    public function rawError(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Attachment not found'], 404);
    }
}

/**
 * Exceptions thrown inside table/global functions map to their own status
 * instead of always answering 500.
 *
 * @internal
 */
class FunctionExceptionStatusTest extends TestCase
{
    /** @return array{0: int, 1: array<string, mixed>} */
    private function invokeFunction(string $method): array
    {
        $reflection = new ReflectionMethod(RecordService::class, 'executeClassFunction');
        $response = $reflection->invoke(new RecordService(), Request::create('/', 'POST'), ['class' => FunctionStatusStub::class, 'functionName' => $method]);

        return [$response->getStatusCode(), $response->getData(true)];
    }

    public function test_validation_failures_are_422_with_field_errors(): void
    {
        [$status, $body] = $this->invokeFunction('validation');

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('file', $body['errors']);
        $this->assertFalse($body['success']);
    }

    public function test_auth_exceptions_keep_their_status(): void
    {
        $this->assertSame(401, $this->invokeFunction('unauthenticated')[0]);
        $this->assertSame(403, $this->invokeFunction('forbidden')[0]);
        $this->assertSame(404, $this->invokeFunction('http')[0]);
    }

    public function test_a_client_error_exception_code_is_the_status(): void
    {
        [$status, $body] = $this->invokeFunction('coded');

        $this->assertSame(409, $status);
        $this->assertSame('Bad thing', $body['message']);
    }

    public function test_real_failures_stay_500(): void
    {
        $this->assertSame(500, $this->invokeFunction('plain')[0]);
        $this->assertSame(500, $this->invokeFunction('badCode')[0]);
    }

    public function test_a_raw_json_error_keeps_its_message_at_the_top_level(): void
    {
        [$status, $body] = $this->invokeFunction('rawError');

        $this->assertSame(404, $status);
        $this->assertSame('Attachment not found', $body['message']);
    }
}
