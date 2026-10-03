<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class BoostInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sp-laravel-api:boost';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Merge the sp-laravel-api MCP server into .mcp.json and validate the setup';

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
        $this->info('Merging the [sp-laravel-api] MCP server into .mcp.json...');
        $merged = $this->mergeMcpConfig();

        if (! $merged) {
            $this->error('Could not update .mcp.json (unparseable JSON or write failure).');
            $this->line('Fix .mcp.json manually, then re-run this command.');

            return Command::FAILURE;
        }

        $this->info('Running setup validation...');
        $this->newLine();
        $this->call('sp-laravel-api:validate');
        $this->newLine();

        $this->line('Next steps:');
        $this->line('  • Run `php artisan sp-laravel-api:agent` to install the agent skill and rules.');
        $this->line('  • If you use Laravel Boost, re-run `php artisan sp-laravel-api:boost` if Boost regenerates .mcp.json.');

        return Command::SUCCESS;
    }

    /**
     * Read-modify-write merge of the sp-laravel-api entry into .mcp.json,
     * preserving every other server (laravel-boost included). Creates the
     * file when missing. Idempotent: untouched when the entry already matches.
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
            $this->line('  → [sp-laravel-api] entry already present and up to date.');

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

        $this->line('  → Added the [sp-laravel-api] MCP server to .mcp.json (existing servers preserved).');

        return true;
    }
}
