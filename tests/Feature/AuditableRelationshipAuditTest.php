<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Support\SchemaRegistry;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Traits\Auditable;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;

class AuditableRelationshipAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('audit.enabled', true);

        Schema::create('parents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->string('title');
            $table->timestamps();
        });

        Config::set('record.tables', [
            'parents' => new RecordTableType(
                pms_name: 'parents',
                table: 'parents',
                has_tenant_id: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'children' => new RecordHasManyType(
                        table: 'children',
                        foreignKey: 'parent_id',
                    ),
                ],
            ),
        ]);
        SchemaRegistry::refresh();
    }

    public function test_update_audit_stores_old_new_and_relationships_from_record_config(): void
    {
        $parent = ParentAuditModel::create(['name' => 'Parent 1']);
        ChildAuditModel::create(['parent_id' => $parent->id, 'title' => 'Child 1']);

        $parent->update(['name' => 'Parent 2']);

        $log = DB::table('audit_logs')
            ->where('entity_name', 'parents')
            ->where('entity_id', $parent->id)
            ->where('event', 'updated')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);

        $old = json_decode((string) $log->old_data, true);
        $new = json_decode((string) $log->new_data, true);

        $this->assertIsArray($old);
        $this->assertIsArray($new);
        $this->assertEquals('Parent 1', $old['name'] ?? null);
        $this->assertEquals('Parent 2', $new['name'] ?? null);

        $this->assertIsArray($old['children'] ?? null);
        $this->assertCount(1, $old['children']);
        $this->assertEquals('Child 1', $old['children'][0]['title'] ?? null);

        $this->assertArrayNotHasKey('created_at', $old);
        $this->assertArrayNotHasKey('updated_at', $old);
        $this->assertArrayNotHasKey('created_at', $new);
        $this->assertArrayNotHasKey('updated_at', $new);
    }
}

class ParentAuditModel extends Model
{
    use Auditable;

    protected $table = 'parents';

    protected $guarded = [];

    public function children()
    {
        return $this->hasMany(ChildAuditModel::class, 'parent_id');
    }
}

class ChildAuditModel extends Model
{
    protected $table = 'children';

    protected $guarded = [];
}
