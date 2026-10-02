<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Tools;

use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;

/**
 * `tools/call` for the catalog. laravel/mcp's own handler turns anything a tool
 * throws into an `isError` result with a generic message, which would turn
 * Forbidden (-32002) and Unauthenticated (-32001) into "An internal server
 * error occurred." Running every call through ToolExecutor keeps the JSON-RPC
 * codes the `legacy` driver returns, for tools the catalog lists and for names
 * it does not (so a hidden or read-only-disabled tool is still refused by the
 * executor, not merely absent).
 */
class CatalogCallTool extends CallTool
{
    protected bool $schemaOnly = false;

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $name = $request->params['name'] ?? null;
        if (!is_string($name) || '' === $name) {
            throw new JsonRpcException('Missing [name] parameter.', -32602, $request->id);
        }

        try {
            $result = (new ToolExecutor($this->schemaOnly))->call($name, $request->toRequest()->all());
        } catch (ToolError $toolError) {
            throw new JsonRpcException($toolError->getMessage(), $toolError->getCode(), $request->id);
        }

        return JsonRpcResponse::result($request->id, $result->toWireArray());
    }
}
