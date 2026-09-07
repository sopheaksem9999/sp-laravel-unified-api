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

    public function test_disabled_placeholder_params_are_marked_with_tilde_in_bruno_output(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $listUsersBru = (string) file_get_contents($this->outputPath . '/Users/List Users.bru');

        // Placeholder-only params (no default) are rendered as disabled (~param:)
        // so a one-click send doesn't fire empty values the API rejects.
        foreach (['id', 'name', 'cursor', 'sortby', 'search'] as $placeholder) {
            $this->assertStringContainsString('~' . $placeholder . ':', $listUsersBru, sprintf("Expected disabled '~%s:' in Bruno output", $placeholder));
        }

        // Params with real defaults are enabled with their default values.
        $this->assertStringContainsString('page: 1', $listUsersBru);
        $this->assertStringContainsString('per_page: 25', $listUsersBru);
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

    public function test_default_export_preserves_an_edited_request_and_adds_new_endpoints(): void
    {
        @mkdir($this->outputPath . '/Users', 0o755, true);
        $editedRequest = "meta {\n  name: List Users\n}\n\nbody:json {\n  {\"name\": \"fixture\"}\n}\n\nscript:post-response {\n  test(\"kept\", () => true);\n}\n";
        file_put_contents($this->outputPath . '/Users/List Users.bru', $editedRequest);

        Config::set('record.tables', [
            ...Config::get('record.tables'),
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'orders',
                columns: ['id' => ['type' => 'integer', 'nullable' => false]],
            ),
        ]);

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $this->assertSame($editedRequest, file_get_contents($this->outputPath . '/Users/List Users.bru'));
        $this->assertFileExists($this->outputPath . '/Orders/List Orders.bru');
    }

    public function test_default_reexport_of_an_unchanged_collection_succeeds_without_writing_files(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $listPath = $this->outputPath . '/Users/List Users.bru';
        $before = (string) file_get_contents($listPath);

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $this->assertSame($before, file_get_contents($listPath));
    }

    public function test_force_replaces_generated_files_without_deleting_custom_requests(): void
    {
        @mkdir($this->outputPath . '/Users', 0o755, true);
        file_put_contents($this->outputPath . '/Users/List Users.bru', "meta {\n  name: List Users\n}\n\nbody:json {\n  {\"name\": \"fixture\"}\n}\n");
        file_put_contents($this->outputPath . '/Users/Manual smoke test.bru', "meta {\n  name: Manual smoke test\n}\n");
        file_put_contents($this->outputPath . '/bruno.json', '{"name":"Old API"}');

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString('url: {{baseUrl}}{{apiPrefix}}/users', (string) file_get_contents($this->outputPath . '/Users/List Users.bru'));
        $this->assertSame("meta {\n  name: Manual smoke test\n}\n", file_get_contents($this->outputPath . '/Users/Manual smoke test.bru'));
        $this->assertSame('TestApp API', json_decode((string) file_get_contents($this->outputPath . '/bruno.json'), true)['name']);
    }

    public function test_force_and_regen_cannot_be_used_together(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
            '--force' => true,
            '--regen' => 'users',
        ])->expectsOutputToContain('cannot be used together')
            ->assertExitCode(2);

        $this->assertDirectoryDoesNotExist($this->outputPath);
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
