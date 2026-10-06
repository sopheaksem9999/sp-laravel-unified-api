<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;

/**
 * One `schema://{table}` resource.
 */
final class CatalogResource extends Resource
{
    public function __construct(string $uri, string $name, string $description)
    {
        $this->uri = $uri;
        $this->name = $name;
        $this->title = $name;
        $this->description = $description;
        $this->mimeType = 'application/json';
    }

    public function handle(Request $request): Response
    {
        try {
            $read = (new ToolExecutor())->readResource($this->uri());
        } catch (ToolError $toolError) {
            return Response::error($toolError->getMessage());
        }

        return Response::text((string) $read['contents'][0]['text']);
    }
}
