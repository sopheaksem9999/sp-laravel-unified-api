<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The package's own uuid-keyed tables must be creatable through the generic
 * CRUD path without a client-supplied id.
 *
 * Their migrations declare uuid('id')->primary(), which has no database
 * default and does not auto-increment, so createRecord has to generate the
 * key. It decides that from the DECLARED column type in config, and these
 * tables used to declare 'string' — so no key was generated and the insert
 * failed the not-null constraint on every driver.
 *
 * The declaration is the thing under test here, not round-trip behaviour:
 * SQLite's type affinity would let a wrong declaration pass a plain insert
 * test, so the assertions read the registered config directly as well.
 */
class PackageTableUuidPrimaryKeyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Package tables whose migration uses uuid('id')->primary().
     */
    private const UUID_KEYED_TABLES = [
        'sp_webhook_endpoints',
        'sp_webhook_subscriptions',
        'sp_webhook_deliveries',
        'sp_attachments',
        'sp_attachment_folders',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The base TestCase blanks these so unrelated tests get a clean route
        // regex. Re-require the shipped files: this is the config a real app
        // boots with, and it is exactly what is being asserted.
        $attachments = require __DIR__ . '/../../config/attachments.php';
        $webhooks = require __DIR__ . '/../../config/webhooks.php';

        Config::set('attachments.enabled', true);
        Config::set('attachments.tables', $attachments['tables']);
        Config::set('webhooks.enabled', true);
        Config::set('webhooks.tables', $webhooks['tables']);

        SchemaRegistryUtils::refresh();
    }

    /**
     * @test
     *
     * @dataProvider uuidKeyedTables
     */
    public function a_uuid_keyed_package_table_declares_a_uuid_primary_key(string $table): void
    {
        $config = SchemaRegistryUtils::getTable($table);

        $this->assertInstanceOf(RecordTableType::class, $config, $table . ' should be registered');

        $pk = $config->primaryKey ?? 'id';

        $this->assertTrue(
            SchemaRegistryUtils::isUuidColumnType($config->columns[$pk] ?? null),
            sprintf(
                "%s.%s is uuid('id')->primary() in its migration, so it must declare a uuid type; got %s",
                $table,
                $pk,
                var_export($config->columns[$pk]['type'] ?? null, true)
            )
        );
    }

    /**
     * @test
     *
     * @dataProvider uuidKeyedTables
     */
    public function a_uuid_keyed_package_table_can_be_created_without_a_client_supplied_id(string $table): void
    {
        $result = app(RecordService::class)->createRecord($table, $this->payloadFor($table), null);

        $this->assertTrue(
            Str::isUuid((string) $result['id']),
            sprintf('createRecord must generate a uuid for %s, got: %s', $table, var_export($result['id'], true))
        );
        $this->assertSame(1, DB::table($table)->where('id', $result['id'])->count());
    }

    /** @test */
    public function sp_attachment_links_keeps_its_auto_incrementing_key(): void
    {
        // Counterweight: its migration uses $table->id(), so a uuid must never
        // be generated for it. Over-applying the uuid declaration would break
        // the sequence on every driver that enforces the column type.
        $config = SchemaRegistryUtils::getTable('sp_attachment_links');

        $this->assertFalse(SchemaRegistryUtils::isUuidColumnType($config->columns['id'] ?? null));

        $result = app(RecordService::class)->createRecord('sp_attachment_links', [
            'attachment_id' => (string) Str::uuid(),
            'record_id' => '1',
            'record_type' => 'invoices',
        ], null);

        $this->assertSame(1, (int) $result['id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uuidKeyedTables(): array
    {
        $cases = [];
        foreach (self::UUID_KEYED_TABLES as $table) {
            $cases[$table] = [$table];
        }

        return $cases;
    }

    /**
     * The minimum not-null, no-default payload each table needs.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(string $table): array
    {
        return match ($table) {
            'sp_webhook_endpoints' => [
                'name' => 'Orders',
                'url' => 'https://example.test/hooks/orders',
                'secret' => 's3cret',
                'is_active' => true,
            ],
            'sp_webhook_subscriptions' => [
                'endpoint_id' => (string) Str::uuid(),
                'table_name' => 'invoices',
                'event' => 'created',
            ],
            'sp_webhook_deliveries' => [
                'endpoint_id' => (string) Str::uuid(),
                'event' => 'created',
                'payload' => json_encode(['ok' => true]),
                'status' => 'pending',
            ],
            'sp_attachments' => [
                'disk' => 'local',
                'path' => 'attachments/a.txt',
                'filename' => 'a.txt',
                'mime_type' => 'text/plain',
                'size' => 12,
                'visibility' => 'private',
            ],
            'sp_attachment_folders' => [
                'name' => 'Invoices',
                'scope' => 'internal',
                'visibility' => 'private',
            ],
            default => [],
        };
    }
}
