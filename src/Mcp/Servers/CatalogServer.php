<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Servers;

use Laravel\Mcp\Server;
use Sopheak\Core\Mcp\Resources\CatalogResource;
use Sopheak\Core\Mcp\ToolCatalog;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\Tools\CatalogCallTool;
use Sopheak\Core\Mcp\Tools\CatalogTool;
use Sopheak\Core\Mcp\Tools\SchemaCatalogCallTool;
use Sopheak\Core\Support\CacheRequestContext;

/**
 * Publishes the package's ToolCatalog through laravel/mcp.
 */
abstract class CatalogServer extends Server
{
    protected string $name = 'sp-laravel-api-mcp';

    protected string $version = '1.0.0';

    protected string $instructions = 'Start with sp_api_get_api_guidance, then sp_api_list_endpoints and sp_api_get_endpoint before calling any endpoint. Never invent a request body.';

    protected bool $schemaOnly = false;

    /**
     * The legacy driver returns the whole catalog in one response, and a client
     * that ignores `nextCursor` would silently miss tools if this stayed at
     * laravel/mcp's default of 15. Typical apps fit in one page; a larger
     * catalog pages, and compliant clients follow `nextCursor`.
     */
    public int $defaultPaginationLength = 500;

    public int $maxPaginationLength = 500;

    /**
     * A long-lived process (stdio, Octane) gets neither RouteMatched nor a queue
     * worker's forgetScopedInstances(), so without this the cache namespace memo
     * would live as long as the process and keep serving reads that another
     * process has already invalidated. The legacy stdio loop resets it for every
     * message; so does this server.
     */
    public function handle(string $rawMessage): void
    {
        app(CacheRequestContext::class)->reset();

        parent::handle($rawMessage);
    }

    protected function boot(): void
    {
        $this->addMethod('tools/call', $this->schemaOnly ? SchemaCatalogCallTool::class : CatalogCallTool::class);

        $catalog = new ToolCatalog();
        $this->tools = array_map(
            fn (ToolDefinition $definition): CatalogTool => new CatalogTool($definition, $this->schemaOnly),
            $catalog->tools($this->schemaOnly)
        );
        $this->resources = array_map(
            static fn (array $resource): CatalogResource => new CatalogResource($resource['uri'], $resource['name'], $resource['description']),
            $catalog->resources()
        );
    }
}
