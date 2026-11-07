<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Throwable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Jobs\DispatchWebhookJob;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class WebhookSsrfSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('sp_webhook_deliveries')) {
            Schema::create('sp_webhook_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->string('endpoint_id')->nullable();
                $table->integer('response_status')->nullable();
                $table->text('response_body')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        Config::set('record.tables.sp_webhook_deliveries', new RecordTableType(
            table: 'sp_webhook_deliveries',
        ));
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function dispatch_webhook_job_blocks_private_ip_requests_and_records_failure(): void
    {
        Http::fake();

        $deliveryId = (string) DB::table('sp_webhook_deliveries')->insertGetId([
            'endpoint_id' => 'ep_1',
            'event' => 'record.created',
            'payload' => json_encode(['id' => 1]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new DispatchWebhookJob(
            deliveryId: $deliveryId,
            endpointId: 'ep_1',
            url: 'http://169.254.169.254/latest/meta-data/',
            secret: 'sec_123',
            event: 'record.created',
            payload: ['id' => 1]
        );

        $job->handle();

        Http::assertNothingSent();

        $this->assertDatabaseHas('sp_webhook_deliveries', [
            'id' => (int) $deliveryId,
            'response_status' => 400,
            'status' => 'failed',
        ]);
    }

    /** @test */
    public function dispatch_webhook_job_does_not_follow_http_redirects(): void
    {
        Http::fake([
            'https://1.1.1.1/webhook' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        ]);

        $deliveryId = (string) DB::table('sp_webhook_deliveries')->insertGetId([
            'endpoint_id' => 'ep_2',
            'event' => 'record.created',
            'payload' => json_encode(['id' => 2]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new DispatchWebhookJob(
            deliveryId: $deliveryId,
            endpointId: 'ep_2',
            url: 'https://1.1.1.1/webhook',
            secret: 'sec_123',
            event: 'record.created',
            payload: ['id' => 2]
        );

        try {
            $job->handle();
        } catch (Throwable) {
            // Fails due to 302 not being successful
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://1.1.1.1/webhook');
        $this->assertDatabaseHas('sp_webhook_deliveries', [
            'id' => (int) $deliveryId,
            'response_status' => 302,
            'status' => 'failed',
        ]);
    }
}
