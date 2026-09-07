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
        $this->assertArrayNotHasKey('auth', $decoded);
        $variables = array_column($decoded['variable'], null, 'key');
        $this->assertArrayHasKey('authToken', $variables);
    }

    public function test_includes_users_folder_in_output(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $folderNames = array_column($decoded['item'], 'name');

        $this->assertContains('Users', $folderNames);

        $usersFolder = array_values(array_filter($decoded['item'], static fn(array $f): bool => $f['name'] === 'Users'))[0];
        $requestNames = array_column($usersFolder['item'], 'name');
        $this->assertContains('List Users', $requestNames);
    }

    public function test_omits_select_param_on_list_request(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $usersFolder = array_values(array_filter($decoded['item'], static fn(array $f): bool => $f['name'] === 'Users'))[0];
        $listUsers = array_values(array_filter($usersFolder['item'], static fn(array $r): bool => $r['name'] === 'List Users'))[0];

        $queryParamNames = array_column($listUsers['request']['url']['query'] ?? [], 'key');
        $this->assertNotContains('select', $queryParamNames);

        $this->assertStringContainsString('Tip:', $listUsers['request']['description']);
    }

    public function test_disables_placeholder_query_params_so_the_request_is_callable(): void
    {
        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $usersFolder = array_values(array_filter($decoded['item'], static fn(array $f): bool => $f['name'] === 'Users'))[0];
        $listUsers = array_values(array_filter($usersFolder['item'], static fn(array $r): bool => $r['name'] === 'List Users'))[0];

        $query = collect($listUsers['request']['url']['query'] ?? [])->keyBy('key');

        // Placeholder-only params (no default) must be disabled so a one-click
        // send doesn't fire empty/example values the API rejects.
        foreach (['id', 'name', 'cursor', 'sortby', 'search'] as $placeholder) {
            $this->assertTrue($query->has($placeholder), sprintf("Expected '%s' to be present", $placeholder));
            $this->assertTrue($query->get($placeholder)['disabled'], sprintf("Expected '%s' to be disabled", $placeholder));
        }

        // Params with real defaults stay enabled with their default values.
        $this->assertFalse($query->get('page')['disabled']);
        $this->assertSame('1', $query->get('page')['value']);
        $this->assertFalse($query->get('per_page')['disabled']);
        $this->assertSame('25', $query->get('per_page')['value']);
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
                ['name' => 'Users', 'item' => [[
                    'name' => 'List Users',
                    'request' => ['method' => 'GET', 'body' => ['mode' => 'raw', 'raw' => '{"name":"fixture"}']],
                ]]],
            ],
        ];
        file_put_contents($this->outputPath, json_encode($existing));

        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
            '--regen' => 'users',
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $usersFolder = array_values(array_filter($decoded['item'], static fn(array $f): bool => $f['name'] === 'Users'))[0];
        $listUsers = collect($usersFolder['item'])->firstWhere('name', 'List Users');
        $this->assertArrayNotHasKey('body', $listUsers['request']);
    }

    public function test_force_replaces_generated_postman_requests_and_preserves_custom_items(): void
    {
        $existing = [
            'item' => [
                ['name' => 'Users', 'item' => [
                    [
                        'name' => 'List Users',
                        'request' => ['method' => 'GET', 'body' => ['mode' => 'raw', 'raw' => '{"name":"fixture"}']],
                    ],
                    ['name' => 'Manual smoke test', 'request' => ['method' => 'GET']],
                ]],
            ],
        ];
        file_put_contents($this->outputPath, json_encode($existing));

        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
            '--force' => true,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $users = collect($decoded['item'])->firstWhere('name', 'Users');
        $listUsers = collect($users['item'])->firstWhere('name', 'List Users');

        $this->assertArrayNotHasKey('body', $listUsers['request']);
        $this->assertNotNull(collect($users['item'])->firstWhere('name', 'Manual smoke test'));
    }

    public function test_default_export_preserves_matching_requests_and_custom_items_while_adding_new_endpoints(): void
    {
        $existing = [
            'info' => ['name' => 'My hand-edited collection'],
            'item' => [
                ['name' => 'Users', 'item' => [
                    [
                        'name' => 'List Users',
                        'request' => [
                            'method' => 'GET',
                            'body' => ['mode' => 'raw', 'raw' => '{"name":"fixture"}'],
                            'header' => [['key' => 'X-Test', 'value' => 'keep']],
                        ],
                    ],
                    ['name' => 'Manual smoke test', 'request' => ['method' => 'GET']],
                ]],
            ],
        ];
        file_put_contents($this->outputPath, json_encode($existing));

        Config::set('record.tables', [
            ...Config::get('record.tables'),
            'orders' => new RecordTableType(
                table: 'orders',
                pmsName: 'orders',
                columns: ['id' => ['type' => 'integer', 'nullable' => false]],
            ),
        ]);

        $this->artisan('sp-laravel-api:export-postman', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $users = collect($decoded['item'])->firstWhere('name', 'Users');
        $listUsers = collect($users['item'])->firstWhere('name', 'List Users');

        $this->assertSame('{"name":"fixture"}', $listUsers['request']['body']['raw']);
        $this->assertSame('keep', $listUsers['request']['header'][0]['value']);
        $this->assertNotNull(collect($users['item'])->firstWhere('name', 'Manual smoke test'));
        $this->assertNotNull(collect($decoded['item'])->firstWhere('name', 'Orders'));
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
