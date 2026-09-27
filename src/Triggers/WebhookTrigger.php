<?php

declare(strict_types=1);

namespace Sopheak\Core\Triggers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Jobs\DispatchWebhookJob;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Handles webhook dispatching for record events.
 *
 * Extends RecordTriggerBase for easy customization.
 * Override any method in your app to customize webhook behavior.
 */
class WebhookTrigger extends RecordTriggerBase
{
    #[RecordTrigger('afterCreate')]
    public static function afterCreate(Request $request, string $table, array $context): void
    {
        $tenantId = $context['tenant_id'] ?? null;
        $data = self::extractDataFromContext($context);
        self::dispatchWebhooks($table, 'created', $data, $tenantId);
    }

    #[RecordTrigger('afterUpdate')]
    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        $tenantId = $context['tenant_id'] ?? null;
        $data = self::extractDataFromContext($context);
        self::dispatchWebhooks($table, 'updated', $data, $tenantId);
    }

    #[RecordTrigger('afterDelete')]
    public static function afterDelete(Request $request, string $table, array $context): void
    {
        $tenantId = $context['tenant_id'] ?? null;

        $oldData = $context['old_data'] ?? $context['record'] ?? [];
        if (is_object($oldData)) {
            $oldData = (array) $oldData;
        }

        $id = $context['id'] ?? null;
        if ($id) {
            $oldData['id'] = $id;
        }

        self::dispatchWebhooks($table, 'deleted', $oldData, $tenantId);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function extractDataFromContext(array $context): array
    {
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
    private static function dispatchWebhooks(string $table, string $action, array $payload, int|string|null $tenantId): void
    {
        if (!config('webhooks.enabled', false)) {
            return;
        }

        // Prevent infinite loops if webhooks table itself is modified
        if (in_array($table, ['sp_webhook_endpoints', 'sp_webhook_subscriptions', 'sp_webhook_deliveries'])) {
            return;
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        $payload = RecordService::stripHiddenColumns($payload, $tableSchema);

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
            $endpointId = (string) (is_object($endpoint) ? $endpoint->id : $endpoint['id']);
            $endpointUrl = (string) (is_object($endpoint) ? $endpoint->url : $endpoint['url']);
            $endpointSecret = (string) (is_object($endpoint) ? $endpoint->secret : $endpoint['secret']);

            // Create delivery record
            $deliveryPayload = [
                'endpoint_id' => $endpointId,
                'event' => $event,
                'payload' => json_encode($payload),
                'status' => 'pending',
            ];

            $delivery = RecordService::executeCreate('sp_webhook_deliveries', $deliveryPayload, [], $tenantId);
            $deliveryId = (string) (is_object($delivery['data'] ?? null)
                ? $delivery['data']->id
                : ($delivery['data']['id'] ?? $delivery['id'] ?? ''));

            // Dispatch Job
            DispatchWebhookJob::dispatch(
                $deliveryId,
                $endpointId,
                $endpointUrl,
                $endpointSecret,
                $event,
                $payload,
                $tenantId
            )->onQueue($queueName);
        }
    }
}
