<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Relationship subqueries appended the tenant filter by interpolating the
 * value into SQL. For a parent that is not tenant-scoped the value comes from
 * the X-Tenant-ID header, so `1 OR 1=1` returned every tenant's related rows —
 * and the header was arbitrary SQL. belongsTo cast the value (int), which
 * blocked that but turned every UUID tenant id into 0.
 *
 * @internal
 */
class RelationshipTenantBindingTest extends TestCase
{
    use RefreshDatabase;

    private const INJECTION = '1 OR 1=1';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.enable_tenant_id', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('tenant_id');
            $t->timestamps();
        });
        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('body');
            $t->unsignedBigInteger('tag_id')->nullable();
            $t->timestamps();
        });
        Schema::create('widgets', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('note_id');
            $t->string('tenant_id');
            $t->timestamps();
        });
        Schema::create('note_widget', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->unsignedBigInteger('widget_id');
        });

        $now = now();
        DB::table('tags')->insert([
            ['id' => 1, 'name' => 'T1-TAG', 'tenant_id' => '1', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'T2-TAG', 'tenant_id' => '2', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'UUID-TAG', 'tenant_id' => 'a1b2c3d4-uuid-tenant', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('notes')->insert([
            ['id' => 1, 'body' => 'N1', 'tag_id' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'body' => 'N2', 'tag_id' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'T1-CHILD', 'note_id' => 1, 'tenant_id' => '1', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'T2-CHILD', 'note_id' => 1, 'tenant_id' => '2', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('note_widget')->insert([
            ['note_id' => 1, 'widget_id' => 1],
            ['note_id' => 1, 'widget_id' => 2],
        ]);

        $base = [
            'id' => ['type' => 'integer', 'nullable' => false],
            'created_at' => ['type' => 'datetime', 'nullable' => true],
            'updated_at' => ['type' => 'datetime', 'nullable' => true],
        ];

        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                hasTenantId: false,
                public: new RecordTablePublic(read: true, write: true),
                columns: $base + ['body' => ['type' => 'string', 'nullable' => false], 'tag_id' => ['type' => 'bigInteger', 'nullable' => true]],
                relationships: [
                    'widgets' => new RecordHasManyType(table: 'widgets', foreignKey: 'note_id', type: RecordRelationshipsEnum::HAS_MANY, localKey: 'id'),
                    'tag' => new RecordBelongsToType(table: 'tags', type: RecordRelationshipsEnum::BELONGS_TO, foreignKey: 'tag_id', ownerKey: 'id'),
                    'linked' => new RecordMetaBelongsToManyType(related: 'widgets', table: 'note_widget', foreignPivotKey: 'note_id', relatedPivotKey: 'widget_id'),
                ],
            ),
            'widgets' => new RecordTableType(
                table: 'widgets',
                hasTenantId: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: $base + ['name' => ['type' => 'string', 'nullable' => false], 'note_id' => ['type' => 'bigInteger', 'nullable' => false], 'tenant_id' => ['type' => 'string', 'nullable' => false]],
            ),
            'tags' => new RecordTableType(
                table: 'tags',
                hasTenantId: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: $base + ['name' => ['type' => 'string', 'nullable' => false], 'tenant_id' => ['type' => 'string', 'nullable' => false]],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    private function body(string $uri, string $tenant): string
    {
        return (string) $this->getJson($uri, ['X-Tenant-ID' => $tenant])->getContent();
    }

    /** @test */
    public function a_tenant_header_carrying_sql_matches_nothing_extra_on_a_has_many_include(): void
    {
        $this->assertStringNotContainsString('T2-CHILD', $this->body('/api/notes?select=*,widgets(*)', self::INJECTION));
    }

    /** @test */
    public function a_tenant_header_carrying_sql_matches_nothing_extra_on_a_belongs_to_many_include(): void
    {
        $this->assertStringNotContainsString('T2-CHILD', $this->body('/api/notes?select=*,linked(*)', self::INJECTION));
    }

    /** @test */
    public function a_legitimate_tenant_header_still_scopes_the_include(): void
    {
        $body = $this->body('/api/notes?select=*,widgets(*)', '1');

        $this->assertStringContainsString('T1-CHILD', $body);
        $this->assertStringNotContainsString('T2-CHILD', $body);
    }

    /** @test */
    public function a_tenant_header_carrying_sql_matches_nothing_extra_on_a_belongs_to_include(): void
    {
        // Regression guard: the (int) cast used to block this; binding must too.
        $this->assertStringNotContainsString('T2-TAG', $this->body('/api/notes?select=*,tag(*)', self::INJECTION));
    }

    /** @test */
    public function a_uuid_tenant_id_scopes_a_belongs_to_include(): void
    {
        // (int) 'a1b2c3d4-uuid-tenant' was 0, so note 2's tag never matched.
        $this->assertStringContainsString('UUID-TAG', $this->body('/api/notes/2?select=*,tag(*)', 'a1b2c3d4-uuid-tenant'));
    }
}
