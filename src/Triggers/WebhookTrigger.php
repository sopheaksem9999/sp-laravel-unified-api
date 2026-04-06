<?php

namespace Sopheak\Core\Triggers;

use Illuminate\Support\Str;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Jobs\DispatchWebhookJob;

class WebhookTrigger
{
    #[RecordTrigger('afterCreate')]
    public function handleAfterCreate(array $data, string $table, ?string $tenantId): array
    {
        $this->dispatchWebhooks($table, 'created', $data, $tenantId);
        return $data;
    }

    #[RecordTrigger('afterUpdate')]
    public function handleAfterUpdate(array $data, string $table, ?string $tenantId): array
    {
        $this->dispatchWebhooks($table, 'updated', $data, $tenantId);
        return $data;
    }

    #[RecordTrigger('afterDelete')]
    public function handleAfterDelete(string $id, array $oldData, string $table, ?string $tenantId): void
    {
        $this->dispatchWebhooks($table, 'deleted', $oldData, $tenantId);
    }

    private function dispatchWebhooks(string $table, string $action, array $payload, ?string $tenantId): void
    {
        if (!config('webhooks.enabled', false)) {
            return;
        }

        // Prevent infinite loops if webhooks table itself is modified
        if (in_array($table, ['sp_webhook_endpoints', 'sp_webhook_subscriptions', 'sp_webhook_deliveries'])) {
            return;
        }

        $event = sprintf('%s.%s', $table, $action);

        // Find subscriptions for this table/event
        $subscriptions = RecordService::executeGetByFilter('sp_webhook_subscriptions', [
            'table_name' => 'in.' . $table . ',*',
            'event' => 'in.' . $action . ',*',
        ], $tenantId);

        if (empty($subscriptions['data'])) {
            return;
        }

        $endpointIds = array_unique(array_column($subscriptions['data'], 'endpoint_id'));

        // Get active endpoints
        $endpoints = RecordService::executeGetByFilter('sp_webhook_endpoints', [
            'id' => 'in.' . implode(',', $endpointIds),
            'is_active' => 'eq.true',
        ], $tenantId);

        if (empty($endpoints['data'])) {
            return;
        }

        $queueName = config('webhooks.queue_name', 'default');

        foreach ($endpoints['data'] as $endpoint) {
            // Create delivery record
            $deliveryPayload = [
                'id' => Str::uuid()->toString(),
                'endpoint_id' => $endpoint['id'],
                'event' => $event,
                'payload' => json_encode($payload),
                'status' => 'pending',
            ];

            $delivery = RecordService::executeCreate('sp_webhook_deliveries', $deliveryPayload, [], $tenantId);

            // Dispatch Job
            DispatchWebhookJob::dispatch(
                $delivery['id'],
                $endpoint['id'],
                $endpoint['url'],
                $endpoint['secret'],
                $event,
                $payload,
                $tenantId
            )->onQueue($queueName);
        }
    }
}
