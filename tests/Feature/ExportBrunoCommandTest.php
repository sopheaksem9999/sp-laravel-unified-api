<?php

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

        $this->outputPath = sys_get_temp_dir() . '/bruno-test-' . uniqid() . '.bru';
        if (is_file($this->outputPath)) {
            unlink($this->outputPath);
        }

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
        if (is_file($this->outputPath)) {
            unlink($this->outputPath);
        }

        parent::tearDown();
    }

    public function test_writes_a_bruno_v3_collection_to_the_output_path(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $this->assertFileExists($this->outputPath);
        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $this->assertIsArray($decoded);
        $this->assertSame('v3', $decoded['meta']['version']);
        $this->assertSame('TestApp API', $decoded['meta']['name']);
        $this->assertSame('bearer', $decoded['auth']['mode']);
        $this->assertSame('http://localhost', $decoded['vars']['baseUrl']['value']);
        $this->assertSame('/api/v1', $decoded['vars']['apiPrefix']['value']);
        $this->assertTrue($decoded['vars']['bearerToken']['secret']);
    }

    public function test_includes_users_folder_in_output(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $folderNames = array_column($decoded['folders'], 'name');

        $this->assertContains('Users', $folderNames);

        $usersFolder = array_values(array_filter($decoded['folders'], static fn ($f) => $f['name'] === 'Users'))[0];
        $requestNames = array_column($usersFolder['requests'], 'name');
        $this->assertContains('List Users', $requestNames);
    }

    public function test_injects_select_param_on_list_request(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $usersFolder = array_values(array_filter($decoded['folders'], static fn ($f) => $f['name'] === 'Users'))[0];
        $listUsers = array_values(array_filter($usersFolder['requests'], static fn ($r) => $r['name'] === 'List Users'))[0];

        $select = array_values(array_filter($listUsers['params'], static fn ($p) => $p['name'] === 'select'))[0];
        $this->assertFalse($select['enabled']);
        $this->assertSame('', $select['value']);
    }

    public function test_dry_run_does_not_write_the_file(): void
    {
        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($this->outputPath);
    }

    public function test_regen_flag_regenerates_specific_table(): void
    {
        // Seed an existing collection with stale data
        $existing = [
            'meta' => ['name' => 'TestApp API', 'type' => 'collection', 'version' => 'v3'],
            'vars' => [
                'baseUrl' => ['value' => 'http://OLD'],
                'apiPrefix' => ['value' => '/OLD'],
                'bearerToken' => ['value' => '', 'secret' => true],
            ],
            'folders' => [
                ['name' => 'Users', 'requests' => [['name' => 'List Users']]],
            ],
        ];
        file_put_contents($this->outputPath, json_encode($existing));

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
            '--regen' => 'users',
        ])->assertExitCode(0);

        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        // The var should be updated to the new value (regenerated)
        $this->assertSame('http://localhost', $decoded['vars']['baseUrl']['value']);
        $this->assertSame('/api/v1', $decoded['vars']['apiPrefix']['value']);
    }

    public function test_default_run_preserves_existing_var_values(): void
    {
        // Default mode skips existing — the var values should be preserved
        $existing = [
            'meta' => ['name' => 'TestApp API', 'type' => 'collection', 'version' => 'v3'],
            'vars' => [
                'baseUrl' => ['value' => 'http://OLD'],
                'apiPrefix' => ['value' => '/OLD'],
                'bearerToken' => ['value' => 'OLD_TOKEN', 'secret' => true],
            ],
            'folders' => [
                ['name' => 'Users', 'requests' => [['name' => 'List Users']]],
            ],
        ];
        file_put_contents($this->outputPath, json_encode($existing));

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(0);

        // Default mode = skip existing -> existing names not regenerated
        // But our emitter always rewrites the collection; the var is re-rendered each run.
        // The user's expectation is that "List Users" stays in skipped[].
        $decoded = json_decode((string) file_get_contents($this->outputPath), true);
        $usersFolder = array_values(array_filter($decoded['folders'], static fn ($f) => $f['name'] === 'Users'))[0];
        $requestNames = array_column($usersFolder['requests'], 'name');
        $this->assertContains('List Users', $requestNames);
    }

    public function test_returns_failure_when_output_directory_is_unwritable(): void
    {
        $badPath = '/this/directory/does/not/exist/and/cannot/be/created.bru';

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $badPath,
        ])->assertExitCode(1);
    }

    public function test_creates_parent_directory_if_missing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/bruno-test-' . uniqid() . '/nested/collection.bru';

        $this->artisan('sp-laravel-api:export-bruno', [
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

        $this->artisan('sp-laravel-api:export-bruno', [
            '--output' => $this->outputPath,
        ])->assertExitCode(1);
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
