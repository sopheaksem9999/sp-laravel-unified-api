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
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttachmentTempOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('sp_attachments')) {
            Schema::create('sp_attachments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('folder_id')->nullable();
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
        }

        if (!Schema::hasTable('sp_attachment_links')) {
            Schema::create('sp_attachment_links', function (Blueprint $table): void {
                $table->id();
                $table->uuid('attachment_id')->index();
                $table->uuid('record_id')->nullable();
                $table->string('record_type')->nullable();
                $table->string('collection_name')->nullable();
                $table->timestamps();
            });
        }

        Storage::fake('local');
        Storage::fake('public');

        Config::set('attachments.enabled', true);
        Config::set('attachments.temp_lifetime', 1440);
        Config::set('attachments.default_temp_visibility', 'temp_private');
        Config::set('attachments.max_temp_timeout_minutes', 43200);
        Config::set('attachments.protect_temp_public_via_download', false);
        Config::set('attachments.tables', [
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
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function upload_supports_as_temp_and_sets_temp_timeout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 10:00:00'));

        $request = Request::create(
            uri: '/attachments/upload',
            method: 'POST',
            parameters: [
                'as_temp' => true,
                'temp_timeout_minutes' => 30,
                'visibility' => 'private',
            ],
            files: [
                'file' => UploadedFile::fake()->image('sample.jpg', 100, 100),
            ]
        );

        $controller = new AttachmentUploadController();
        $response = $controller->upload($request);

        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);
        $data = $payload['data'] ?? $payload;
        $this->assertSame('temp_private', $data['visibility']);
        $this->assertSame('2026-04-03 10:30:00', Carbon::parse((string) $data['temp_timeout'])->toDateTimeString());

        Carbon::setTestNow();
    }

    /** @test */
    public function upload_accepts_string_boolean_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 10:00:00'));

        $request = Request::create(
            uri: '/attachments/upload',
            method: 'POST',
            parameters: [
                'as_temp' => 'false',
                'replace_old' => 'false',
                'visibility' => 'private',
            ],
            files: [
                'file' => UploadedFile::fake()->create('sample.txt', 1, 'text/plain'),
            ]
        );

        $controller = new AttachmentUploadController();
        $response = $controller->upload($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $data = $payload['data'] ?? $payload;
        $this->assertSame('private', $data['visibility']);

        Carbon::setTestNow();
    }

    /** @test */
    public function clone_temp_creates_new_attachment_and_copies_file(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:00:00'));

        Storage::disk('local')->put('attachments/source/source.txt', 'temp-clone-source');

        $sourceId = Str::uuid()->toString();
        DB::table('sp_attachments')->insert([
            'id' => $sourceId,
            'title' => 'Source',
            'caption' => 'Source caption',
            'disk' => 'local',
            'path' => 'attachments/source/source.txt',
            'filename' => 'source.txt',
            'mime_type' => 'text/plain',
            'size' => 17,
            'visibility' => 'private',
            'temp_timeout' => null,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $request = Request::create(
            uri: '/attachments/clone-temp',
            method: 'POST',
            parameters: [
                'attachment_id' => $sourceId,
                'visibility' => 'temp_public',
                'temp_timeout_minutes' => 15,
            ]
        );

        $controller = new AttachmentUploadController();
        $response = $controller->cloneTemp($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $data = $payload['data'] ?? $payload;

        $this->assertNotSame($sourceId, $data['id']);
        $this->assertSame('temp_public', $data['visibility']);
        $this->assertSame('2026-04-03 12:15:00', Carbon::parse((string) $data['temp_timeout'])->toDateTimeString());
        $this->assertTrue(Storage::disk('public')->exists($data['path']));
        $this->assertSame('temp-clone-source', Storage::disk('public')->get($data['path']));

        Carbon::setTestNow();
    }

    /** @test */
    public function clone_temp_uses_protected_download_url_for_temp_public_when_enabled(): void
    {
        Config::set('attachments.protect_temp_public_via_download', true);
        Carbon::setTestNow(Carbon::parse('2026-04-03 14:00:00'));

        Storage::disk('local')->put('attachments/source/protected.txt', 'protected-temp-public');

        $sourceId = Str::uuid()->toString();
        DB::table('sp_attachments')->insert([
            'id' => $sourceId,
            'title' => 'Source',
            'caption' => null,
            'disk' => 'local',
            'path' => 'attachments/source/protected.txt',
            'filename' => 'protected.txt',
            'mime_type' => 'text/plain',
            'size' => 21,
            'visibility' => 'private',
            'temp_timeout' => null,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $request = Request::create(
            uri: '/attachments/clone-temp',
            method: 'POST',
            parameters: [
                'attachment_id' => $sourceId,
                'visibility' => 'temp_public',
                'temp_timeout_minutes' => 30,
            ]
        );

        $controller = new AttachmentUploadController();
        $response = $controller->cloneTemp($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $data = $payload['data'] ?? $payload;
        $this->assertSame('temp_public', $data['visibility']);
        $this->assertStringContainsString('/api/' . config('attachments.route_prefix', 'attachments') . '/' . $data['id'] . '/view', (string) $data['url']);

        Carbon::setTestNow();
    }

    /** @test */
    public function download_denies_expired_temp_attachment_with_gone_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 16:00:00'));

        Storage::disk('local')->put('attachments/temp/expired-download.txt', 'expired-content');

        $attachmentId = Str::uuid()->toString();
        DB::table('sp_attachments')->insert([
            'id' => $attachmentId,
            'title' => 'Expired Temp',
            'caption' => null,
            'disk' => 'local',
            'path' => 'attachments/temp/expired-download.txt',
            'filename' => 'expired-download.txt',
            'mime_type' => 'text/plain',
            'size' => 15,
            'visibility' => 'temp_private',
            'temp_timeout' => Carbon::now()->subMinute()->toDateTimeString(),
            'created_at' => Carbon::now()->subHour()->toDateTimeString(),
            'updated_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);

        $controller = new AttachmentUploadController();
        $response = $controller->download(Request::create('/attachments/' . $attachmentId . '/download', 'GET'), $attachmentId);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('Attachment has expired', data_get($response->getData(true), 'message'));
        $this->assertTrue(Storage::disk('local')->exists('attachments/temp/expired-download.txt'));

        Carbon::setTestNow();
    }
}
