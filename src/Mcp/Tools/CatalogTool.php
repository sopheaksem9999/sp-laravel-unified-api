<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Sopheak\Core\Constants\RecordConstants;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\ToolExecutor;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\PermissionUtils;

/**
 * One catalog definition published through laravel/mcp. toArray() emits the
 * definition's raw JSON Schema, because the catalog uses union types such as
 * ["string","integer"] that laravel/mcp's JsonSchema builder cannot express.
 */
final class CatalogTool extends Tool
{
    public function __construct(private readonly ToolDefinition $definition, private readonly bool $schemaOnly = false)
    {
        $this->name = $definition->name;
        $this->title = (string) $definition->title;
        $this->description = $definition->description;
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition->toWireArray();
    }

    /**
     * Hide a data tool whose action the caller is not authorised for, so
     * tools/list shows each user only what they can do. Hiding is a courtesy,
     * not authorization: CatalogCallTool still sends every call through
     * ToolExecutor, which refuses a hidden tool with Forbidden.
     *
     * The decision is the one every entry point makes
     * (PermissionUtils::actionDecision), answered from the cached per-user
     * permission set, so listing N tools costs no per-tool query.
     */
    public function shouldRegister(): bool
    {
        if ('schema' === $this->definition->action || null === $this->definition->table) {
            return true;
        }

        $authAction = match ($this->definition->action) {
            'create' => RecordConstants::ACTION_CREATE,
            'update' => RecordConstants::ACTION_UPDATE,
            'delete' => RecordConstants::ACTION_DELETE,
            default => RecordConstants::READ,
        };

        return PermissionUtils::DECISION_ALLOWED === PermissionUtils::actionDecision(
            auth(RecordConfigService::authGuard())->user(),
            $this->definition->table,
            $authAction
        );
    }

    /**
     * Used by laravel/mcp's test helpers; tools/call goes through
     * CatalogCallTool so JSON-RPC error codes survive.
     */
    public function handle(Request $request): ResponseFactory|Response
    {
        $result = (new ToolExecutor($this->schemaOnly))->call($this->definition->name, $request->all());

        if ($result->isError) {
            return Response::error((string) $result->message);
        }

        return Response::make(Response::text($result->toWireArray()['content'][0]['text']))
            ->withStructuredContent((array) $result->structuredContent);
    }
}
