<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Covers nested update/delete for plain hasMany relationships, which share
 * processRelatedData's FK-based write path with morphMany. This path had no
 * dedicated test coverage before — added alongside the morphMany ownership-
 * scoping fix so both relationship kinds are protected the same way.
 *
 * @internal
 */
class RelationshipHasManyNestedWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
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
                        localKey: 'id',
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

    public function test_nested_update_can_modify_an_item_belonging_to_the_same_invoice(): void
    {
        $invoiceId = (string) Str::uuid();
        $itemId = (string) Str::uuid();

        DB::table('invoices')->insert(['id' => $invoiceId, 'title' => 'Invoice 1']);
        DB::table('invoice_items')->insert(['id' => $itemId, 'invoice_id' => $invoiceId, 'name' => 'original']);

        $payload = [
            'items' => [
                ['id' => $itemId, 'name' => 'updated'],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('invoices', $payload, $invoiceId, null, 'update');

        $this->assertSame('updated', DB::table('invoice_items')->where('id', $itemId)->value('name'));
    }

    public function test_nested_delete_can_delete_an_item_belonging_to_the_same_invoice(): void
    {
        $invoiceId = (string) Str::uuid();
        $itemId = (string) Str::uuid();

        DB::table('invoices')->insert(['id' => $invoiceId, 'title' => 'Invoice 1']);
        DB::table('invoice_items')->insert(['id' => $itemId, 'invoice_id' => $invoiceId, 'name' => 'original']);

        $payload = [
            'items' => [
                ['id' => $itemId, '_delete' => true],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('invoices', $payload, $invoiceId, null, 'update');

        $this->assertDatabaseMissing('invoice_items', ['id' => $itemId]);
    }

    public function test_nested_update_cannot_modify_an_item_belonging_to_a_different_invoice(): void
    {
        $invoiceId = (string) Str::uuid();
        $otherInvoiceId = (string) Str::uuid();
        $foreignItemId = (string) Str::uuid();

        DB::table('invoices')->insert(['id' => $invoiceId, 'title' => 'Invoice 1']);
        DB::table('invoices')->insert(['id' => $otherInvoiceId, 'title' => 'Invoice 2']);
        DB::table('invoice_items')->insert(['id' => $foreignItemId, 'invoice_id' => $otherInvoiceId, 'name' => 'original']);

        // Attacker only has access to $invoiceId, but references an item that
        // actually belongs to a different invoice.
        $payload = [
            'items' => [
                ['id' => $foreignItemId, 'name' => 'hacked'],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('invoices', $payload, $invoiceId, null, 'update');

        $row = DB::table('invoice_items')->where('id', $foreignItemId)->first();
        $this->assertSame('original', $row->name);
        $this->assertSame($otherInvoiceId, $row->invoice_id);
    }

    public function test_nested_delete_cannot_delete_an_item_belonging_to_a_different_invoice(): void
    {
        $invoiceId = (string) Str::uuid();
        $otherInvoiceId = (string) Str::uuid();
        $foreignItemId = (string) Str::uuid();

        DB::table('invoices')->insert(['id' => $invoiceId, 'title' => 'Invoice 1']);
        DB::table('invoices')->insert(['id' => $otherInvoiceId, 'title' => 'Invoice 2']);
        DB::table('invoice_items')->insert(['id' => $foreignItemId, 'invoice_id' => $otherInvoiceId, 'name' => 'original']);

        $payload = [
            'items' => [
                ['id' => $foreignItemId, '_delete' => true],
            ],
        ];

        RelationshipResolverUtils::processRelatedData('invoices', $payload, $invoiceId, null, 'update');

        $this->assertDatabaseHas('invoice_items', ['id' => $foreignItemId]);
    }
}
