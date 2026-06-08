<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit\Services\ApiClient;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Services\ApiClient\ExportFolder;
use Sopheak\Core\Services\ApiClient\ExportRequest;
use Sopheak\Core\Services\ApiClient\ExportResult;

class ExportResultTest extends TestCase
{
    public function test_stores_app_name_base_url_and_api_prefix(): void
    {
        $result = new ExportResult(
            appName: 'TestApp',
            baseUrl: 'http://localhost',
            apiPrefix: '/api/v1',
            folders: [],
        );

        $this->assertSame('TestApp', $result->appName);
        $this->assertSame('http://localhost', $result->baseUrl);
        $this->assertSame('/api/v1', $result->apiPrefix);
    }

    public function test_defaults_buckets_to_empty_arrays(): void
    {
        $result = new ExportResult(
            appName: 'TestApp',
            baseUrl: 'http://localhost',
            apiPrefix: '/api/v1',
            folders: [],
        );

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->regenerated);
        $this->assertSame([], $result->skipped);
        $this->assertSame([], $result->suggestions);
    }

    public function test_stores_folders(): void
    {
        $request = new ExportRequest(
            name: 'List Users',
            method: 'GET',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/users',
            description: 'List users',
            pathParams: [],
            queryParams: [],
            headers: [],
        );
        $folder = new ExportFolder(name: 'Users', requests: [$request]);

        $result = new ExportResult(
            appName: 'TestApp',
            baseUrl: 'http://localhost',
            apiPrefix: '/api/v1',
            folders: [$folder],
        );

        $this->assertCount(1, $result->folders);
        $this->assertSame('Users', $result->folders[0]->name);
        $this->assertSame('List Users', $result->folders[0]->requests[0]->name);
    }

    public function test_stores_bucket_names(): void
    {
        $result = new ExportResult(
            appName: 'TestApp',
            baseUrl: 'http://localhost',
            apiPrefix: '/api/v1',
            folders: [],
            added: ['New Request 1', 'New Request 2'],
            regenerated: ['Regen 1'],
            skipped: ['Skip 1', 'Skip 2', 'Skip 3'],
            suggestions: ['products', 'orders'],
        );

        $this->assertSame(['New Request 1', 'New Request 2'], $result->added);
        $this->assertSame(['Regen 1'], $result->regenerated);
        $this->assertSame(['Skip 1', 'Skip 2', 'Skip 3'], $result->skipped);
        $this->assertSame(['products', 'orders'], $result->suggestions);
    }

    public function test_is_empty_returns_true_when_no_folders_and_no_buckets(): void
    {
        $result = new ExportResult(
            appName: 'TestApp',
            baseUrl: 'http://localhost',
            apiPrefix: '/api/v1',
            folders: [],
        );

        $this->assertTrue($result->isEmpty());
    }

    public function test_is_empty_returns_false_when_folders_present(): void
    {
        $request = new ExportRequest(
            name: 'List Users',
            method: 'GET',
            urlTemplate: '{{baseUrl}}{{apiPrefix}}/users',
            description: 'List users',
            pathParams: [],
            queryParams: [],
            headers: [],
        );
        $folder = new ExportFolder(name: 'Users', requests: [$request]);

        $result = new ExportResult(
            appName: 'TestApp',
            baseUrl: 'http://localhost',
            apiPrefix: '/api/v1',
            folders: [$folder],
        );

        $this->assertFalse($result->isEmpty());
    }

    public function test_total_request_count_sums_across_folders(): void
    {
        $r1 = new ExportRequest('A', 'GET', '/a', 'desc', [], [], []);
        $r2 = new ExportRequest('B', 'GET', '/b', 'desc', [], [], []);
        $r3 = new ExportRequest('C', 'GET', '/c', 'desc', [], [], []);

        $f1 = new ExportFolder('Users', [$r1, $r2]);
        $f2 = new ExportFolder('Orders', [$r3]);

        $result = new ExportResult('App', 'http://localhost', '/api', [$f1, $f2]);

        $this->assertSame(3, $result->totalRequestCount());
    }
}
