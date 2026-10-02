<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use Exception;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Constants\RecordConstants;
use Sopheak\Core\Exceptions\NestedWriteRefusedException;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\NestedWriteAuthorizer;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Runs one MCP tool call, independent of any transport: tenant, then
 * authorization, then execution. The `legacy` JSON-RPC server and the
 * `laravel` driver both call it, so tenant isolation, permissions, viewOwn,
 * nested-write authorization and hidden-column stripping cannot differ between
 * them. Failures MCP reports as JSON-RPC errors are thrown as ToolError;
 * failures it reports as an `isError` tool result are returned.
 */
final readonly class ToolExecutor
{
    private SchemaTools $schemaTools;

    public function __construct(private bool $schemaOnly = false)
    {
        $this->schemaTools = new SchemaTools();
    }

    /**
     * @param array<string, mixed> $args
     * @throws ToolError
     */
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $args
     */
    public function call(string $name, array $args): ToolResult
    {

        // Schema discovery tools
        if (in_array($name, ['sp_api_list_endpoints', 'sp_api_get_endpoint', 'sp_api_list_permissions', 'sp_api_get_api_guidance'], true)) {
            try {
                $result = match ($name) {
                    'sp_api_list_endpoints' => $this->schemaTools->listEndpoints($args),
                    'sp_api_get_endpoint' => $this->schemaTools->getEndpoint($args),
                    'sp_api_list_permissions' => $this->schemaTools->listPermissions(),
                    'sp_api_get_api_guidance' => $this->schemaTools->apiGuidance(),
                };

                $structuredContent = match ($name) {
                    'sp_api_list_endpoints' => ['endpoints' => $result],
                    'sp_api_list_permissions' => ['permissions' => $result],
                    default => $result,
                };

                return ToolResult::ok($structuredContent, $result);
            } catch (Exception $exception) {
                return ToolResult::error($exception->getMessage());
            }
        }

        // Schema-only mode: reject any non-schema tool
        if ($this->schemaOnly) {
            throw new ToolError('Tool not found: ' . $name, -32601);
        }

        $parts = explode('_', $name, 2);
        if (count($parts) !== 2) {
            throw new ToolError('Tool not found: ' . $name, -32601);
        }

        $action = $parts[0];
        $table = $parts[1];

        $readOnly = config('record.mcp.read_only', true);
        if ($readOnly && in_array($action, ['create', 'update', 'delete'])) {
            throw new ToolError('Tool not found or read-only mode is enabled: ' . $name, -32601);
        }

        $validActions = ['list', 'read', 'create', 'update', 'delete'];
        if (!in_array($action, $validActions)) {
            throw new ToolError('Tool not found: ' . $name, -32601);
        }

        // The table's can* flags switch its HTTP routes off (404); the same action over
        // MCP must be refused too. An unknown table falls through to authorizeAction().
        $tableConfig = SchemaRegistryUtils::getTable($table);
        if ($tableConfig instanceof RecordTableType && !match ($action) {
            'list', 'read' => $tableConfig->canRead,
            'create' => $tableConfig->canCreate,
            'update' => $tableConfig->canUpdate,
            'delete' => $tableConfig->canDelete,
        }) {
            throw new ToolError('Tool not found: ' . $name, -32601);
        }

        $authAction = match ($action) {
            'list', 'read' => RecordConstants::READ,
            'create' => RecordConstants::ACTION_CREATE,
            'update' => RecordConstants::ACTION_UPDATE,
            'delete' => RecordConstants::ACTION_DELETE,
        };
        $this->authorizeAction($table, $authAction);

        $id = $args['id'] ?? null;
        $payload = $args['payload'] ?? [];
        $queryParams = $args['queryParams'] ?? [];
        $tenantId = $this->resolveToolTenantId($table, $args);

        try {
            $result = match ($action) {
                'list' => RecordService::executeGetByFilter($table, $queryParams, $tenantId, true, 'id'),
                'read' => RecordService::executeGetById($table, $id, $queryParams, $tenantId),
                'create' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeCreate($table, $payload, $queryParams, $tenantId))),
                'update' => NestedWriteAuthorizer::enforce(fn (): array => DB::transaction(fn (): array => RecordService::executeUpdate($table, $id, $payload, $queryParams, $tenantId))),
                'delete' => RecordService::executeDelete($table, $id, $queryParams, $tenantId),
            };

            if (isset($result['data'])) {
                $tableSchema = SchemaRegistryUtils::getTable($table);
                $result['data'] = RecordService::stripHiddenColumns($result['data'], $tableSchema);
            }

            return $this->dataToolResult($result);
        } catch (NestedWriteRefusedException $refused) {
            // Same contract as a refused parent (authorizeAction()): a
            // JSON-RPC error, not an isError tool result.
            throw PermissionUtils::DECISION_UNAUTHENTICATED === $refused->decision
                ? new ToolError('Unauthenticated', -32001)
                : new ToolError('Forbidden', -32002);
        } catch (Exception $exception) {
            return ToolResult::error($exception->getMessage());
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     * @throws ToolError
     */
    /**
     * @param array<string, mixed> $params
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function readResource(string $uri): array
    {
        if (!str_starts_with($uri, 'schema://')) {
            throw new ToolError('Invalid resource URI: ' . $uri);
        }

        $table = substr($uri, 9); // Remove 'schema://'
        SchemaRegistryUtils::refresh();
        $config = SchemaRegistryUtils::get()[$table] ?? null;

        if (!$config || !($config instanceof RecordTableType)) {
            throw new ToolError('Resource not found: ' . $uri);
        }

        $schemaData = [
            'table' => $table,
            'primaryKey' => $config->primaryKey,
            'softDeletes' => $config->softDeletes,
            'hasTenantId' => $config->hasTenantId,
            'isAuthRead' => $config->isAuthRead,
            'isAuthWrite' => $config->isAuthWrite,
            'canRead' => $config->canRead,
            'canCreate' => $config->canCreate,
            'canUpdate' => $config->canUpdate,
            'canDelete' => $config->canDelete,
            'canUpsert' => $config->canUpsert,
        ];

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => json_encode($schemaData, JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }

    /**
     * Resolve the tenant this tool call is allowed to touch.
     *
     * The tenant is taken from the request — the same authority the HTTP
     * controllers use — and never from the tool arguments. A model can put any
     * value in `arguments`, so trusting `tenantId` there let one company's
     * client read and write another company's rows simply by naming their id,
     * and omitting it disabled scoping altogether.
     *
     * A `tenantId` argument is therefore only an assertion: it must agree with
     * the request, or the call is refused. When a tenant-scoped table resolves
     * no tenant at all, the call is refused rather than silently widened to
     * every tenant.
     *
     * The resolved tenant is returned even for a table that is not itself
     * tenant-scoped. Such a table is still a pivot into tenant-scoped children:
     * `RecordService::executeGetByFilter()` only stamps `resolved_tenant_id`
     * onto its synthetic Request when a tenant is passed, and without it
     * `resolveRelationshipTenantId()` falls through to `input('tenant_id')` —
     * which on that synthetic request is the model's own `queryParams`. Passing
     * the tenant through closes that path; it cannot affect the parent's own
     * query, which is gated on `shouldApplyTenantId()` separately.
     *
     * @param array<string, mixed> $args
     * @throws Exception
     */
    private function resolveToolTenantId(string $table, array $args): mixed
    {
        if (!RecordConfigService::enableTenantId()) {
            return null;
        }

        $tableSchema = SchemaRegistryUtils::getTable($table);
        if (!$tableSchema instanceof RecordTableType) {
            // "I cannot identify this table" is not the same as "this table has
            // no tenancy"; only the latter may proceed without a tenant.
            throw new ToolError('Unknown table: ' . $table, -32001);
        }

        $resolved = RecordUtils::resolveTenantIdFromRequest(request());

        if (array_key_exists('tenantId', $args) && !RecordUtils::isTenantIdMissing($args['tenantId'])) {
            $claimed = $args['tenantId'];

            if (!is_scalar($claimed)) {
                throw new ToolError('The tenantId argument must be a scalar value.', -32001);
            }

            // Loose comparison: a header is always a string, an argument may be
            // an int, and "1" and 1 name the same tenant.
            if (
                RecordUtils::isTenantIdMissing($resolved)
                || (string) RecordUtils::normalizeTenantId($claimed) !== (string) RecordUtils::normalizeTenantId($resolved)
            ) {
                throw new ToolError('The tenantId argument does not match the authenticated tenant context.', -32001);
            }
        }

        if (RecordUtils::isTenantIdMissing($resolved)) {
            if (RecordUtils::shouldApplyTenantId($tableSchema)) {
                throw new ToolError('Tenant context is required for ' . $table . ' but none was resolved from the request.', -32001);
            }

            return null;
        }

        return $resolved;
    }

    private function authorizeAction(string $table, string $action): void
    {
        // Same decision as HasControllerHelpers::authorizeAction(), mapped to
        // the MCP error codes.
        $decision = PermissionUtils::actionDecision(auth(RecordConfigService::authGuard())->user(), $table, $action);

        if (PermissionUtils::DECISION_UNAUTHENTICATED === $decision) {
            throw new ToolError('Unauthenticated', -32001);
        }

        if (PermissionUtils::DECISION_FORBIDDEN === $decision) {
            throw new ToolError('Forbidden', -32002);
        }
    }

    /**
     * RecordService returns the current Laravel request for internal processing.
     * It is not part of an API result and cannot be represented as MCP JSON.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dataToolResult(array $result): ToolResult
    {
        unset($result['request']);

        return ToolResult::ok(['response' => $result], $result);
    }
}
