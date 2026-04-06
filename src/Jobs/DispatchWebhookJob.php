<?php

namespace Sopheak\Core\Jobs;

use Illuminate\Http\Client\Response;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Sopheak\Core\Services\RecordService;
use Exception;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public $backoff = [60, 300, 600]; // 1 min, 5 mins, 10 mins

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $deliveryId,
        public string $endpointId,
        public string $url,
        public string $secret,
        public string $event,
        public array $payload,
        public ?string $tenantId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $payloadJson = json_encode($this->payload);
        $signature = hash_hmac('sha256', $payloadJson ?: '', $this->secret);

        try {
            /** @var Response $response */
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Webhook-Event' => $this->event,
                    'X-Webhook-Signature' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->url, $this->payload);

            $status = $response->successful() ? 'success' : 'failed';

            RecordService::executeUpdate('sp_webhook_deliveries', $this->deliveryId, [
                'response_status' => $response->status(),
                'response_body' => $response->body(),
                'status' => $status,
            ], [], $this->tenantId);

            if ($response->failed()) {
                $response->throw(); // Trigger retry
            }
        } catch (Exception $exception) {
            RecordService::executeUpdate('sp_webhook_deliveries', $this->deliveryId, [
                'response_status' => 500,
                'response_body' => $exception->getMessage(),
                'status' => 'failed',
            ], [], $this->tenantId);

            throw $exception; // Trigger retry
        }
    }
}
