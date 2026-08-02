<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttachmentUrlEmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('videos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->uuid('profile_image_id')->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', [
            'videos' => new RecordTableType(
                table: 'videos',
                pmsName: 'videos',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'profile_image' => new RecordBelongsToType(
                        table: 'sp_attachments',
                        foreignKey: 'profile_image_id',
                        ownerKey: 'id',
                    ),
                ],
            ),
            'sp_attachments' => new RecordTableType(
                table: 'sp_attachments',
                pmsName: 'sp_attachments',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    public function test_embedded_public_attachment_gets_urls_via_subquery_path(): void
    {
        $videoId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $videoId, 'title' => 'Video 1', 'profile_image_id' => $attachmentId]);
        DB::table('sp_attachments')->insert([
            'id' => $attachmentId,
            'disk' => 'public',
            'path' => 'images/poster.jpg',
            'filename' => 'poster.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'visibility' => 'public',
        ]);

        $request = Request::create('/api/v1/videos', 'GET', ['select' => '*,profile_image(*)']);

        $schema = SchemaRegistryUtils::get();
        $result = RecordService::applyRequestFilters($request, $schema['videos']);
        $data = $result['data'];

        $this->assertCount(1, $data);
        $profileImage = $data[0]->profile_image;
        $this->assertNotNull($profileImage);
        $profile = is_array($profileImage) ? $profileImage : (array) $profileImage;
        $this->assertSame($attachmentId, $profile['id']);
        $this->assertStringContainsString('/sp_attachments/'.$attachmentId.'/download', $profile['download_url']);
        $this->assertStringContainsString('/storage/images/poster.jpg', $profile['url']);
        $this->assertStringNotContainsString('/view', $profile['url']);
    }

    public function test_embedded_private_attachment_falls_back_to_api_view_url(): void
    {
        $videoId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $videoId, 'title' => 'Video 1', 'profile_image_id' => $attachmentId]);
        DB::table('sp_attachments')->insert([
            'id' => $attachmentId,
            'disk' => 'public',
            'path' => 'images/pending.jpg',
            'filename' => 'pending.jpg',
            'mime_type' => 'application/octet-stream',
            'size' => 0,
            'visibility' => 'private',
        ]);

        $request = Request::create('/api/v1/videos', 'GET', ['select' => '*,profile_image(*)']);

        $schema = SchemaRegistryUtils::get();
        $result = RecordService::applyRequestFilters($request, $schema['videos']);
        $data = $result['data'];

        $this->assertCount(1, $data);
        $profileImage = $data[0]->profile_image;
        $this->assertNotNull($profileImage);
        $profile = is_array($profileImage) ? $profileImage : (array) $profileImage;
        $this->assertStringContainsString('/sp_attachments/' . $attachmentId . '/view', $profile['url']);
    }

    public function test_embedded_attachment_gets_urls_via_recursive_include_path(): void
    {
        $videoId = (string) Str::uuid();
        $attachmentId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $videoId, 'title' => 'Video 1', 'profile_image_id' => $attachmentId]);
        DB::table('sp_attachments')->insert([
            'id' => $attachmentId,
            'disk' => 'public',
            'path' => 'images/banner.jpg',
            'filename' => 'banner.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'visibility' => 'public',
        ]);

        $rows = DB::table('videos')->get()->all();

        $data = RelationshipResolverUtils::includeRelationships($rows, 'videos', '*,profile_image(*)');

        $this->assertCount(1, $data);
        $this->assertNotNull($data[0]->profile_image);
        $this->assertStringContainsString('/sp_attachments/' . $attachmentId . '/download', $data[0]->profile_image->download_url);
        $this->assertStringContainsString('/storage/images/banner.jpg', $data[0]->profile_image->url);
    }

    public function test_non_attachment_embeds_are_unchanged(): void
    {
        // Regression guard: enrichment must only touch sp_attachments relations.
        $request = Request::create('/api/v1/videos', 'GET', ['select' => '*,profile_image(*)']);

        $schema = SchemaRegistryUtils::get();
        $result = RecordService::applyRequestFilters($request, $schema['videos']);
        $data = $result['data'];

        $this->assertSame([], $data);
    }
}
