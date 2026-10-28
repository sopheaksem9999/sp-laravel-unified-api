<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Sopheak\Core\Tests\TestCase;

class ApiDocsAuthTest extends TestCase
{
    /** @test */
    public function it_rejects_external_or_custom_endpoint_parameter(): void
    {
        Http::fake();
        Config::set('record.api_docs.login_api', '/api/v1/auth/login');

        $this->postJson('/api-docs/auth/login', [
            'endpoint' => 'http://169.254.169.254/latest/meta-data/',
            'username' => 'test@example.com',
            'password' => 'secret',
        ]);

        // Requests targeting non-configured external endpoints must not be dispatched
        Http::assertNothingSent();
    }
}
