<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\JsonResponse;
use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Contracts\Attachment\AttachmentMultipartDriver;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Services\AttachmentPresignService;
use Sopheak\Core\Tests\TestCase;

class AttachmentDirectUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('attachments.direct_upload.enabled', true);
        Config::set('attachments.disk_private', 'local');
        Config::set('attachments.disk_public', 'public');
        $attachmentConfig = require __DIR__ . '/../../config/sp-attachments.php';
        Config::set('attachments.tables', $attachmentConfig['tables'] ?? []);
        Config::set('record.enable_tenant_id', false);

        DB::statement('CREATE TABLE IF NOT EXISTS sp_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id VARCHAR(255) NULL,
            title VARCHAR(255) NULL,
            caption TEXT NULL,
            disk VARCHAR(255) NOT NULL,
            path VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(255) NOT NULL,
            size INTEGER NOT NULL,
            visibility VARCHAR(255) NOT NULL,
            temp_timeout DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
    }

    private function controller(): AttachmentUploadController
    {
        return app(AttachmentUploadController::class);
    }

    private function issueUpload(string $visibility = 'private'): array
    {
        $response = $this->controller()->createUploadUrl(
            Request::create('/', 'POST', ['filename' => 'poster.jpg', 'visibility' => $visibility])
        );

        return json_decode((string) $response->getContent(), true)['data'];
    }

    private function complete(string $key, string $token, int $expires, ?UploadedFile $file = null): JsonResponse
    {
        $parameters = [
            'key' => $key,
            'upload_token' => $token,
            'expires_at' => $expires,
            'visibility' => 'private',
        ];

        $request = Request::create('/', 'POST', $parameters);
        if ($file instanceof UploadedFile) {
            $request->files->set('file', $file);
        }

        return $this->controller()->completeUpload($request);
    }

    public function test_complete_upload_accepts_the_token_issued_with_the_key(): void
    {
        Storage::fake('local');

        $issued = $this->issueUpload();

        $response = $this->complete(
            $issued['key'],
            $issued['upload_token'],
            $issued['expires_at'],
            UploadedFile::fake()->image('poster.jpg')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_complete_upload_rejects_a_key_without_a_valid_token(): void
    {
        Storage::fake('local');

        $response = $this->complete(
            'attachments/private/2026/08/17/00000000-0000-4000-8000-000000000000.jpg',
            'invalid-token',
            4102444800,
            UploadedFile::fake()->image('poster.jpg')
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_complete_upload_rejects_a_token_issued_for_another_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $issued = $this->issueUpload('private'); // disk local

        $response = $this->controller()->completeUpload(
            Request::create('/', 'POST', [
                'key' => $issued['key'],
                'upload_token' => $issued['upload_token'],
                'expires_at' => $issued['expires_at'],
                'visibility' => 'public', // resolves to the 'public' disk — mismatch
            ])
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_complete_upload_rejects_an_expired_token(): void
    {
        Storage::fake('local');

        $issued = $this->issueUpload();
        $expired = now()->subMinute()->getTimestamp();
        $stale = app(AttachmentPresignService::class)->issueUploadToken(
            $issued['key'],
            'local',
            '',
            '',
            $expired
        );

        $response = $this->complete($issued['key'], $stale, $expired);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_complete_upload_rejects_keys_outside_the_storage_path_pattern(): void
    {
        Storage::fake('local');

        $key = 'evil/path.txt';
        $expires = now()->addMinutes(10)->getTimestamp();
        $token = app(AttachmentPresignService::class)->issueUploadToken($key, 'local', '', '', $expires);

        $response = $this->complete($key, $token, $expires);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_post_fallback_does_not_overwrite_an_existing_object(): void
    {
        Storage::fake('local');

        $issued = $this->issueUpload();
        Storage::disk('local')->put($issued['key'], 'already-here');

        $response = $this->complete(
            $issued['key'],
            $issued['upload_token'],
            $issued['expires_at'],
            UploadedFile::fake()->image('poster.jpg')
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('already-here', Storage::disk('local')->get($issued['key']));
    }

    public function test_preview_rejects_a_bad_signature_before_looking_up_the_attachment(): void
    {
        $response = $this->controller()->preview(
            Request::create('/preview', 'GET', [
                'tenant' => '',
                'expires' => now()->addMinutes(5)->getTimestamp(),
                'signature' => str_repeat('0', 64),
            ]),
            '999999999'
        );

        // A nonexistent id must return 410 (invalid signature), not 404 —
        // otherwise the endpoint leaks attachment existence to unauthenticated callers.
        $this->assertSame(410, $response->getStatusCode());
    }

    public function test_multipart_part_signing_requires_the_token_from_creation(): void
    {
        $this->app->bind(AttachmentMultipartDriver::class, FakeMultipartDriver::class);
        Storage::fake('local');

        $create = $this->controller()->createMultipartUpload(
            Request::create('/', 'POST', ['filename' => 'movie.mp4', 'visibility' => 'private'])
        );
        $created = json_decode((string) $create->getContent(), true)['data'];

        $forged = $this->controller()->signMultipartPart(
            Request::create('/', 'POST', [
                'key' => $created['key'],
                'upload_id' => $created['upload_id'],
                'part_number' => 1,
                'visibility' => 'private',
                'upload_token' => 'forged',
                'expires_at' => $created['expires_at'],
            ])
        );
        $this->assertSame(422, $forged->getStatusCode());

        $valid = $this->controller()->signMultipartPart(
            Request::create('/', 'POST', [
                'key' => $created['key'],
                'upload_id' => $created['upload_id'],
                'part_number' => 1,
                'visibility' => 'private',
                'upload_token' => $created['upload_token'],
                'expires_at' => $created['expires_at'],
            ])
        );
        $this->assertSame(200, $valid->getStatusCode());
    }

    public function test_multipart_completion_uses_object_metadata_and_never_persists_etag(): void
    {
        $this->app->bind(AttachmentMultipartDriver::class, FakeMultipartDriver::class);
        Storage::fake('local');

        $create = $this->controller()->createMultipartUpload(
            Request::create('/', 'POST', ['filename' => 'movie.mp4', 'visibility' => 'private'])
        );
        $created = json_decode((string) $create->getContent(), true)['data'];

        $response = $this->controller()->completeMultipartUpload(
            Request::create('/', 'POST', [
                'key' => $created['key'],
                'upload_id' => $created['upload_id'],
                'visibility' => 'private',
                'upload_token' => $created['upload_token'],
                'expires_at' => $created['expires_at'],
                'filename' => 'client-name.mp4',
                'parts' => [['part_number' => 1, 'etag' => '"fake-etag"']],
            ])
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('video/mp4', $data['mime_type']);
        $this->assertSame(104857600, $data['size']);
        $this->assertArrayNotHasKey('etag', $data);
        $this->assertDatabaseHas('sp_attachments', ['mime_type' => 'video/mp4', 'size' => 104857600]);
    }
}

class FakeMultipartDriver implements AttachmentMultipartDriver
{
    public function supports(FilesystemAdapter $disk): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function createMultipartUpload(FilesystemAdapter $disk, string $path, ?string $contentType): array
    {
        return ['upload_id' => 'fake-upload'];
    }

    /**
     * @return array<string, mixed[]|string>
     */
    public function presignPart(FilesystemAdapter $disk, string $path, string $uploadId, int $partNumber, DateTimeInterface $expiresAt): array
    {
        return ['url' => 'https://fake.example/part', 'headers' => []];
    }

    /**
     * @return array<string, string|int>
     */
    public function completeMultipart(FilesystemAdapter $disk, string $path, string $uploadId, array $parts): array
    {
        return [
            'content_type' => 'video/mp4',
            'content_length' => 104857600,
            'etag' => '"final-etag"',
        ];
    }

    public function abortMultipart(FilesystemAdapter $disk, string $path, string $uploadId): void {}
}
