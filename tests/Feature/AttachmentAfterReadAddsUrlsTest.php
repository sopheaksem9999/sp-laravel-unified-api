<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttachmentAfterReadAddsUrlsTest extends TestCase
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

        $config = require __DIR__ . '/../../config/sp-attachments.php';
        $tableConfig = $config['tables']['sp_attachments'];
        $tableConfig->isAuthRead = false;

        Config::set('record.tables', ['sp_attachments' => $tableConfig]);
        SchemaRegistryUtils::refresh();

        DB::table('sp_attachments')->insert([
            'disk' => 'local',
            'path' => 'attachments/test.png',
            'filename' => 'test.png',
            'mime_type' => 'image/png',
            'size' => 123,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function it_injects_url_fields_on_list(): void
    {
        $response = $this->getJson('/api/sp_attachments');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.url', fn($value): bool => is_string($value) && '' !== $value);
        $response->assertJsonPath('data.0.download_url', fn($value): bool => is_string($value) && '' !== $value);
    }

    /** @test */
    public function it_injects_url_fields_on_show(): void
    {
        $id = (string) DB::table('sp_attachments')->value('id');

        $response = $this->getJson('/api/sp_attachments/' . $id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.url', fn($value): bool => is_string($value) && '' !== $value);
        $response->assertJsonPath('data.download_url', fn($value): bool => is_string($value) && '' !== $value);
    }
}
