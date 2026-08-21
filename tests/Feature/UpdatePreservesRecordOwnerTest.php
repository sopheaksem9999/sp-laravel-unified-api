<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * `created_by_id` / `created_by` are create-time audit stamps: they record who
 * inserted the row and must survive later writes. Rewriting them on PUT/PATCH
 * silently reassigns record ownership — an admin editing or approving a
 * customer's row would claim it, which in turn breaks `viewOwn:*` scoping for
 * every table whose owner column resolves to an audit stamp.
 *
 * `last_updated_by_id` / `last_updated_by` / `updated_by` are the columns that
 * are *meant* to move on update.
 *
 * @internal
 */
class UpdatePreservesRecordOwnerTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ID = 1;

    private const CUSTOMER_ID = 7;

    private Authenticatable $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('video_purchases', function (Blueprint $table): void {
            $table->id();
            $table->string('ref')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('last_updated_by_id')->nullable();
            $table->timestamps();
        });

        Config::set('record.tables', [
            'video_purchases' => new RecordTableType(
                table: 'video_purchases',
                pmsName: 'video_purchase',
                hasTenantId: false,
                canUpsert: true,
                ownerColumn: 'user_id',
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'ref' => ['type' => 'string', 'nullable' => false],
                    'user_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'is_approved' => ['type' => 'boolean', 'nullable' => true],
                    'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'last_updated_by_id' => ['type' => 'bigInteger', 'nullable' => true],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);

        // These tests exercise userstamp writes, not authorization: bypass the
        // per-table permission gate that `pmsName` activates.
        Config::set('permissions.super_admin_callback', fn($user): bool => true);

        SchemaRegistryUtils::refresh();

        $this->admin = (new class extends Authenticatable {
            protected $table = 'users';

            public $timestamps = false;

            protected $fillable = ['id', 'name'];
        });
        $this->admin->forceFill(['id' => self::ADMIN_ID, 'name' => 'Admin']);
        $this->admin->save();
    }

    private function seedCustomerPurchase(string $ref = 'PUR-1'): int
    {
        return (int) DB::table('video_purchases')->insertGetId([
            'ref' => $ref,
            'user_id' => self::CUSTOMER_ID,
            'is_approved' => false,
            'created_by_id' => self::CUSTOMER_ID,
            'last_updated_by_id' => self::CUSTOMER_ID,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /** @test */
    public function put_does_not_overwrite_created_by_id(): void
    {
        $id = $this->seedCustomerPurchase();

        $this->actingAs($this->admin, 'api')
            ->putJson('/api/video_purchases/' . $id, ['is_approved' => true])
            ->assertStatus(200);

        $row = DB::table('video_purchases')->find($id);
        $this->assertSame(self::CUSTOMER_ID, (int) $row->created_by_id, 'created_by_id must keep the original author');
        $this->assertSame(self::ADMIN_ID, (int) $row->last_updated_by_id, 'last_updated_by_id must move to the editor');
        $this->assertSame(1, (int) $row->is_approved);
    }

    /** @test */
    public function patch_does_not_overwrite_created_by_id(): void
    {
        $id = $this->seedCustomerPurchase('PUR-2');

        $this->actingAs($this->admin, 'api')
            ->patchJson('/api/video_purchases/' . $id, ['is_approved' => true])
            ->assertStatus(200);

        $row = DB::table('video_purchases')->find($id);
        $this->assertSame(self::CUSTOMER_ID, (int) $row->created_by_id);
        $this->assertSame(self::ADMIN_ID, (int) $row->last_updated_by_id);
    }

    /** @test */
    public function update_does_not_overwrite_the_resolved_owner_column(): void
    {
        $id = $this->seedCustomerPurchase('PUR-3');

        $this->actingAs($this->admin, 'api')
            ->patchJson('/api/video_purchases/' . $id, ['is_approved' => true])
            ->assertStatus(200);

        $row = DB::table('video_purchases')->find($id);
        $this->assertSame(self::CUSTOMER_ID, (int) $row->user_id, 'the owner column must not drift on update');
    }

    /** @test */
    public function upsert_still_stamps_created_by_id_on_the_insert_branch(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/video_purchases/upsert?match_on=ref', [
                'ref' => 'PUR-NEW',
                'user_id' => self::CUSTOMER_ID,
                'is_approved' => true,
            ])->assertStatus(200);

        $row = DB::table('video_purchases')->where('ref', 'PUR-NEW')->first();
        $this->assertNotNull($row);
        $this->assertSame(self::ADMIN_ID, (int) $row->created_by_id, 'an upsert-inserted row must still be stamped');
        $this->assertSame(self::ADMIN_ID, (int) $row->last_updated_by_id);
    }

    /** @test */
    public function upsert_does_not_overwrite_created_by_id_on_the_update_branch(): void
    {
        $this->seedCustomerPurchase('PUR-4');

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/video_purchases/upsert?match_on=ref', [
                'ref' => 'PUR-4',
                'is_approved' => true,
            ])->assertStatus(200);

        $row = DB::table('video_purchases')->where('ref', 'PUR-4')->first();
        $this->assertSame(self::CUSTOMER_ID, (int) $row->created_by_id);
        $this->assertSame(self::ADMIN_ID, (int) $row->last_updated_by_id);
    }
}
