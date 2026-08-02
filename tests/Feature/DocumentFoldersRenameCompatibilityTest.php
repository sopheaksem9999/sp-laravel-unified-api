<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Covers the sp_document_folders -> sp_attachment_folders rename: the physical
 * table is renamed, but the old config key stays registered (deprecated) so
 * existing clients hitting it keep working, backed by the same table as the
 * new canonical key.
 *
 * @internal
 */
class DocumentFoldersRenameCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sp_attachment_links');
        Schema::dropIfExists('sp_attachments');
        Schema::dropIfExists('sp_attachment_folders');
        Schema::dropIfExists('sp_document_folders');

        (require __DIR__ . '/../../database/migrations/2024_01_01_000000_create_sp_attachments_tables.php')->up();
        (require __DIR__ . '/../../database/migrations/2026_08_02_000000_rename_sp_document_folders_table.php')->up();

        // TestCase::getEnvironmentSetUp() resets attachments.tables to []; load the
        // real shipped config here so this test exercises the actual dual registration.
        Config::set('attachments.tables', (require __DIR__ . '/../../config/attachments.php')['tables']);

        SchemaRegistryUtils::refresh();
    }

    public function test_new_canonical_key_creates_and_reads_a_folder(): void
    {
        $id = (string) Str::uuid();

        RecordService::executeCreate('sp_attachment_folders', [
            'id' => $id,
            'name' => 'Reports',
            'scope' => 'internal',
            'visibility' => 'private',
        ]);

        $listed = RecordService::executeGetByFilter('sp_attachment_folders', ['id' => 'eq.' . $id]);

        $this->assertCount(1, $listed['data'] ?? []);
        $this->assertSame('Reports', data_get($listed, 'data.0.name'));
    }

    public function test_deprecated_old_key_still_creates_and_reads_a_folder(): void
    {
        $id = (string) Str::uuid();

        RecordService::executeCreate('sp_document_folders', [
            'id' => $id,
            'name' => 'Legacy Clients',
            'scope' => 'internal',
            'visibility' => 'private',
        ]);

        $listed = RecordService::executeGetByFilter('sp_document_folders', ['id' => 'eq.' . $id]);

        $this->assertCount(1, $listed['data'] ?? []);
        $this->assertSame('Legacy Clients', data_get($listed, 'data.0.name'));
    }

    public function test_write_via_new_key_is_immediately_visible_via_deprecated_key(): void
    {
        $id = (string) Str::uuid();

        RecordService::executeCreate('sp_attachment_folders', [
            'id' => $id,
            'name' => 'Cross Key A',
            'scope' => 'internal',
            'visibility' => 'private',
        ]);

        $viaOldKey = RecordService::executeGetByFilter('sp_document_folders', ['id' => 'eq.' . $id]);

        $this->assertCount(1, $viaOldKey['data'] ?? []);
        $this->assertSame('Cross Key A', data_get($viaOldKey, 'data.0.name'));
    }

    public function test_write_via_deprecated_key_is_immediately_visible_via_new_key(): void
    {
        $id = (string) Str::uuid();

        RecordService::executeCreate('sp_document_folders', [
            'id' => $id,
            'name' => 'Cross Key B',
            'scope' => 'internal',
            'visibility' => 'private',
        ]);

        $viaNewKey = RecordService::executeGetByFilter('sp_attachment_folders', ['id' => 'eq.' . $id]);

        $this->assertCount(1, $viaNewKey['data'] ?? []);
        $this->assertSame('Cross Key B', data_get($viaNewKey, 'data.0.name'));
    }
}
