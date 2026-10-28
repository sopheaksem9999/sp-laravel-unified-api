<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Utilities\UrlSecurityValidator;

class UrlSecurityValidatorTest extends TestCase
{
    /**
     * @dataProvider invalidUrlProvider
     */
    public function test_rejects_private_and_metadata_urls(string $url): void
    {
        $this->assertFalse(UrlSecurityValidator::isPublicHttpUrl($url));
    }

    public static function invalidUrlProvider(): array
    {
        return [
            ['http://127.0.0.1/webhook'],
            ['http://localhost:8000/webhook'],
            ['http://169.254.169.254/latest/meta-data'],
            ['http://10.0.0.1/hook'],
            ['http://192.168.1.1/hook'],
            ['http://172.16.0.1/hook'],
            ['file:///etc/passwd'],
            ['gopher://127.0.0.1:6379'],
            ['javascript:alert(1)'],
            ['http://0.0.0.0/webhook'],
            ['http://[::1]/webhook'],
        ];
    }

    /**
     * @dataProvider validUrlProvider
     */
    public function test_allows_public_urls(string $url): void
    {
        $this->assertTrue(UrlSecurityValidator::isPublicHttpUrl($url));
    }

    public static function validUrlProvider(): array
    {
        return [
            ['https://example.com/webhook'],
            ['https://api.github.com/webhook'],
            ['http://93.184.216.34/webhook'],
        ];
    }
}
