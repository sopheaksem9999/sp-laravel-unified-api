<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\QueryBuilderFiltersUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class OwnRecordsIncludeUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * viewOwn restricted direct access to a table but not its rows loaded as a
 * related record, filtered on through a relationship, or cached inside a
 * response whose main table is unrestricted.
 *
 * @internal
 */
class OwnRecordsRelationshipIncludesTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 42;

    private const OTHER = 99;

    private const ADMIN = 1;

    /** The foreign widget as embedded JSON; the bare word also occurs in a comment body. */
    private const FOREIGN = '"name":"THEIRS"';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
        });
        DB::table('users')->insert([['id' => self::ADMIN], ['id' => self::OWNER], ['id' => 43]]);

        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->timestamps();
        });
        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('note_id');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('comments', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->unsignedBigInteger('widget_id');
            $t->timestamps();
        });
        Schema::create('note_widget', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->unsignedBigInteger('widget_id');
        });

        $now = now();
        DB::table('notes')->insert(['id' => 1, 'body' => 'N1', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'MINE', 'note_id' => 1, 'created_by_id' => self::OWNER, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'THEIRS', 'note_id' => 1, 'created_by_id' => self::OTHER, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('comments')->insert([
            ['id' => 1, 'body' => 'ON-THEIRS', 'widget_id' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'body' => 'ON-MINE', 'widget_id' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('note_widget')->insert([['note_id' => 1, 'widget_id' => 1], ['note_id' => 1, 'widget_id' => 2]]);

        $this->configureTables(cacheComments: false);

        // Everyone may do everything; only OWNER and 43 are own-restricted on widgets.
        Gate::before(function ($user, string $ability): bool {
            if (str_starts_with($ability, 'viewOwn:')) {
                return 'viewOwn:widget' === $ability && in_array((int) $user->id, [self::OWNER, 43], true);
            }

            return true;
        });
    }

    private function configureTables(bool $cacheComments, string $commentsKey = 'comments'): void
    {
        $base = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'created_at' => ['type' => 'datetime', 'nullable' => true],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ];

        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                pmsName: 'note',
                hasTenantId: false,
                columns: $base + ['body' => ['type' => 'string', 'nullable' => false]],
                relationships: [
                    'widgets' => new RecordHasManyType(table: 'widgets', foreignKey: 'note_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                    'linked' => new RecordMetaBelongsToManyType(related: 'widgets', table: 'note_widget', foreignPivotKey: 'note_id', relatedPivotKey: 'widget_id'),
                ],
            ),
            'widgets' => new RecordTableType(
                table: 'widgets',
                pmsName: 'widget',
                hasTenantId: false,
                columns: $base + [
                    'name' => ['type' => 'string', 'nullable' => false],
                    'note_id' => ['type' => 'bigInteger', 'nullable' => false],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                ],
                relationships: [
                    'comments' => new RecordHasManyType(table: 'comments', foreignKey: 'widget_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                ],
            ),
            $commentsKey => new RecordTableType(
                table: 'comments',
                pmsName: 'comment',
                hasTenantId: false,
                disableCache: !$cacheComments,
                columns: $base + ['body' => ['type' => 'string', 'nullable' => false], 'widget_id' => ['type' => 'bigInteger', 'nullable' => false]],
                relationships: [
                    'widget' => new RecordBelongsToType(table: 'widgets', type: RecordRelationshipsEnum::BELONGS_TO, foreignKey: 'widget_id', ownerKey: 'id'),
                ],
            ),
        ]);

        SchemaRegistryUtils::refresh();
        QueryBuilderFiltersUtils::clearColumnCache();
    }

    private function actAs(int $id): static
    {
        return $this->actingAs(OwnRecordsIncludeUser::query()->findOrFail($id), 'api');
    }

    private function body(int $userId, string $uri): string
    {
        return (string) $this->actAs($userId)->getJson($uri)->getContent();
    }

    /** @test */
    public function a_belongs_to_include_does_not_embed_a_foreign_row(): void
    {
        $this->assertStringNotContainsString(self::FOREIGN, $this->body(self::OWNER, '/api/comments?select=*,widget(*)'));
        $this->assertStringNotContainsString(self::FOREIGN, $this->body(self::OWNER, '/api/comments/1?select=*,widget(*)'));
    }

    /** @test */
    public function a_has_many_include_does_not_embed_a_foreign_row(): void
    {
        $body = $this->body(self::OWNER, '/api/notes?select=*,widgets(*)');

        $this->assertStringContainsString('"name":"MINE"', $body);
        $this->assertStringNotContainsString(self::FOREIGN, $body);
    }

    /** @test */
    public function a_belongs_to_many_include_does_not_embed_a_foreign_row(): void
    {
        // belongsToMany always takes the batched path.
        $body = $this->body(self::OWNER, '/api/notes?select=*,linked(*)');

        $this->assertStringContainsString('"name":"MINE"', $body);
        $this->assertStringNotContainsString(self::FOREIGN, $body);
    }

    /** @test */
    public function a_nested_include_on_the_batched_path_does_not_embed_a_foreign_row(): void
    {
        // Children disable the subquery optimisation for widgets.
        $body = $this->body(self::OWNER, '/api/notes?select=*,widgets(*,comments(*))');

        $this->assertStringContainsString('"name":"MINE"', $body);
        $this->assertStringNotContainsString(self::FOREIGN, $body);
    }

    /** @test */
    public function a_relationship_filter_is_not_an_existence_oracle_for_foreign_rows(): void
    {
        $body = $this->body(self::OWNER, '/api/comments?widget.name=eq.THEIRS');

        $this->assertStringNotContainsString('ON-THEIRS', $body);
    }

    /** @test */
    public function an_unrestricted_user_still_sees_every_related_row(): void
    {
        $this->assertStringContainsString(self::FOREIGN, $this->body(self::ADMIN, '/api/comments?select=*,widget(*)'));
        $this->assertStringContainsString(self::FOREIGN, $this->body(self::ADMIN, '/api/notes?select=*,linked(*)'));
    }

    /** @test */
    public function a_cached_response_embedding_a_restricted_table_is_not_shared_across_users(): void
    {
        Config::set('record.cache.enabled', true);
        $this->configureTables(cacheComments: true);
        Cache::flush();

        // comments is unrestricted; the embedded widget is restricted.
        $this->assertStringContainsString(self::FOREIGN, $this->body(self::ADMIN, '/api/comments?select=*,widget(*)'));
        $this->assertStringNotContainsString(self::FOREIGN, $this->body(self::OWNER, '/api/comments?select=*,widget(*)'));

        Cache::flush();
        $this->assertStringNotContainsString(self::FOREIGN, $this->body(self::OWNER, '/api/comments?select=*,widget(*)'));
        $this->assertStringContainsString(self::FOREIGN, $this->body(self::ADMIN, '/api/comments?select=*,widget(*)'));
    }

    /** @test */
    public function a_cached_response_is_not_shared_when_the_config_key_differs_from_the_table_name(): void
    {
        Config::set('record.cache.enabled', true);
        $this->configureTables(cacheComments: true, commentsKey: 'remarks');
        Cache::flush();

        $this->assertStringContainsString(self::FOREIGN, $this->body(self::ADMIN, '/api/remarks?select=*,widget(*)'));
        $this->assertStringNotContainsString(self::FOREIGN, $this->body(self::OWNER, '/api/remarks?select=*,widget(*)'));
    }
}
