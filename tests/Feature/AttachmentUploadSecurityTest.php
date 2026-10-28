<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Http\Controllers\AttachmentUploadController;
use Sopheak\Core\Tests\TestCase;

class AttachmentUploadSecurityTest extends TestCase
{
    /** @test */
    public function it_rejects_dangerous_file_extensions(): void
    {
        Storage::fake('local');
        $dangerousFile = UploadedFile::fake()->create('exploit.php', 10, 'application/x-php');

        $request = Request::create(
            uri: '/attachments/upload',
            method: 'POST',
            parameters: [
                'visibility' => 'private',
            ],
            files: [
                'file' => $dangerousFile,
            ]
        );

        $response = (new AttachmentUploadController())->upload($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('File type not allowed', (string) data_get($response->getData(true), 'message'));
    }

    /** @test */
    public function it_rejects_executable_shell_extensions(): void
    {
        Storage::fake('local');
        $shFile = UploadedFile::fake()->create('script.sh', 10, 'text/x-shellscript');

        $request = Request::create(
            uri: '/attachments/upload',
            method: 'POST',
            parameters: [
                'visibility' => 'private',
            ],
            files: [
                'file' => $shFile,
            ]
        );

        $response = (new AttachmentUploadController())->upload($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('File type not allowed', (string) data_get($response->getData(true), 'message'));
    }
}
