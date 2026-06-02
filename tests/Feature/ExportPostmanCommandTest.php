<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @internal
 */
class ExportPostmanCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaRegistryUtils::refresh();

        $this->outputPath = sys_get_temp_dir() . '/postman-test-' . uniqid() . '.json';
        if (is_file($this->outputPath)) {
            unlink($this->outputPath);
        }

        Config::set('app.name', 'TestApp');
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v1');

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
        if (is_file($this->outputPath)) {
            unlink($this->outputPath);
        }
        parent::tearDown();
    }

    public function test_writes_a_postman_v2_1_collection_to_the_output_path(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $this->assertFileExists($this->outputPath);
        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $this->assertIsArray($decoded);
        $this->assertSame('TestApp API', $decoded['info']['name']);
        $this->assertSame('https://schema.getpostman.com/json/collection/v2.1.0/collection.json', $decoded['info']['schema']);
        $this->assertSame('bearer', $decoded['auth']['type']);
    }

    public function test_includes_users_folder_in_output(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $folderNames = array_column($decoded['item'], 'name');

        $this->assertContains('Users', $folderNames);

        $usersFolder = array_values(array_filter($decoded['item'], static fn ($f) => $f['name'] === 'Users'))[0];
        $requestNames = array_column($usersFolder['item'], 'name');
        $this->assertContains('List Users', $requestNames);
    }

    public function test_omits_select_param_on_list_request(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $usersFolder = array_values(array_filter($decoded['item'], static fn ($f) => $f['name'] === 'Users'))[0];
        $listUsers = array_values(array_filter($usersFolder['item'], static fn ($r) => $r['name'] === 'List Users'))[0];

        $queryParamNames = array_column($listUsers['request']['url']['query'] ?? [], 'key');
        $this->assertNotContains('select', $queryParamNames);

        $this->assertStringContainsString('Tip:', $listUsers['request']['description']);
    }

    public function test_dry_run_does_not_write_the_file(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($this->outputPath);
    }

    public function test_regen_flag_regenerates_specific_table(): void
    {
        $existing = [
            'info' => ['name' => 'TestApp API', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                ['name' => 'Users', 'item' => [['name' => 'List Users']]],
            ],
        ];
        file_put_contents($this->outputPath, json_encode($existing));

        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
            '--regen' => 'users',
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $usersFolder = array_values(array_filter($decoded['item'], static fn ($f) => $f['name'] === 'Users'))[0];
        $requestNames = array_column($usersFolder['item'], 'name');
        $this->assertContains('List Users', $requestNames);
    }

    public function test_invalid_regen_returns_exit_code_2(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
            '--regen' => 'nonexistent',
        ])->assertExitCode(2);
    }

    public function test_returns_failure_when_output_directory_is_unwritable(): void
    {
        $badPath = '/this/directory/does/not/exist/and/cannot/be/created.json';

        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $badPath,
        ])->assertExitCode(1);
    }

    public function test_creates_parent_directory_if_missing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/postman-test-' . uniqid() . '/nested/collection.json';

        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $nestedPath,
        ])->assertExitCode(0);

        $this->assertFileExists($nestedPath);

        @unlink($nestedPath);
        @rmdir(dirname($nestedPath));
        @rmdir(dirname($nestedPath, 2));
    }

    public function test_returns_failure_when_existing_file_is_invalid_json(): void
    {
        file_put_contents($this->outputPath, 'not json');

        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(1);
    }
}
