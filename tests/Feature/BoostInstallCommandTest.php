<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Sopheak\Core\Tests\TestCase;

class BoostInstallCommandTest extends TestCase
{
    private string $mcpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mcpPath = base_path('.mcp.json');

        if (File::exists($this->mcpPath)) {
            File::copy($this->mcpPath, $this->mcpPath.'.bak');
        }
    }

    protected function tearDown(): void
    {
        File::delete($this->mcpPath);

        if (File::exists($this->mcpPath.'.bak')) {
            File::move($this->mcpPath.'.bak', $this->mcpPath);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function readMcp(): array
    {
        return json_decode((string) File::get($this->mcpPath), true);
    }

    public function test_it_creates_mcp_json_when_missing(): void
    {
        File::delete($this->mcpPath);

        $this->artisan('sp-laravel-api:boost')->assertSuccessful();

        $this->assertFileExists($this->mcpPath);
        $config = $this->readMcp();

        $this->assertSame([
            'command' => 'php',
            'args' => ['artisan', 'sp-laravel-api:mcp'],
        ], $config['mcpServers']['sp-laravel-api'] ?? null);
    }

    public function test_it_merges_into_existing_mcp_json_and_preserves_other_servers(): void
    {
        File::put($this->mcpPath, json_encode([
            'mcpServers' => [
                'laravel-boost' => [
                    'command' => 'php',
                    'args' => ['artisan', 'boost:mcp'],
                ],
                'other-server' => [
                    'command' => 'npx',
                    'args' => ['some-tool'],
                ],
            ],
        ]));

        $this->artisan('sp-laravel-api:boost')->assertSuccessful();

        $config = $this->readMcp();

        $this->assertSame(['php', ['artisan', 'boost:mcp']], [$config['mcpServers']['laravel-boost']['command'], $config['mcpServers']['laravel-boost']['args']]);
        $this->assertSame(['npx', ['some-tool']], [$config['mcpServers']['other-server']['command'], $config['mcpServers']['other-server']['args']]);
        $this->assertSame('php', $config['mcpServers']['sp-laravel-api']['command']);
        $this->assertSame(['artisan', 'sp-laravel-api:mcp'], $config['mcpServers']['sp-laravel-api']['args']);
    }

    public function test_it_is_idempotent_on_identical_content(): void
    {
        File::put($this->mcpPath, json_encode([
            'mcpServers' => [
                'laravel-boost' => [
                    'command' => 'php',
                    'args' => ['artisan', 'boost:mcp'],
                ],
                'sp-laravel-api' => [
                    'command' => 'php',
                    'args' => ['artisan', 'sp-laravel-api:mcp'],
                ],
            ],
        ]));

        $before = File::get($this->mcpPath);

        $this->artisan('sp-laravel-api:boost')
            ->expectsOutputToContain('already present and up to date')
            ->assertSuccessful();

        $this->assertSame($before, File::get($this->mcpPath));
    }

    public function test_it_refuses_to_clobber_unparseable_mcp_json(): void
    {
        File::put($this->mcpPath, '{ not valid json ');

        $this->artisan('sp-laravel-api:boost')->assertFailed();

        $this->assertStringContainsString('not valid json', File::get($this->mcpPath));
    }
}
