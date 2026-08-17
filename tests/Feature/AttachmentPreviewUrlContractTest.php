<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Services\AttachmentPreviewUrlService;
use Sopheak\Core\Services\AttachmentUrlService;
use Sopheak\Core\Tests\TestCase;

class AttachmentPreviewUrlContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('attachments.preview_url_enabled', true);
        Config::set('attachments.disk_private', 'local');
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

    private function attachment(): object
    {
        Storage::fake('local');
        Storage::disk('local')->put('attachments/private/secret.jpg', 'fake-image-bytes');

        DB::table('sp_attachments')->insert([
            'disk' => 'local',
            'path' => 'attachments/private/secret.jpg',
            'filename' => 'secret.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 16,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('sp_attachments')->first();
    }

    public function test_private_attachment_gets_a_signed_preview_url_when_enabled(): void
    {
        $attachment = $this->attachment();

        $urls = app(AttachmentUrlService::class)->appendUrls((array) $attachment);

        $this->assertArrayHasKey('preview_url', $urls);
    }

    public function test_preview_endpoint_streams_a_valid_signed_url(): void
    {
        $attachment = $this->attachment();
        $signedUrl = app(AttachmentPreviewUrlService::class)->signedUrl((array) $attachment);
        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $query);

        $response = app(AttachmentUploadController::class)->preview(
            Request::create('/preview', 'GET', $query),
            (string) $attachment->id
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_preview_endpoint_rejects_a_tampered_signature(): void
    {
        $attachment = $this->attachment();
        $signedUrl = app(AttachmentPreviewUrlService::class)->signedUrl((array) $attachment);
        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $query);
        $query['signature'] = str_repeat('0', 64);

        $response = app(AttachmentUploadController::class)->preview(
            Request::create('/preview', 'GET', $query),
            (string) $attachment->id
        );

        $this->assertSame(410, $response->getStatusCode());
    }

    public function test_signed_url_includes_the_rpc_segment_when_rpc_prefix_is_set(): void
    {
        Config::set('record.rpc_prefix', 'rpc');

        $attachment = $this->attachment();
        $signedUrl = app(AttachmentPreviewUrlService::class)->signedUrl((array) $attachment);

        $this->assertStringContainsString('/' . config('attachments.route_prefix') . '/rpc/' . $attachment->id . '/preview', $signedUrl);
    }

    public function test_signed_url_omits_the_rpc_segment_when_rpc_prefix_is_empty(): void
    {
        Config::set('record.rpc_prefix', '');

        $attachment = $this->attachment();
        $signedUrl = app(AttachmentPreviewUrlService::class)->signedUrl((array) $attachment);

        $this->assertStringContainsString('/' . config('attachments.route_prefix') . '/' . $attachment->id . '/preview', $signedUrl);
    }

    public function test_signed_preview_url_resolves_through_the_http_route(): void
    {
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('attachments.preview_url_enabled', true);
        $attachmentConfig = require __DIR__ . '/../../config/sp-attachments.php';
        Config::set('record.tables', $attachmentConfig['tables'] ?? []);

        $attachment = $this->attachment();
        $signedUrl = app(AttachmentPreviewUrlService::class)->signedUrl((array) $attachment);

        $response = $this->get($signedUrl);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fake-image-bytes', $response->streamedContent());
    }
}
