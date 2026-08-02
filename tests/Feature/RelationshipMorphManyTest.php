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
use Sopheak\Core\Types\RecordMorphHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class RelationshipMorphManyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('videos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('target_type');
            $table->uuid('target_id');
            $table->string('locale');
            $table->string('field');
            $table->text('value');
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
                    'translations' => new RecordMorphHasManyType(
                        table: 'translations',
                        morphType: 'target_type',
                        morphId: 'target_id',
                        morphClass: 'videos',
                        localKey: 'id',
                    ),
                ],
            ),
            'promotions' => new RecordTableType(
                table: 'promotions',
                pmsName: 'promotions',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'translations' => new RecordMorphHasManyType(
                        table: 'translations',
                        morphType: 'target_type',
                        morphId: 'target_id',
                        morphClass: 'promotions',
                        localKey: 'id',
                    ),
                ],
            ),
            'translations' => new RecordTableType(
                table: 'translations',
                pmsName: 'translations',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    public function test_include_translations_filters_by_morph_class(): void
    {
        $sharedId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $sharedId, 'title' => 'Video 1']);
        DB::table('promotions')->insert(['id' => $sharedId, 'title' => 'Promo 1']);

        DB::table('translations')->insert(['id' => (string) Str::uuid(), 'target_type' => 'videos', 'target_id' => $sharedId, 'locale' => 'km-KH', 'field' => 'title', 'value' => 'ភាពយន្ត']);
        DB::table('translations')->insert(['id' => (string) Str::uuid(), 'target_type' => 'promotions', 'target_id' => $sharedId, 'locale' => 'km-KH', 'field' => 'title', 'value' => 'ផ្សព្វផ្សាយ']);

        $request = Request::create('/api/v1/videos', 'GET', ['select' => '*,translations(*)']);

        $schema = SchemaRegistryUtils::get();
        $result = RecordService::applyRequestFilters($request, $schema['videos']);
        $data = $result['data'];

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]->translations);
        $this->assertEquals('km-KH', $data[0]->translations[0]->locale);
        $this->assertEquals('ភាពយន្ត', $data[0]->translations[0]->value);
    }

    public function test_nested_create_sets_morph_type_and_morph_id(): void
    {
        $videoId = (string) Str::uuid();

        $payload = [
            'title' => 'Video 1',
            'translations' => [
                ['locale' => 'km-KH', 'field' => 'title', 'value' => 'ភាពយន្ត'],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('videos', $payload, $videoId, null, 'create');

        $rows = DB::table('translations')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('videos', $rows[0]->target_type);
        $this->assertEquals($videoId, $rows[0]->target_id);
        $this->assertEquals('km-KH', $rows[0]->locale);
    }

    public function test_nested_create_overrides_client_supplied_discriminator(): void
    {
        $videoId = (string) Str::uuid();

        $payload = [
            'title' => 'Video 1',
            'translations' => [
                [
                    'target_type' => 'promotions', // malicious/wrong value — must be overridden
                    'target_id' => (string) Str::uuid(),
                    'locale' => 'km-KH',
                    'field' => 'title',
                    'value' => 'ភាពយន្ត',
                ],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('videos', $payload, $videoId, null, 'create');

        $rows = DB::table('translations')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('videos', $rows[0]->target_type);
        $this->assertEquals($videoId, $rows[0]->target_id);
        $this->assertTrue(Str::isUuid($rows[0]->id));
    }

    public function test_nested_update_can_modify_a_translation_belonging_to_the_same_parent(): void
    {
        $videoId = (string) Str::uuid();
        $translationId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $videoId, 'title' => 'Video 1']);
        DB::table('translations')->insert([
            'id' => $translationId,
            'target_type' => 'videos',
            'target_id' => $videoId,
            'locale' => 'km-KH',
            'field' => 'title',
            'value' => 'original',
        ]);

        $payload = [
            'translations' => [
                ['id' => $translationId, 'value' => 'updated'],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('videos', $payload, $videoId, null, 'update');

        $this->assertSame('updated', DB::table('translations')->where('id', $translationId)->value('value'));
    }

    public function test_nested_delete_can_delete_a_translation_belonging_to_the_same_parent(): void
    {
        $videoId = (string) Str::uuid();
        $translationId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $videoId, 'title' => 'Video 1']);
        DB::table('translations')->insert([
            'id' => $translationId,
            'target_type' => 'videos',
            'target_id' => $videoId,
            'locale' => 'km-KH',
            'field' => 'title',
            'value' => 'original',
        ]);

        $payload = [
            'translations' => [
                ['id' => $translationId, '_delete' => true],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('videos', $payload, $videoId, null, 'update');

        $this->assertDatabaseMissing('translations', ['id' => $translationId]);
    }

    public function test_nested_update_cannot_modify_a_translation_belonging_to_a_different_parent(): void
    {
        $videoId = (string) Str::uuid();
        $promotionId = (string) Str::uuid();
        $foreignTranslationId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $videoId, 'title' => 'Video 1']);
        DB::table('promotions')->insert(['id' => $promotionId, 'title' => 'Promo 1']);
        DB::table('translations')->insert([
            'id' => $foreignTranslationId,
            'target_type' => 'promotions',
            'target_id' => $promotionId,
            'locale' => 'km-KH',
            'field' => 'title',
            'value' => 'original',
        ]);

        // Attacker only has access to $videoId, but references a translation that
        // actually belongs to a different promotion.
        $payload = [
            'translations' => [
                ['id' => $foreignTranslationId, 'value' => 'hacked'],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('videos', $payload, $videoId, null, 'update');

        $row = DB::table('translations')->where('id', $foreignTranslationId)->first();
        $this->assertSame('original', $row->value);
        $this->assertSame('promotions', $row->target_type);
        $this->assertSame($promotionId, $row->target_id);
    }

    public function test_nested_delete_cannot_delete_a_translation_belonging_to_a_different_parent(): void
    {
        $videoId = (string) Str::uuid();
        $promotionId = (string) Str::uuid();
        $foreignTranslationId = (string) Str::uuid();

        DB::table('videos')->insert(['id' => $videoId, 'title' => 'Video 1']);
        DB::table('promotions')->insert(['id' => $promotionId, 'title' => 'Promo 1']);
        DB::table('translations')->insert([
            'id' => $foreignTranslationId,
            'target_type' => 'promotions',
            'target_id' => $promotionId,
            'locale' => 'km-KH',
            'field' => 'title',
            'value' => 'original',
        ]);

        $payload = [
            'translations' => [
                ['id' => $foreignTranslationId, '_delete' => true],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('videos', $payload, $videoId, null, 'update');

        $this->assertDatabaseHas('translations', ['id' => $foreignTranslationId]);
    }
}
