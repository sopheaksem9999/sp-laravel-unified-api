<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttachmentReadResizeTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Same uuid requirement as AttachmentTempOptionsTest: this test
        // inserts uuid-shaped ids directly into the real migrated table.
        $app['config']->set('record.id_type', 'uuid');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Config::set('attachments.enabled', true);
        Config::set('attachments.tables', [
            'sp_attachments' => new RecordTableType(
                table: 'sp_attachments',
                pmsName: 'attachments',
                hasTenantId: false,
                isAuthRead: false,
                isAuthWrite: false,
                columns: [
                    'id' => ['type' => 'uuid', 'nullable' => false],
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
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    private function insertImageAttachment(string $path, string $mimeType = 'image/jpeg', ?string $bytes = null): string
    {
        $id = Str::uuid()->toString();
        Storage::disk('local')->put($path, $bytes ?? $this->jpegBytes(100, 80));

        DB::table('sp_attachments')->insert([
            'id' => $id,
            'title' => 'Image',
            'caption' => null,
            'disk' => 'local',
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => $mimeType,
            'size' => Storage::disk('local')->size($path),
            'visibility' => 'private',
            'temp_timeout' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $id;
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 30));
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * StreamedResponse bodies are generated lazily, so getContent() returns
     * false. Capture the streamed output instead.
     */
    private function streamedContent(mixed $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** @test */
    public function view_ignores_resize_params_when_feature_disabled(): void
    {
        $originalBytes = $this->jpegBytes(100, 80);
        $id = $this->insertImageAttachment('attachments/original.jpg', 'image/jpeg', $originalBytes);

        $controller = new AttachmentUploadController();
        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=64&h=48&format=webp', 'GET'), $id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($originalBytes, $this->streamedContent($response));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    /** @test */
    public function view_resizes_image_when_enabled_and_params_sent(): void
    {
        Config::set('attachments.read_resizing', true);
        $id = $this->insertImageAttachment('attachments/original.jpg');

        $controller = new AttachmentUploadController();
        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=64&h=48&fit=contain&format=webp', 'GET'), $id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));

        $info = getimagesizefromstring($this->streamedContent($response));
        $this->assertNotFalse($info);
        $this->assertSame(60, $info[0]);
        $this->assertSame(48, $info[1]);
    }

    /** @test */
    public function view_serves_original_when_enabled_but_no_params_sent(): void
    {
        Config::set('attachments.read_resizing', true);
        $originalBytes = $this->jpegBytes(100, 80);
        $id = $this->insertImageAttachment('attachments/original.jpg', 'image/jpeg', $originalBytes);

        $controller = new AttachmentUploadController();
        $response = $controller->view(Request::create('/attachments/' . $id . '/view', 'GET'), $id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($originalBytes, $this->streamedContent($response));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    /** @test */
    public function view_serves_non_image_untouched_even_with_resize_params(): void
    {
        Config::set('attachments.read_resizing', true);
        $id = Str::uuid()->toString();
        Storage::disk('local')->put('attachments/doc.pdf', 'fake-pdf-bytes');

        DB::table('sp_attachments')->insert([
            'id' => $id,
            'title' => 'Document',
            'caption' => null,
            'disk' => 'local',
            'path' => 'attachments/doc.pdf',
            'filename' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size' => 14,
            'visibility' => 'private',
            'temp_timeout' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $controller = new AttachmentUploadController();
        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=100', 'GET'), $id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fake-pdf-bytes', $this->streamedContent($response));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /** @test */
    public function view_rejects_out_of_range_dimensions_and_invalid_formats(): void
    {
        Config::set('attachments.read_resizing', true);
        $id = $this->insertImageAttachment('attachments/original.jpg');

        $controller = new AttachmentUploadController();

        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=99999', 'GET'), $id);
        $this->assertSame(422, $response->getStatusCode());

        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=0', 'GET'), $id);
        $this->assertSame(422, $response->getStatusCode());

        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=100&format=exe', 'GET'), $id);
        $this->assertSame(422, $response->getStatusCode());
    }

    /** @test */
    public function view_applies_configured_size_name(): void
    {
        Config::set('attachments.read_resizing', true);
        Config::set('attachments.image_sizes', [
            'thumbnail' => ['w' => 150, 'h' => 150, 'fit' => 'crop'],
        ]);
        $id = $this->insertImageAttachment('attachments/original.jpg');

        $controller = new AttachmentUploadController();
        $response = $controller->view(Request::create('/attachments/' . $id . '/view?size_name=thumbnail&format=jpg', 'GET'), $id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));

        $info = getimagesizefromstring($this->streamedContent($response));
        $this->assertNotFalse($info);
        $this->assertSame(150, $info[0]);
        $this->assertSame(150, $info[1]);
    }

    /** @test */
    public function view_adds_cache_headers_only_when_max_age_configured(): void
    {
        Config::set('attachments.read_resizing', true);
        $id = $this->insertImageAttachment('attachments/original.jpg');

        $controller = new AttachmentUploadController();

        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=64&format=jpg', 'GET'), $id);
        $this->assertStringNotContainsString('max-age', (string) $response->headers->get('Cache-Control'));

        Config::set('attachments.read_resize_cache_max_age', 3600);
        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=64&format=jpg', 'GET'), $id);
        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
    }

    /** @test */
    public function view_write_back_cache_serves_derived_file_after_first_hit(): void
    {
        Config::set('attachments.read_resizing', true);
        Config::set('attachments.read_resize_cache', true);
        Config::set('attachments.read_resize_cache_disk', 'public');
        $id = $this->insertImageAttachment('attachments/original.jpg');

        $controller = new AttachmentUploadController();

        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=64&format=jpg', 'GET'), $id);
        $this->assertSame(200, $response->getStatusCode());

        $cachedFiles = Storage::disk('public')->allFiles('attachments/resized');
        $this->assertCount(1, $cachedFiles);

        Storage::disk('public')->put($cachedFiles[0], 'sentinel-cached-bytes');

        $response = $controller->view(Request::create('/attachments/' . $id . '/view?w=64&format=jpg', 'GET'), $id);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sentinel-cached-bytes', $this->streamedContent($response));
    }
}
