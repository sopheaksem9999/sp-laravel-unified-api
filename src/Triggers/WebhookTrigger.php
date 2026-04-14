<?php

namespace Sopheak\Core\Triggers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Jobs\DispatchWebhookJob;

class WebhookTrigger
{
    #[RecordTrigger('afterCreate')]
    public static function handleAfterCreate(Request $request, string $table, mixed $context): void
    {
        $tenantId = is_array($context) ? ($context['tenant_id'] ?? null) : null;
        $data = self::extractDataFromContext($context);
        self::dispatchWebhooks($table, 'created', $data, $tenantId);
    }

    #[RecordTrigger('afterUpdate')]
    public static function handleAfterUpdate(Request $request, string $table, mixed $context): void
    {
        $tenantId = is_array($context) ? ($context['tenant_id'] ?? null) : null;
        $data = self::extractDataFromContext($context);
        self::dispatchWebhooks($table, 'updated', $data, $tenantId);
    }

    #[RecordTrigger('afterDelete')]
    public static function handleAfterDelete(Request $request, string $table, mixed $context): void
    {
        $tenantId = is_array($context) ? ($context['tenant_id'] ?? null) : null;

        $oldData = is_array($context) ? ($context['old_data'] ?? $context['record'] ?? []) : [];
        if (is_object($oldData)) {
            $oldData = (array) $oldData;
        }

        $id = is_array($context) ? ($context['id'] ?? null) : $context;
        if ($id) {
            $oldData['id'] = $id;
        }

        self::dispatchWebhooks($table, 'deleted', $oldData, $tenantId);
    }

    private static function extractDataFromContext(mixed $context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $data = $context['data'] ?? $context['response'] ?? [];
        if ($data instanceof JsonResponse) {
            $decoded = json_decode($data->getContent(), true);
            return $decoded['data'] ?? $decoded ?? [];
        }

        if (is_object($data)) {
            return (array) $data;
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Dispatch matching webhooks.
     */
    private static function dispatchWebhooks(string $table, string $action, array $payload, ?string $tenantId): void
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
