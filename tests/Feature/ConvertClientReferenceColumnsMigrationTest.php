<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Tests\TestCase;

/**
 * The ALTER migration that repairs installs which migrated before the four
 * client-reference columns became strings.
 *
 * A fresh install never exercises it (the columns are already strings), so
 * these tests rebuild the pre-fix shape by hand and run the migration against
 * it, which is the only way the conversion gets any coverage at all.
 */
class ConvertClientReferenceColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('audit.enabled', true);
    }

    /** @test */
    public function it_converts_integer_client_reference_columns_to_strings(): void
    {
        $this->rebuildPreFixSchema();

        $this->assertColumnType('sp_model_has_roles', 'model_id', 'integer');
        $this->assertColumnType('sp_model_permissions', 'model_id', 'integer');
        $this->assertColumnType('sp_audit_logs', 'entity_id', 'integer');
        $this->assertColumnType('sp_audit_logs', 'user_id', 'integer');

        $this->migration()->up();

        $this->assertColumnType('sp_model_has_roles', 'model_id', 'varchar');
        $this->assertColumnType('sp_model_permissions', 'model_id', 'varchar');
        $this->assertColumnType('sp_audit_logs', 'entity_id', 'varchar');
        $this->assertColumnType('sp_audit_logs', 'user_id', 'varchar');
    }

    /** @test */
    public function it_preserves_nullability(): void
    {
        $this->rebuildPreFixSchema();

        $this->migration()->up();

        // model_id is required; the audit columns are not. change() replaces
        // the whole column definition, so a migration that forgot to restate
        // nullability would flip both of these.
        $this->assertFalse($this->column('sp_model_has_roles', 'model_id')['nullable']);
        $this->assertTrue($this->column('sp_audit_logs', 'entity_id')['nullable']);
        $this->assertTrue($this->column('sp_audit_logs', 'user_id')['nullable']);
    }

    /** @test */
    public function a_uuid_key_fits_after_the_conversion(): void
    {
        $this->rebuildPreFixSchema();
        $this->migration()->up();

        $uuid = (string) Str::uuid();

        DB::table('sp_model_has_roles')->insert([
            'model_type' => 'App\\Models\\User',
            'model_id' => $uuid,
            'role_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($uuid, DB::table('sp_model_has_roles')->value('model_id'));
    }

    /** @test */
    public function it_is_a_no_op_on_a_fresh_install(): void
    {
        // No rebuild: the tables are exactly what the create migrations just
        // built, which is the shape this migration must leave alone.
        $before = collect(Schema::getColumns('sp_model_has_roles'))->keyBy('name');

        $this->migration()->up();

        $after = collect(Schema::getColumns('sp_model_has_roles'))->keyBy('name');

        $this->assertEquals($before->toArray(), $after->toArray());
        $this->assertSame('varchar', $after['model_id']['type']);
    }

    /** @test */
    public function it_is_idempotent(): void
    {
        $this->rebuildPreFixSchema();

        $this->migration()->up();
        $first = collect(Schema::getColumns('sp_audit_logs'))->keyBy('name')->toArray();

        $this->migration()->up();
        $second = collect(Schema::getColumns('sp_audit_logs'))->keyBy('name')->toArray();

        $this->assertEquals($first, $second);
    }

    /** @test */
    public function it_skips_a_missing_table(): void
    {
        $this->rebuildPreFixSchema();

        // audit.enabled = false gates the audit table off entirely, so the
        // migration has to tolerate its absence rather than assume it exists.
        Schema::dropIfExists('sp_audit_logs');

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('sp_audit_logs'));
        $this->assertColumnType('sp_model_has_roles', 'model_id', 'varchar');
    }

    /** @test */
    public function down_is_a_documented_no_op(): void
    {
        $this->rebuildPreFixSchema();
        $this->migration()->up();

        $this->migration()->down();

        // Reversing is lossy — a uuid cannot go back into a bigint — so down()
        // must leave the wider column in place rather than destroy data.
        $this->assertColumnType('sp_model_has_roles', 'model_id', 'varchar');
        $this->assertColumnType('sp_audit_logs', 'entity_id', 'varchar');
    }

    private function migration(): Migration
    {
        return require __DIR__ . '/../../database/migrations/2026_08_05_000000_convert_client_reference_columns_to_string.php';
    }

    /**
     * Rebuild the three tables the way they looked before the columns were
     * fixed: model_id / entity_id / user_id as unsigned big integers.
     */
    private function rebuildPreFixSchema(): void
    {
        Schema::dropIfExists('sp_model_has_roles');
        Schema::dropIfExists('sp_model_permissions');
        Schema::dropIfExists('sp_audit_logs');

        Schema::create('sp_model_has_roles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->unique(['model_type', 'model_id', 'role_id'], 'sp_model_has_roles_unique');
        });

        Schema::create('sp_model_permissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->unique(['model_type', 'model_id', 'permission_id'], 'sp_model_permissions_unique');
        });

        Schema::create('sp_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $table, string $column): array
    {
        $columns = collect(Schema::getColumns($table))->keyBy('name');

        $this->assertTrue($columns->has($column), sprintf('%s should have a %s column', $table, $column));

        return $columns[$column];
    }

    private function assertColumnType(string $table, string $column, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->column($table, $column)['type'],
            sprintf('%s.%s should be %s', $table, $column, $expected)
        );
    }
}
