<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Services\AttachmentUrlService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttachmentArchitectureSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAttachmentTables();

        Storage::fake('local');
        Storage::fake('public');

        Config::set('attachments.enabled', true);
        Config::set('attachments.disk_public', 'public');
        Config::set('attachments.disk_private', 'local');
        Config::set('attachments.temp_lifetime', 1440);
        Config::set('attachments.default_temp_visibility', 'temp_private');
        Config::set('attachments.max_temp_timeout_minutes', 43200);
        Config::set('attachments.protect_temp_public_via_download', false);
        Config::set('attachments.url_strategy', 'auto');
        Config::set('attachments.folder_delete_strategy', 'legacy');
        Config::set('attachments.access', [
            'validate_folder_exists' => true,
            'validate_record_exists' => false,
        ]);
        Config::set('attachments.tables', $this->attachmentTableConfig());

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function url_service_can_force_api_urls_without_changing_attachment_payload_shape(): void
    {
        Config::set('attachments.url_strategy', 'api');

        $attachment = app(AttachmentUrlService::class)->appendUrls([
            'id' => 'att-1',
            'disk' => 'public',
            'path' => 'attachments/public/file.txt',
            'visibility' => 'public',
        ]);

        $this->assertSame('http://localhost/api/sp_attachments/att-1/view', $attachment['url']);
        $this->assertSame('http://localhost/api/sp_attachments/att-1/download', $attachment['download_url']);
    }

    /** @test */
    public function upload_rejects_unknown_folder_when_folder_validation_is_enabled(): void
    {
        $request = Request::create(
            uri: '/attachments/upload',
            method: 'POST',
            parameters: [
                'folder_id' => Str::uuid()->toString(),
                'visibility' => 'private',
            ],
            files: [
                'file' => UploadedFile::fake()->create('sample.txt', 1, 'text/plain'),
            ]
        );

        $response = (new AttachmentUploadController())->upload($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Folder not found', data_get($response->getData(true), 'message'));
    }

    /** @test */
    public function replace_old_keeps_reused_attachment_file_and_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:00:00'));

        $oldAttachmentId = Str::uuid()->toString();
        $oldPath = 'attachments/private/reused.txt';
        Storage::disk('local')->put($oldPath, 'old-content');

        DB::table('sp_attachments')->insert($this->attachmentRow([
            'id' => $oldAttachmentId,
            'path' => $oldPath,
            'filename' => 'reused.txt',
        ]));

        DB::table('sp_attachment_links')->insert([
            [
                'attachment_id' => $oldAttachmentId,
                'record_id' => 'record-1',
                'record_type' => 'users',
                'collection_name' => 'avatar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'attachment_id' => $oldAttachmentId,
                'record_id' => 'record-2',
                'record_type' => 'users',
                'collection_name' => 'avatar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $request = Request::create(
            uri: '/attachments/upload',
            method: 'POST',
            parameters: [
                'record_id' => 'record-1',
                'record_type' => 'users',
                'collection_name' => 'avatar',
                'replace_old' => true,
                'visibility' => 'private',
            ],
            files: [
                'file' => UploadedFile::fake()->create('new.txt', 1, 'text/plain'),
            ]
        );

        $response = (new AttachmentUploadController())->upload($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('sp_attachments', ['id' => $oldAttachmentId]);
        $this->assertTrue(Storage::disk('local')->exists($oldPath));
        $this->assertDatabaseHas('sp_attachment_links', [
            'attachment_id' => $oldAttachmentId,
            'record_id' => 'record-2',
        ]);

        Carbon::setTestNow();
    }

    /** @test */
    public function folder_delete_can_be_configured_to_restrict_non_empty_folders(): void
    {
        Config::set('attachments.folder_delete_strategy', 'restrict');

        $folderId = Str::uuid()->toString();
        DB::table('sp_document_folders')->insert([
            'id' => $folderId,
            'name' => 'Internal',
            'scope' => 'internal',
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sp_attachments')->insert($this->attachmentRow([
            'folder_id' => $folderId,
        ]));

        $response = (new AttachmentUploadController())->deleteFolder(
            Request::create('/attachments/folders/' . $folderId, 'DELETE'),
            $folderId
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Folder is not empty', data_get($response->getData(true), 'message'));
        $this->assertDatabaseHas('sp_document_folders', ['id' => $folderId]);
    }

    /** @test */
    public function migration_does_not_create_attachment_tables_when_module_is_disabled(): void
    {
        Schema::dropIfExists('sp_attachment_links');
        Schema::dropIfExists('sp_attachments');
        Schema::dropIfExists('sp_document_folders');

        Config::set('attachments.enabled', false);

        $migration = require __DIR__ . '/../../database/migrations/2024_01_01_000000_create_sp_attachments_tables.php';
        $migration->up();

        $this->assertFalse(Schema::hasTable('sp_document_folders'));
        $this->assertFalse(Schema::hasTable('sp_attachments'));
        $this->assertFalse(Schema::hasTable('sp_attachment_links'));
    }

    /** @test */
    public function upload_rejects_temp_timeout_at_after_configured_maximum(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:00:00'));

        $request = Request::create(
            uri: '/attachments/upload',
            method: 'POST',
            parameters: [
                'visibility' => 'temp_private',
                'temp_timeout_at' => Carbon::now()->addDays(31)->toDateTimeString(),
            ],
            files: [
                'file' => UploadedFile::fake()->create('sample.txt', 1, 'text/plain'),
            ]
        );

        $response = (new AttachmentUploadController())->upload($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Temporary timeout exceeds maximum allowed minutes', data_get($response->getData(true), 'message'));

        Carbon::setTestNow();
    }

    /** @test */
    public function link_to_record_can_use_opt_in_record_authorizer(): void
    {
        Config::set('attachments.access.record_authorizer', static fn(): bool => false);

        $attachmentId = Str::uuid()->toString();
        DB::table('sp_attachments')->insert($this->attachmentRow([
            'id' => $attachmentId,
        ]));

        $request = Request::create(
            uri: '/attachments/record/users/user-1',
            method: 'POST',
            parameters: [
                'attachment_id' => $attachmentId,
            ]
        );

        $response = (new AttachmentUploadController())->linkToRecord($request, 'users', 'user-1');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Attachment access denied', data_get($response->getData(true), 'message'));
    }

    private function createAttachmentTables(): void
    {
        Schema::dropIfExists('sp_attachment_links');
        Schema::dropIfExists('sp_attachments');
        Schema::dropIfExists('sp_document_folders');

        Schema::create('sp_document_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('parent_id')->nullable()->index();
            $table->string('scope')->default('internal')->index();
            $table->string('visibility')->default('private')->index();
            $table->string('owner_type')->nullable()->index();
            $table->string('owner_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sp_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('folder_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('visibility');
            $table->timestamp('temp_timeout')->nullable();
            $table->timestamps();
        });

        Schema::create('sp_attachment_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attachment_id')->index();
            $table->string('record_id')->nullable()->index();
            $table->string('record_type')->nullable()->index();
            $table->string('collection_name')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, RecordTableType>
     */
    private function attachmentTableConfig(): array
    {
        return [
            'sp_attachments' => new RecordTableType(
                table: 'sp_attachments',
                pmsName: 'attachments',
                hasTenantId: false,
                isAuthRead: false,
                isAuthWrite: false,
                columns: [
                    'id' => ['type' => 'string', 'nullable' => false],
                    'folder_id' => ['type' => 'string', 'nullable' => true],
                    'title' => ['type' => 'string', 'nullable' => true],
                    'caption' => ['type' => 'string', 'nullable' => true],
                    'disk' => ['type' => 'string', 'nullable' => false],
                    'path' => ['type' => 'string', 'nullable' => false],
                    'filename' => ['type' => 'string', 'nullable' => false],
                    'mime_type' => ['type' => 'string', 'nullable' => false],
                    'size' => ['type' => 'integer', 'nullable' => false],
                    'visibility' => ['type' => 'string', 'nullable' => false],
                    'temp_timeout' => ['type' => 'datetime', 'nullable' => true],
                ]
            ),
            'sp_document_folders' => new RecordTableType(
                table: 'sp_document_folders',
                pmsName: 'attachments',
                hasTenantId: false,
                isAuthRead: false,
                isAuthWrite: false,
                columns: [
                    'id' => ['type' => 'string', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'parent_id' => ['type' => 'string', 'nullable' => true],
                    'scope' => ['type' => 'string', 'nullable' => false],
                    'visibility' => ['type' => 'string', 'nullable' => false],
                    'owner_type' => ['type' => 'string', 'nullable' => true],
                    'owner_id' => ['type' => 'string', 'nullable' => true],
                    'metadata' => ['type' => 'json', 'nullable' => true],
                ]
            ),
            'sp_attachment_links' => new RecordTableType(
                table: 'sp_attachment_links',
                pmsName: 'attachments',
                hasTenantId: false,
                isAuthRead: false,
                isAuthWrite: false,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'attachment_id' => ['type' => 'string', 'nullable' => false],
                    'record_id' => ['type' => 'string', 'nullable' => true],
                    'record_type' => ['type' => 'string', 'nullable' => true],
                    'collection_name' => ['type' => 'string', 'nullable' => true],
                ]
            ),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function attachmentRow(array $overrides = []): array
    {
        return array_merge([
            'id' => Str::uuid()->toString(),
            'folder_id' => null,
            'title' => null,
            'caption' => null,
            'disk' => 'local',
            'path' => 'attachments/private/file.txt',
            'filename' => 'file.txt',
            'mime_type' => 'text/plain',
            'size' => 4,
            'visibility' => 'private',
            'temp_timeout' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}
