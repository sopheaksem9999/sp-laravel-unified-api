<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Services\AttachmentStorageService;
use Sopheak\Core\Tests\TestCase;

class AttachmentStorageServiceTest extends TestCase
{
    public function test_visibility_resolves_to_configured_disks(): void
    {
        Config::set('attachments.disk_public', 'r2-public');
        Config::set('attachments.disk_private', 'r2-private');

        $service = app(AttachmentStorageService::class);

        $this->assertSame('r2-public', $service->resolveDiskFromVisibility('public'));
        $this->assertSame('r2-public', $service->resolveDiskFromVisibility('temp_public'));
        $this->assertSame('r2-private', $service->resolveDiskFromVisibility('private'));
        $this->assertSame('r2-private', $service->resolveDiskFromVisibility('temp_private'));
    }

    public function test_default_storage_paths_keep_the_existing_shape(): void
    {
        Config::set('attachments.direct_upload.storage_prefix', 'attachments');

        $path = app(AttachmentStorageService::class)->generateStoragePath('private', 'mp4');

        $this->assertMatchesRegularExpression(
            '#^attachments/private/\d{4}/\d{2}/\d{2}/[0-9a-f-]{36}\.mp4$#',
            $path
        );
    }

    public function test_custom_storage_prefix_is_applied(): void
    {
        Config::set('attachments.direct_upload.storage_prefix', 'media/vault');

        $path = app(AttachmentStorageService::class)->generateStoragePath('public', 'jpg');

        $this->assertStringStartsWith('media/vault/public/', $path);
    }
}
