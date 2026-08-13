<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Column-level `select` projection must not drop the key columns relationships
 * are matched and grouped on.
 *
 * Reported by KarunaFilm 2026-08-13: a query switched from `select=*` to an
 * explicit projection started returning `null` relationships with no error and
 * no log, while the same shape using `*` worked. Three separate places dropped
 * a join key:
 *
 *   Bug A  a relation with nested children lost the parent-side key its
 *          children match on          (RelationshipResolverUtils, recursive include)
 *   Bug B  child rows lost the column they are grouped back by
 *                                     (RelationshipResolverUtils, optimized load)
 *   Bug C  the root query lost the FK/local key top-level includes match on
 *                                     (QueryBuilderFiltersUtils::apply)
 *
 * Every assertion here checks the relationship is POPULATED, not merely that
 * the request returned 200 -- the failure mode is a silent null, so asserting
 * on status would pass against the bug.
 *
 * `subquery_optimization_max_records` is pinned to 0 so these run against the
 * PHP-side resolver. The subquery path selects its own keys and masks all three
 * bugs; the reporter's cases 4 and 5 "worked" for exactly that reason.
 */
class SelectProjectionJoinKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('url');
            $table->timestamps();
        });

        Schema::create('video_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('video_category_id')->nullable();
            $table->unsignedBigInteger('profile_image_id')->nullable();
            $table->timestamps();
        });

        Schema::create('video_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('target_id');
            $table->string('locale');
            $table->string('field');
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('episodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('video_id');
            $table->integer('episode_number');
            $table->timestamps();
        });

        Config::set('record.cache.enabled', false);
        Config::set('record.subquery_optimization_max_records', 0);
        Config::set('record.tables', [
            'videos' => new RecordTableType(
                table: 'videos',
                pmsName: 'videos',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'video_category' => new RecordBelongsToType(
                        table: 'video_categories',
                        foreignKey: 'video_category_id',
                        ownerKey: 'id',
                    ),
                    'profile_image' => new RecordBelongsToType(
                        table: 'attachments',
                        foreignKey: 'profile_image_id',
                        ownerKey: 'id',
                    ),
                    'translations' => new RecordHasManyType(
                        table: 'video_translations',
                        foreignKey: 'target_id',
                        localKey: 'id',
                    ),
                ],
            ),
            'episodes' => new RecordTableType(
                table: 'episodes',
                pmsName: 'episodes',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'video' => new RecordBelongsToType(
                        table: 'videos',
                        foreignKey: 'video_id',
                        ownerKey: 'id',
                    ),
                ],
            ),
            'video_categories' => new RecordTableType(
                table: 'video_categories',
                pmsName: 'video_categories',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
            'video_translations' => new RecordTableType(
                table: 'video_translations',
                pmsName: 'video_translations',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
            'attachments' => new RecordTableType(
                table: 'attachments',
                pmsName: 'attachments',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();

        DB::table('attachments')->insert(['id' => 90, 'url' => 'https://cdn.example/poster.png', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('video_categories')->insert(['id' => 7, 'name' => 'Tutorials', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('videos')->insert(['id' => 1, 'title' => 'Intro', 'video_category_id' => 7, 'profile_image_id' => 90, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('video_translations')->insert([
            ['id' => 1, 'target_id' => 1, 'locale' => 'km', 'field' => 'title', 'value' => 'សេចក្តីផ្តើម', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'target_id' => 1, 'locale' => 'en', 'field' => 'title', 'value' => 'Intro', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('episodes')->insert(['id' => 50, 'video_id' => 1, 'episode_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Bug C, belongsTo: the root query must keep the FK a top-level include
     * matches on, even when `select` does not name it.
     */
    public function test_root_projection_keeps_the_foreign_key_a_belongs_to_include_needs(): void
    {
        $response = $this->getJson('/api/videos?select=id,title,video_category(id,name)');
        $response->assertStatus(200);

        $this->assertSame(
            'Tutorials',
            $response->json('data.0.video_category.name'),
            'video_category collapsed to null: the root projection dropped video_category_id'
        );
    }

    /**
     * Bug C, hasMany: same, for the root local key.
     */
    public function test_root_projection_keeps_the_local_key_a_has_many_include_needs(): void
    {
        $response = $this->getJson('/api/videos?select=title,translations(id,locale,value)');
        $response->assertStatus(200);

        $this->assertCount(
            2,
            $response->json('data.0.translations') ?? [],
            'translations came back empty: the root projection dropped videos.id'
        );
    }

    /**
     * Bug A: a relation carrying nested children must keep the parent-side key
     * those children match on. Here `video(id,title,...)` must also load
     * `profile_image_id` or the nested attachment resolves to null.
     *
     * This is the reporter's case 2 -- the one that produced missing thumbnails.
     */
    public function test_nested_child_resolves_when_the_parent_relation_has_explicit_columns(): void
    {
        $response = $this->getJson('/api/episodes?select=id,video(id,title,profile_image(*))');
        $response->assertStatus(200);

        $this->assertSame(
            'Intro',
            $response->json('data.0.video.title'),
            'precondition: the video relation itself must resolve'
        );
        $this->assertSame(
            'https://cdn.example/poster.png',
            $response->json('data.0.video.profile_image.url'),
            'nested profile_image collapsed to null: the video relation dropped profile_image_id'
        );
    }

    /**
     * Bug B: child rows must keep the column they are grouped back by. Selecting
     * `translations(locale,field,value)` drops `target_id`, so every fetched row
     * fails the grouping map and the relation returns empty.
     *
     * This is the reporter's translations case.
     */
    public function test_child_rows_keep_their_grouping_key_when_columns_are_explicit(): void
    {
        $response = $this->getJson('/api/videos?select=id,translations(locale,field,value)');
        $response->assertStatus(200);

        $translations = $response->json('data.0.translations') ?? [];
        $this->assertCount(
            2,
            $translations,
            'translations came back empty: the child projection dropped the target_id grouping key'
        );
        $this->assertEqualsCanonicalizing(
            ['km', 'en'],
            array_column($translations, 'locale')
        );
    }

    /**
     * The reporter's control case: wildcards select every key column, so the
     * same shape has always worked. Guards against a fix that breaks `*`.
     */
    public function test_wildcard_projection_still_resolves_every_level(): void
    {
        $response = $this->getJson('/api/episodes?select=*,video(*,profile_image(*),translations(*))');
        $response->assertStatus(200);

        $this->assertSame('Intro', $response->json('data.0.video.title'));
        $this->assertSame('https://cdn.example/poster.png', $response->json('data.0.video.profile_image.url'));
        $this->assertCount(2, $response->json('data.0.video.translations') ?? []);
    }

    /**
     * Plain projections with no includes must be untouched by the augmentation.
     */
    public function test_projection_without_includes_is_unchanged(): void
    {
        $response = $this->getJson('/api/videos?select=title');
        $response->assertStatus(200);

        $row = $response->json('data.0');
        $this->assertSame(['title' => 'Intro'], $row, 'a select with no includes must return exactly what was asked for');
    }

    /**
     * DOCUMENTS a side effect, it does not endorse it.
     *
     * The augmentation adds the join key to the SQL projection but never strips
     * it before the response is built, so `select` stops being an exact
     * projection contract: the client gets a column it did not ask for.
     */
    public function test_augmented_join_key_is_returned_to_the_client(): void
    {
        $response = $this->getJson('/api/videos?select=title,translations(id,locale,value)');
        $response->assertStatus(200);

        $this->assertArrayHasKey(
            'id',
            $response->json('data.0'),
            'current behaviour: videos.id was added to satisfy the include and is returned'
        );
    }
}
