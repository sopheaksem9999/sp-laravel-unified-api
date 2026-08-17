<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;

class AttachmentDirectUploadContractTest extends TestCase
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

    public function test_local_disk_returns_post_fallback_for_upload_url(): void
    {
        Storage::fake('local');

        $response = app(AttachmentUploadController::class)->createUploadUrl(
            Request::create('/', 'POST', ['filename' => 'poster.jpg'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame('POST', $data['method']);
        $this->assertNull($data['upload_url']);
    }

    public function test_complete_upload_persists_file_using_metadata_from_the_posted_file(): void
    {
        Storage::fake('local');

        $issued = $this->issueUploadUrl();
        $request = Request::create('/', 'POST', [
            'key' => $issued['key'],
            'upload_token' => $issued['upload_token'],
            'expires_at' => $issued['expires_at'],
            'visibility' => 'private',
        ]);
        $request->files->set('file', UploadedFile::fake()->image('poster.jpg'));

        $response = app(AttachmentUploadController::class)->completeUpload($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame($issued['key'], $data['path']);
        Storage::disk('local')->assertExists($issued['key']);
        $this->assertDatabaseHas('sp_attachments', ['path' => $issued['key'], 'disk' => 'local']);
    }

    private function issueUploadUrl(): array
    {
        $response = app(AttachmentUploadController::class)->createUploadUrl(
            Request::create('/', 'POST', ['filename' => 'poster.jpg', 'visibility' => 'private'])
        );

        return json_decode((string) $response->getContent(), true)['data'];
    }

    public function test_disabled_direct_upload_functions_are_removed_from_the_registry(): void
    {
        Config::set('attachments.direct_upload.enabled', false);

        $table = RecordConfigService::getTableConfig('sp_attachments');

        $this->assertInstanceOf(RecordTableType::class, $table);
        $this->assertNotContains('create-upload-url', array_keys($table->functions ?? []));
        $this->assertNotContains('complete-upload', array_keys($table->functions ?? []));
    }
}
