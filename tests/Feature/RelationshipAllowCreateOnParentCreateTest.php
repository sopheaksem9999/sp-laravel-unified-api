<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * `allowCreate: false` on a hasMany/morphMany relationship used to only be enforced
 * when the PARENT record was being updated — RelationshipResolverUtils::processRelatedData()
 * had `elseif ('create' === $operation || $allowCreate)`, so nested children were always
 * inserted while the parent itself was being created, regardless of allowCreate. Fixed by
 * dropping the `'create' === $operation` bypass so allowCreate governs nested creation the
 * same way regardless of what operation the parent is going through.
 *
 * @internal
 */
class RelationshipAllowCreateOnParentCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id');
            $table->string('name');
            $table->timestamps();
        });

        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoices',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'items' => new RecordHasManyType(
                        table: 'invoice_items',
                        foreignKey: 'invoice_id',
                        allowCreate: false,
                    ),
                ],
            ),
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                pmsName: 'invoice_items',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [],
            ),
        ]);

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function allow_create_false_blocks_nested_creation_even_while_creating_the_parent(): void
    {
        $response = $this->postJson('/api/invoices', [
            'title' => 'Invoice 1',
            'items' => [
                ['name' => 'Should not be inserted'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "Cannot create item in relationship 'items' for table 'invoices': allowCreate is disabled for this relationship."
        );

        // The whole write is rejected — including the parent — not just the nested item.
        $this->assertDatabaseMissing('invoices', ['title' => 'Invoice 1']);
        $this->assertDatabaseMissing('invoice_items', ['name' => 'Should not be inserted']);
    }
}
