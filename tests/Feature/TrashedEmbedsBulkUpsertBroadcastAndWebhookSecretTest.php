<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Events\RecordMutated;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * - with_trashed / only_trashed must reach the embed fast path's re-query
 * - bulk upsert broadcasts one batched `upserted` event
 * - the webhook endpoint signing secret is never returned
 *
 * @internal
 */
class TrashedEmbedsBulkUpsertBroadcastAndWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('te_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('te_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('ref');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('te_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->timestamps();
        });

        DB::table('te_customers')->insert(['id' => 1, 'name' => 'Acme']);
        DB::table('te_orders')->insert([
            ['id' => 1, 'ref' => 'live', 'customer_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'ref' => 'gone', 'customer_id' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
        ]);

        Config::set('record.cache.enabled', false);
        Config::set('record.tables', [
            'te_orders' => new RecordTableType(
                table: 'te_orders',
                pmsName: 'te_orders',
                hasTenantId: false,
                softDeletes: true,
                public: new RecordTablePublic(read: true, write: true),
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'te_customers', foreignKey: 'customer_id', ownerKey: 'id'),
                ],
            ),
            'te_customers' => new RecordTableType(
                table: 'te_customers',
                pmsName: 'te_customers',
                hasTenantId: false,
                softDeletes: false,
                public: new RecordTablePublic(read: true, write: true),
            ),
            'te_widgets' => new RecordTableType(
                table: 'te_widgets',
                pmsName: 'te_widgets',
                hasTenantId: false,
                softDeletes: false,
                canUpsert: true,
                public: new RecordTablePublic(read: true, write: true),
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'sku' => ['type' => 'string', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                    'created_at' => ['type' => 'datetime', 'nullable' => true],
                    'updated_at' => ['type' => 'datetime', 'nullable' => true],
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @return string[] */
    private function refs(string $query): array
    {
        return collect($this->getJson('/api/te_orders?' . $query)->assertOk()->json('data'))->pluck('ref')->sort()->values()->all();
    }

    public function test_trashed_flags_apply_to_lists_with_embeds(): void
    {
        $embed = '&select=' . rawurlencode('*,customer(*)') . '&per_page=15';

        $this->assertSame(['gone'], $this->refs('only_trashed=true' . $embed));
        $this->assertSame(['gone', 'live'], $this->refs('with_trashed=true' . $embed));
        $this->assertSame(['live'], $this->refs('x=1' . $embed));

        // Same rows as without the embed.
        $this->assertSame($this->refs('only_trashed=true&per_page=15'), $this->refs('only_trashed=true' . $embed));
        $this->assertSame($this->refs('with_trashed=true&per_page=15'), $this->refs('with_trashed=true' . $embed));
    }

    public function test_trashed_flags_apply_to_show_with_embeds(): void
    {
        $embed = 'select=' . rawurlencode('*,customer(*)');

        $this->getJson('/api/te_orders/2?' . $embed)->assertNotFound();

        $shown = $this->getJson('/api/te_orders/2?with_trashed=true&' . $embed)->assertOk();
        $this->assertSame('gone', $shown->json('data.ref'));
        $this->assertSame('Acme', $shown->json('data.customer.name'));
    }

    public function test_bulk_upsert_broadcasts_one_batched_upserted_event(): void
    {
        Config::set('record.broadcast_events', true);
        Event::fake([RecordMutated::class]);

        $this->postJson('/api/te_widgets/bulk/upsert?match_on=sku', [
            ['sku' => 'a', 'name' => 'A'],
            ['sku' => 'b', 'name' => 'B'],
            ['sku' => 'c', 'name' => 'C'],
        ])->assertOk();

        $this->assertSame(3, DB::table('te_widgets')->count());
        Event::assertDispatchedTimes(RecordMutated::class, 1);
        Event::assertDispatched(RecordMutated::class, fn(RecordMutated $event): bool => 'te_widgets' === $event->table
            && 'upserted' === $event->action
            && 'te_widgets.upserted' === $event->broadcastAs()
            && ['sku'] === $event->record['match_on']
            && [['sku' => 'a'], ['sku' => 'b'], ['sku' => 'c']] === $event->record['keys']);
    }

    public function test_broadcast_action_names_cover_the_documented_set(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/guide/api/api-realtime-openapi-attribute-config.md');

        foreach (['created', 'updated', 'upserted', 'deleted', 'restored'] as $action) {
            $this->assertStringContainsString('`' . $action . '`', $source);
        }

        $this->assertStringNotContainsString('`force_deleted`', $source);
    }

    public function test_webhook_endpoint_secret_is_hidden(): void
    {
        $webhooks = require dirname(__DIR__, 2) . '/config/sp-webhooks.php';
        $table = $webhooks['tables']['sp_webhook_endpoints'];
        $this->assertContains('secret', $table->columnHiddens);

        Config::set('record.tables', ['sp_webhook_endpoints' => $table]);
        SchemaRegistryUtils::refresh();
        SchemaRegistryUtils::get();

        $cleaned = RecordApiResponseService::removeHiddenFields(['id' => 1, 'name' => 'n', 'secret' => 's3cret'], 'sp_webhook_endpoints');
        $this->assertArrayNotHasKey('secret', (array) $cleaned);
        $this->assertSame('n', ((array) $cleaned)['name']);
    }
}
