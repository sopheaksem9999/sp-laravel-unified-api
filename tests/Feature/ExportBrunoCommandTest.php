<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @internal
 */
class ExportBrunoCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaRegistryUtils::refresh();

        $this->outputPath = sys_get_temp_dir() . '/bruno-test-' . uniqid();
        $this->cleanupDir($this->outputPath);

        // Ensure a known app + api prefix
        Config::set('app.name', 'TestApp');
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v1');

        // Register a minimal table so the OpenAPI spec has something to export
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                isAuthRead: true,
                isAuthWrite: true,
                columns: [
                    'id' => ['type' => 'integer', 'nullable' => false],
                    'name' => ['type' => 'string', 'nullable' => false],
                ],
            ),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->outputPath);
        parent::tearDown();
    }

    public function test_writes_a_bruno_collection_folder_structure(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $this->assertFileExists($this->outputPath . '/bruno.json');
        $this->assertFileExists($this->outputPath . '/collection.bru');
        $this->assertFileExists($this->outputPath . '/environments/Local.bru');
        $this->assertFileExists($this->outputPath . '/Users/List Users.bru');

        $brunoJson = json_decode((string) file_get_contents($this->outputPath . '/bruno.json'), true);
        $this->assertSame('TestApp API', $brunoJson['name']);
        $this->assertSame('collection', $brunoJson['type']);

        $collectionBru = (string) file_get_contents($this->outputPath . '/collection.bru');
        $this->assertStringContainsString('name: TestApp API', $collectionBru);

        $localEnvBru = (string) file_get_contents($this->outputPath . '/environments/Local.bru');
        $this->assertStringContainsString('baseUrl: http://localhost', $localEnvBru);

        $listUsersBru = (string) file_get_contents($this->outputPath . '/Users/List Users.bru');
        $this->assertStringContainsString('name: List Users', $listUsersBru);
        $this->assertStringContainsString('url: {{baseUrl}}{{apiPrefix}}/users', $listUsersBru);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($this->outputPath);
    }

    public function test_regen_flag_regenerates_specific_table(): void
    {
        // Seed an existing collection with stale data
        @mkdir($this->outputPath . '/Users', 0o755, true);
        file_put_contents($this->outputPath . '/Users/List Users.bru', "meta {\n  name: List Users\n}");

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
            '--regen' => 'users',
        ])->assertExitCode(0);

        $listUsersBru = (string) file_get_contents($this->outputPath . '/Users/List Users.bru');
        $this->assertStringContainsString('url: {{baseUrl}}{{apiPrefix}}/users', $listUsersBru);
    }

    public function test_creates_output_directory_if_missing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/bruno-test-' . uniqid() . '/nested/bruno';

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $nestedPath,
        ])->assertExitCode(0);

        $this->assertFileExists($nestedPath . '/bruno.json');
        $this->cleanupDir(dirname($nestedPath));
    }

    private function cleanupDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = scandir($path);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullPath)) {
                $this->cleanupDir($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
