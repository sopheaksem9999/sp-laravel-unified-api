<?php

declare(strict_types=1);

namespace Sopheak\Core\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event fired after every successful create/update/delete mutation.
 *
 * Channel:    private-tenant.{tenantId}
 * Event name: {table}.{action}   (e.g. "invoices.created")
 * Payload:    { table, action, record, tenant_id, timestamp }
 *
 * Broadcasting is opt-in via:
 *   record.broadcast_events = true          (global toggle)
 *   record.broadcast_tables = ['invoices']  (empty = all tables)
 *   RecordTableType::$disableBroadcast      (per-table override)
 *
 * Queue-based broadcasting is enabled automatically when the application
 * queue driver is not "sync" — matching the audit.queue_enabled pattern.
 */
class RecordMutated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param string     $table     The table / resource name (e.g. "invoices")
     * @param string     $action    The mutation type: created | updated | deleted | restored
     * @param array      $record    The affected record data
     * @param mixed      $tenantId  The tenant identifier (may be null for non-tenant tables)
     * @param string     $timestamp ISO-8601 timestamp of when the mutation occurred
     */
    public function __construct(
        public readonly string $table,
        public readonly string $action,
        public readonly array $record,
        public readonly mixed $tenantId,
        public readonly string $timestamp,
    ) {}

    /**
     * Broadcast on a private per-tenant channel.
     *
     * Falls back to a generic channel when tenantId is absent.
     */
    public function broadcastOn(): Channel
    {
        $channelId = empty($this->tenantId) ? 'global' : (string) $this->tenantId;

        return new PrivateChannel('tenant.' . $channelId);
    }

    /**
     * Event name format: {table}.{action}  (e.g. "invoices.created")
     */
    public function broadcastAs(): string
    {
        return $this->table . '.' . $this->action;
    }

    /**
     * Payload sent to connected clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'table'     => $this->table,
            'action'    => $this->action,
            'record'    => $this->record,
            'tenant_id' => $this->tenantId,
            'timestamp' => $this->timestamp,
        ];
    }
}
