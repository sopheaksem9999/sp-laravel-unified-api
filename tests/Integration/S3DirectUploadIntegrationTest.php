<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Integration;

use Throwable;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Tests\TestCase;

/**
 * End-to-end direct upload against a real S3-compatible store (local MinIO).
 *
 * Skipped unless S3_TEST_ENDPOINT is reachable. Configure via env:
 *   S3_TEST_ENDPOINT=http://localhost:9001
 *   S3_TEST_ACCESS_KEY=minioadmin
 *   S3_TEST_SECRET_KEY=minioadmin
 *   S3_TEST_REGION=us-east-1
 *   S3_TEST_PRIVATE_BUCKET=develop-private
 *   S3_TEST_PUBLIC_BUCKET=develop-publish
 */
class S3DirectUploadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ?S3Client $s3 = null;

    private function s3(): ?S3Client
    {
        if ($this->s3 instanceof S3Client) {
            return $this->s3;
        }

        $endpoint = getenv('S3_TEST_ENDPOINT') ?: 'http://localhost:9001';
        try {
            $client = new S3Client([
                'version' => 'latest',
                'region' => getenv('S3_TEST_REGION') ?: 'us-east-1',
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => getenv('S3_TEST_ACCESS_KEY') ?: 'minioadmin',
                    'secret' => getenv('S3_TEST_SECRET_KEY') ?: 'minioadmin',
                ],
            ]);
            $client->listBuckets();
        } catch (Throwable) {
            return null;
        }

        return $this->s3 = $client;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->s3() instanceof S3Client) {
            $this->markTestSkipped('S3 test endpoint unreachable');
        }

        Config::set('attachments.direct_upload.enabled', true);
        Config::set('attachments.disk_private', 's3-test-private');
        Config::set('attachments.disk_public', 's3-test-public');
        $attachmentConfig = require __DIR__ . '/../../config/sp-attachments.php';
        Config::set('attachments.tables', $attachmentConfig['tables'] ?? []);
        Config::set('record.enable_tenant_id', false);

        Config::set('filesystems.disks.s3-test-private', [
            'driver' => 's3',
            'key' => getenv('S3_TEST_ACCESS_KEY') ?: 'minioadmin',
            'secret' => getenv('S3_TEST_SECRET_KEY') ?: 'minioadmin',
            'region' => getenv('S3_TEST_REGION') ?: 'us-east-1',
            'bucket' => getenv('S3_TEST_PRIVATE_BUCKET') ?: 'develop-private',
            'endpoint' => getenv('S3_TEST_ENDPOINT') ?: 'http://localhost:9001',
            'use_path_style_endpoint' => true,
        ]);
        Config::set('filesystems.disks.s3-test-public', [
            'driver' => 's3',
            'key' => getenv('S3_TEST_ACCESS_KEY') ?: 'minioadmin',
            'secret' => getenv('S3_TEST_SECRET_KEY') ?: 'minioadmin',
            'region' => getenv('S3_TEST_REGION') ?: 'us-east-1',
            'bucket' => getenv('S3_TEST_PUBLIC_BUCKET') ?: 'develop-publish',
            'endpoint' => getenv('S3_TEST_ENDPOINT') ?: 'http://localhost:9001',
            'use_path_style_endpoint' => true,
        ]);

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

    private function bucket(string $disk): string
    {
        return (string) config('filesystems.disks.' . $disk . '.bucket');
    }

    public function test_presigned_put_and_complete_round_trips_through_real_s3(): void
    {
        $response = $this->controller()->createUploadUrl(
            Request::create('/', 'POST', ['filename' => 'poster.jpg', 'content_type' => 'image/jpeg', 'visibility' => 'private'])
        );
        $this->assertSame(200, $response->getStatusCode());
        $issued = json_decode((string) $response->getContent(), true)['data'];

        $this->assertSame('PUT', $issued['method']);
        $this->assertNotEmpty($issued['upload_url']);

        $put = Http::withHeaders($issued['headers'] ?? [])->withBody('fake-jpeg-bytes', 'image/jpeg')->send(
            'PUT',
            $issued['upload_url']
        );
        $this->assertTrue($put->successful(), 'Presigned PUT failed: ' . $put->body());

        $complete = $this->controller()->completeUpload(
            Request::create('/', 'POST', [
                'key' => $issued['key'],
                'upload_token' => $issued['upload_token'],
                'expires_at' => $issued['expires_at'],
                'visibility' => 'private',
            ])
        );
        $this->assertSame(200, $complete->getStatusCode());
        $row = json_decode((string) $complete->getContent(), true)['data'];
        $this->assertSame('image/jpeg', $row['mime_type']);
        $this->assertSame(15, $row['size']);

        $this->assertDatabaseHas('sp_attachments', ['path' => $issued['key'], 'disk' => 's3-test-private']);

        $head = $this->s3()->headObject(['Bucket' => $this->bucket('s3-test-private'), 'Key' => $issued['key']]);
        $this->assertSame('image/jpeg', (string) $head['ContentType']);
    }

    public function test_multipart_lifecycle_round_trips_through_real_s3(): void
    {
        $create = $this->controller()->createMultipartUpload(
            Request::create('/', 'POST', ['filename' => 'movie.mp4', 'content_type' => 'video/mp4', 'visibility' => 'private'])
        );
        $this->assertSame(200, $create->getStatusCode());
        $created = json_decode((string) $create->getContent(), true)['data'];
        $this->assertNotEmpty($created['upload_id']);

        $sign = $this->controller()->signMultipartPart(
            Request::create('/', 'POST', [
                'key' => $created['key'],
                'upload_id' => $created['upload_id'],
                'upload_token' => $created['upload_token'],
                'expires_at' => $created['expires_at'],
                'part_number' => 1,
                'visibility' => 'private',
            ])
        );
        $this->assertSame(200, $sign->getStatusCode());
        $signed = json_decode((string) $sign->getContent(), true)['data'];

        $put = Http::withHeaders($signed['headers'] ?? [])->withBody('part-one-bytes', 'application/octet-stream')->send(
            'PUT',
            $signed['url']
        );
        $this->assertTrue($put->successful(), 'Part PUT failed: ' . $put->body());
        $etag = $put->header('ETag');

        $complete = $this->controller()->completeMultipartUpload(
            Request::create('/', 'POST', [
                'key' => $created['key'],
                'upload_id' => $created['upload_id'],
                'upload_token' => $created['upload_token'],
                'expires_at' => $created['expires_at'],
                'visibility' => 'private',
                'filename' => 'movie.mp4',
                'parts' => [['part_number' => 1, 'etag' => (string) $etag]],
            ])
        );
        $this->assertSame(200, $complete->getStatusCode(), (string) $complete->getContent());
        $row = json_decode((string) $complete->getContent(), true)['data'];
        $this->assertSame('video/mp4', $row['mime_type']);
        $this->assertSame(14, $row['size']);
        $this->assertArrayNotHasKey('etag', $row);

        $this->assertDatabaseHas('sp_attachments', ['path' => $created['key'], 'disk' => 's3-test-private']);

        $head = $this->s3()->headObject(['Bucket' => $this->bucket('s3-test-private'), 'Key' => $created['key']]);
        $this->assertSame('video/mp4', (string) $head['ContentType']);
    }

    public function test_public_visibility_presigns_against_the_public_bucket(): void
    {
        $response = $this->controller()->createUploadUrl(
            Request::create('/', 'POST', ['filename' => 'banner.png', 'content_type' => 'image/png', 'visibility' => 'public'])
        );
        $issued = json_decode((string) $response->getContent(), true)['data'];

        $this->assertSame('s3-test-public', $issued['disk']);
        $this->assertStringStartsWith('attachments/public/', $issued['key']);

        $put = Http::withHeaders($issued['headers'] ?? [])->withBody('png-bytes', 'image/png')->send(
            'PUT',
            $issued['upload_url']
        );
        $this->assertTrue($put->successful(), 'Presigned PUT failed: ' . $put->body());

        $head = $this->s3()->headObject(['Bucket' => $this->bucket('s3-test-public'), 'Key' => $issued['key']]);
        $this->assertSame('image/png', (string) $head['ContentType']);
    }

    public function test_multipart_abort_removes_the_pending_upload(): void
    {
        $create = $this->controller()->createMultipartUpload(
            Request::create('/', 'POST', ['filename' => 'scrap.mp4', 'visibility' => 'private'])
        );
        $created = json_decode((string) $create->getContent(), true)['data'];

        $abort = $this->controller()->abortMultipartUpload(
            Request::create('/', 'POST', [
                'key' => $created['key'],
                'upload_id' => $created['upload_id'],
                'upload_token' => $created['upload_token'],
                'expires_at' => $created['expires_at'],
                'visibility' => 'private',
            ])
        );
        $this->assertSame(200, $abort->getStatusCode());

        $uploads = $this->s3()->listMultipartUploads(['Bucket' => $this->bucket('s3-test-private')]);
        $ids = [];
        foreach ($uploads['Uploads'] ?? [] as $upload) {
            $ids[] = $upload['UploadId'];
        }

        $this->assertNotContains($created['upload_id'], $ids);
    }
}
