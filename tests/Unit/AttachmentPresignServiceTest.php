<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Services\AttachmentPresignService;
use Sopheak\Core\Tests\TestCase;

class AttachmentPresignServiceTest extends TestCase
{
    public function test_support_detection_matches_the_configured_disk(): void
    {
        $disk = Storage::disk('local');

        $this->assertSame(
            $disk->providesTemporaryUploadUrls(),
            app(AttachmentPresignService::class)->supportsPresignedUploads('local')
        );
    }
}
