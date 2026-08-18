<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class AgentSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sp-laravel-api:agent
                            {--force : Overwrite existing agent files}
                            {--skill : Install agent skill only}
                            {--rules : Install agent rules/guidelines only}
                            {--mcp : Configure MCP entry only}
                            {--all : Install skill, rules, and MCP entry (default)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up AI agent skills, rules, and MCP configuration on demand';

    /**
     * Command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = ['sp-laravel-api:agent-init'];

    /**
     * The MCP server entry written into .mcp.json.
     *
     * @var array<string, mixed>
     */
    private const MCP_SERVER_ENTRY = [
        'command' => 'php',
        'args' => ['artisan', 'sp-laravel-api:mcp'],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $onlySkill = (bool) $this->option('skill');
        $onlyRules = (bool) $this->option('rules');
        $onlyMcp = (bool) $this->option('mcp');

        // If no specific flag is given, run all actions
        $runAll = (bool) $this->option('all') || (! $onlySkill && ! $onlyRules && ! $onlyMcp);

        $this->info('Configuring sp-laravel-api agent assets...');
        $this->newLine();

        $success = true;

        if ($runAll || $onlySkill) {
            $this->installSkill($force);
        }

        if ($runAll || $onlyRules) {
            $this->installRules($force);
        }

        if ($runAll || $onlyMcp) {
            $mcpResult = $this->mergeMcpConfig();
            if (! $mcpResult) {
                $this->error('Could not update .mcp.json (unparseable JSON or write failure).');
                $success = false;
            }
        }

        $this->newLine();
        $this->info('Running setup validation...');
        $this->call('sp-laravel-api:validate');
        $this->newLine();

        $this->line('Agent setup complete.');
        $this->line('  • Skill: `.agents/skills/sp-laravel-api-development/SKILL.md`');
        $this->line('  • Rules: `.agents/rules/sp-laravel-api.md`');
        $this->line('  • MCP server: `sp-laravel-api` in `.mcp.json`');

        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Install the sp-laravel-api development skill into .agents/skills/.
     */
    private function installSkill(bool $force): void
    {
        $source = __DIR__ . '/../../resources/agent/skills/sp-laravel-api-development/SKILL.md';
        $destinationDir = base_path('.agents/skills/sp-laravel-api-development');
        $destination = $destinationDir . '/SKILL.md';

        if (! File::exists($source)) {
            $this->warn("  ⚠ Agent skill template not found at [{$source}].");

            return;
        }

        if (! File::isDirectory($destinationDir)) {
            File::makeDirectory($destinationDir, 0755, true);
        }

        if (File::exists($destination) && ! $force) {
            $this->line('  → Agent skill already exists at [.agents/skills/sp-laravel-api-development/SKILL.md] (use --force to overwrite).');

            return;
        }

        File::copy($source, $destination);
        $this->info('  ✓ Installed agent skill to [.agents/skills/sp-laravel-api-development/SKILL.md].');
    }

    /**
     * Install sp-laravel-api guidelines/rules into .agents/rules/.
     */
    private function installRules(bool $force): void
    {
        $source = __DIR__ . '/../../resources/agent/guidelines/core.blade.php';
        $destinationDir = base_path('.agents/rules');
        $destination = $destinationDir . '/sp-laravel-api.md';

        if (! File::exists($source)) {
            $this->warn("  ⚠ Agent guidelines template not found at [{$source}].");

            return;
        }

        if (! File::isDirectory($destinationDir)) {
            File::makeDirectory($destinationDir, 0755, true);
        }

        if (File::exists($destination) && ! $force) {
            $this->line('  → Agent rules already exist at [.agents/rules/sp-laravel-api.md] (use --force to overwrite).');

            return;
        }

        File::copy($source, $destination);
        $this->info('  ✓ Installed agent rules to [.agents/rules/sp-laravel-api.md].');
    }

    /**
     * Read-modify-write merge of the sp-laravel-api entry into .mcp.json.
     */
    private function mergeMcpConfig(): bool
    {
        $path = base_path('.mcp.json');
        $config = ['mcpServers' => []];

        if (File::exists($path)) {
            try {
                $decoded = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }

            if (! is_array($decoded)) {
                return false;
            }

            $config = $decoded;

            if (! isset($config['mcpServers']) || ! is_array($config['mcpServers'])) {
                $config['mcpServers'] = [];
            }
        }

        $existing = $config['mcpServers']['sp-laravel-api'] ?? null;

        if ($existing === self::MCP_SERVER_ENTRY) {
            $this->line('  → [sp-laravel-api] entry in .mcp.json already up to date.');

            return true;
        }

        $config['mcpServers']['sp-laravel-api'] = self::MCP_SERVER_ENTRY;

        try {
            $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (File::put($path, $encoded . PHP_EOL) === false) {
            return false;
        }

        $this->info('  ✓ Added [sp-laravel-api] MCP server entry to .mcp.json.');

        return true;
    }
}
