<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Sopheak\Core\Enums\AuditLogEventEnum;
use Sopheak\Core\Services\AuditLogService;
use Sopheak\Core\Tests\TestCase;

/**
 * `title`, `subject` and `recap` are the human-readable columns of an audit row,
 * and each could land empty: `generateRecap()` returns '' for an UPDATE whose
 * diff comes back empty (the first update of a record has no earlier audit row
 * to diff against), and `getAuditSubject()` returns '' unless a configured
 * subject field is present. `createAuditLogEntry()` wrote those blanks straight
 * through, so rows showed up with nothing to read.
 *
 * None of the three may be stored empty now: each falls back to the best value
 * derivable from the event and entity.
 *
 * @internal
 */
class AuditNarrativeDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.enabled' => true, 'audit.queue_enabled' => false]);
    }

    private function lastRow(): object
    {
        $row = DB::table('sp_audit_logs')->latest('id')->first();
        $this->assertNotNull($row, 'expected an audit row to be written');

        return $row;
    }

    /** @test */
    public function an_update_with_no_detectable_diff_still_gets_a_recap(): void
    {
        // No prior audit row, so old_data is empty and the diff yields nothing —
        // the case that used to store recap = ''.
        AuditLogService::createAuditLogEntry([
            'entity_type' => 'settings',
            'entity_id' => 3,
            'event' => AuditLogEventEnum::UPDATED->value,
            'old_data' => [],
            'new_data' => ['name' => 'after'],
            'title' => '',
            'metadata' => [],
        ]);

        $row = $this->lastRow();
        $this->assertNotSame('', (string) $row->recap);
        $this->assertNotSame('', (string) $row->title);
        $this->assertNotSame('', (string) $row->subject);
    }

    /** @test */
    public function a_direct_call_without_any_narrative_fills_all_three(): void
    {
        AuditLogService::createAuditLogEntry([
            'entity_type' => 'settings',
            'entity_id' => 7,
            'event' => AuditLogEventEnum::CREATED->value,
            'old_data' => [],
            'new_data' => ['name' => 'fresh'],
            'metadata' => [],
        ]);

        $row = $this->lastRow();
        $this->assertNotSame('', (string) $row->title);
        $this->assertNotSame('', (string) $row->subject);
        $this->assertNotSame('', (string) $row->recap);
        $this->assertStringContainsString('Settings', (string) $row->title);
    }

    /** @test */
    public function explicitly_provided_values_are_never_overwritten(): void
    {
        AuditLogService::createAuditLogEntry([
            'entity_type' => 'settings',
            'entity_id' => 9,
            'event' => AuditLogEventEnum::UPDATED->value,
            'old_data' => ['name' => 'a'],
            'new_data' => ['name' => 'b'],
            'title' => 'Custom title',
            'subject' => 'Custom subject',
            'recap' => 'Custom recap',
            'metadata' => [],
        ]);

        $row = $this->lastRow();
        $this->assertSame('Custom title', $row->title);
        $this->assertSame('Custom subject', $row->subject);
        $this->assertSame('Custom recap', $row->recap);
    }

    /** @test */
    public function a_real_diff_still_produces_a_generated_recap_not_the_fallback(): void
    {
        AuditLogService::createAuditLogEntry([
            'entity_type' => 'settings',
            'entity_id' => 11,
            'event' => AuditLogEventEnum::UPDATED->value,
            'old_data' => ['name' => 'before'],
            'new_data' => ['name' => 'after'],
            'metadata' => [],
        ]);

        $row = $this->lastRow();
        // The generated recap names the changed field, so it is strictly richer
        // than the bare-title fallback — that difference is what pins it.
        $this->assertSame('Updated Settings: Name', (string) $row->recap);
        $this->assertNotSame((string) $row->title, (string) $row->recap);
    }

    /** @test */
    public function a_missing_entity_id_does_not_raise_an_undefined_key_error(): void
    {
        AuditLogService::createAuditLogEntry([
            'entity_type' => 'settings',
            'event' => AuditLogEventEnum::CREATED->value,
            'old_data' => [],
            'new_data' => ['name' => 'no id'],
            'metadata' => [],
        ]);

        $this->assertNull($this->lastRow()->entity_id);
    }
}
