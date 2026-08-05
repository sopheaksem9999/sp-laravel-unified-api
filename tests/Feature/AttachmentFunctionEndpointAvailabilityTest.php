<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class AttachmentFunctionEndpointAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $attachmentConfig = require __DIR__ . '/../../config/sp-attachments.php';
        Config::set('attachments.enabled', true);
        Config::set('attachments.tables', $attachmentConfig['tables'] ?? []);
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function upload_function_is_not_blocked_by_can_create_false(): void
    {
        $rpcPrefix = RecordConfigService::rpcPrefix();
        $uri = '' !== $rpcPrefix
            ? '/' . RecordConfigService::apiPrefix() . '/sp_attachments/' . $rpcPrefix . '/upload'
            : '/' . RecordConfigService::apiPrefix() . '/sp_attachments/upload';

        $response = $this->postJson($uri);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Authentication required',
        ]);
    }
}
