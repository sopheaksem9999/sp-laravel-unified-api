<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Sopheak\Core\Tests\TestCase;

class AgentSetupCommandTest extends TestCase
{
    private string $mcpPath;

    private string $skillDir;

    private string $rulesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mcpPath = base_path('.mcp.json');
        $this->skillDir = base_path('.agents/skills/sp-laravel-api-development');
        $this->rulesDir = base_path('.agents/rules');

        if (File::exists($this->mcpPath)) {
            File::copy($this->mcpPath, $this->mcpPath . '.bak');
        }
    }

    protected function tearDown(): void
    {
        File::delete($this->mcpPath);
        if (File::exists($this->mcpPath . '.bak')) {
            File::move($this->mcpPath . '.bak', $this->mcpPath);
        }

        File::deleteDirectory(base_path('.agents/skills/sp-laravel-api-development'));
        File::delete(base_path('.agents/rules/sp-laravel-api.md'));

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function readMcp(): array
    {
        return json_decode((string) File::get($this->mcpPath), true);
    }

    public function test_it_installs_skill_rules_and_mcp_by_default(): void
    {
        File::delete($this->mcpPath);
        File::deleteDirectory($this->skillDir);
        File::delete($this->rulesDir . '/sp-laravel-api.md');

        $this->artisan('sp-laravel-api:agent')->assertSuccessful();

        $this->assertFileExists($this->skillDir . '/SKILL.md');
        $this->assertFileExists($this->rulesDir . '/sp-laravel-api.md');
        $this->assertFileExists($this->mcpPath);

        $config = $this->readMcp();
        $this->assertSame([
            'command' => 'php',
            'args' => ['artisan', 'sp-laravel-api:mcp'],
        ], $config['mcpServers']['sp-laravel-api'] ?? null);
    }

    public function test_it_installs_skill_only_with_skill_flag(): void
    {
        File::delete($this->mcpPath);
        File::deleteDirectory($this->skillDir);
        File::delete($this->rulesDir . '/sp-laravel-api.md');

        $this->artisan('sp-laravel-api:agent --skill')->assertSuccessful();

        $this->assertFileExists($this->skillDir . '/SKILL.md');
        $this->assertFileDoesNotExist($this->rulesDir . '/sp-laravel-api.md');
        $this->assertFileDoesNotExist($this->mcpPath);
    }

    public function test_it_installs_rules_only_with_rules_flag(): void
    {
        File::delete($this->mcpPath);
        File::deleteDirectory($this->skillDir);
        File::delete($this->rulesDir . '/sp-laravel-api.md');

        $this->artisan('sp-laravel-api:agent --rules')->assertSuccessful();

        $this->assertFileDoesNotExist($this->skillDir . '/SKILL.md');
        $this->assertFileExists($this->rulesDir . '/sp-laravel-api.md');
        $this->assertFileDoesNotExist($this->mcpPath);
    }

    public function test_it_configures_mcp_only_with_mcp_flag(): void
    {
        File::delete($this->mcpPath);
        File::deleteDirectory($this->skillDir);
        File::delete($this->rulesDir . '/sp-laravel-api.md');

        $this->artisan('sp-laravel-api:agent --mcp')->assertSuccessful();

        $this->assertFileDoesNotExist($this->skillDir . '/SKILL.md');
        $this->assertFileDoesNotExist($this->rulesDir . '/sp-laravel-api.md');
        $this->assertFileExists($this->mcpPath);
    }

    public function test_it_does_not_overwrite_without_force(): void
    {
        File::ensureDirectoryExists($this->skillDir);
        File::put($this->skillDir . '/SKILL.md', 'custom-content');

        $this->artisan('sp-laravel-api:agent --skill')
            ->expectsOutputToContain('use --force to overwrite')
            ->assertSuccessful();

        $this->assertSame('custom-content', File::get($this->skillDir . '/SKILL.md'));
    }

    public function test_it_overwrites_when_force_is_provided(): void
    {
        File::ensureDirectoryExists($this->skillDir);
        File::put($this->skillDir . '/SKILL.md', 'custom-content');

        $this->artisan('sp-laravel-api:agent --skill --force')
            ->expectsOutputToContain('Installed agent skill')
            ->assertSuccessful();

        $this->assertNotSame('custom-content', File::get($this->skillDir . '/SKILL.md'));
    }

    public function test_it_preserves_existing_mcp_entries(): void
    {
        File::put($this->mcpPath, json_encode([
            'mcpServers' => [
                'laravel-boost' => [
                    'command' => 'php',
                    'args' => ['artisan', 'boost:mcp'],
                ],
            ],
        ]));

        $this->artisan('sp-laravel-api:agent')->assertSuccessful();

        $config = $this->readMcp();
        $this->assertSame(['php', ['artisan', 'boost:mcp']], [$config['mcpServers']['laravel-boost']['command'], $config['mcpServers']['laravel-boost']['args']]);
        $this->assertSame(['php', ['artisan', 'sp-laravel-api:mcp']], [$config['mcpServers']['sp-laravel-api']['command'], $config['mcpServers']['sp-laravel-api']['args']]);
    }
}
