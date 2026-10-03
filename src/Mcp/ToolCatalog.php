<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Which MCP tools exist, independent of any transport. The `legacy` JSON-RPC
 * server and the `laravel` driver publish the same definitions. Descriptions
 * and schemas are moved verbatim from McpServerService::handleToolsList();
 * titles and annotations are the additive part (spec §6.1).
 */
final class ToolCatalog
{
    /**
     * @return array<int, ToolDefinition>
     */
    public function tools(bool $schemaOnly): array
    {
        return $schemaOnly ? $this->schema() : [...$this->schema(), ...$this->data()];
    }

    public function isReadOnly(): bool
    {
        return (bool) config('record.mcp.read_only', true);
    }

    /**
     * The four schema discovery tools — always available.
     *
     * @return array<int, ToolDefinition>
     */
    public function schema(): array
    {
        return [$this->define([
            'name' => 'sp_api_list_endpoints',
            'description' => 'List all API endpoints (tables + custom RPCs). Returns endpoint name, HTTP method, URI, table, and supported actions. Supports substring search.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Filter endpoints by name, table, or URI (case-insensitive substring match)',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->endpointsOutputSchema(),
        ], 'schema'), $this->define([
            'name' => 'sp_api_get_endpoint',
            'description' => 'Get full schema for a single endpoint: fields, filters, sorts, includes, relationships, validation rules, and auth requirements. '
                . 'Check includes[].writable/payloadHint before writing related data — relationships marked writable:true can be nested in the SAME create/update payload (one request) instead of a separate request per child table.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'endpoint' => [
                        'type' => 'string',
                        'description' => "Endpoint name from sp_api_list_endpoints, e.g. 'invoices'",
                    ],
                    'actions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Return only these actions (for example ["list", "create"]); omit to get every action. Fields, filters and includes are always returned.',
                    ],
                ],
                'required' => ['endpoint'],
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->endpointOutputSchema(),
        ], 'schema'), $this->define([
            'name' => 'sp_api_list_permissions',
            'description' => 'List all available permissions grouped by resource.',
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->permissionsOutputSchema(),
        ], 'schema'), $this->define([
            'name' => 'sp_api_get_api_guidance',
            'description' => 'Explain the Data MCP and Schema MCP roles, then guide an agent through discovering and safely calling a documented API endpoint.',
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'outputSchema' => $this->guidanceOutputSchema(),
        ], 'schema')];
    }

    /**
     * CRUD data-access tools; the write tools only when read-only mode is off.
     *
     * @return array<int, ToolDefinition>
     */
    public function data(?bool $readOnly = null): array
    {
        $tools = [];

        SchemaRegistryUtils::refresh();
        // null follows `record.mcp.read_only`; an in-process caller (the AI SDK
        // tools) passes false to list the write tools regardless of that MCP setting.
        $readOnly ??= (bool) config('record.mcp.read_only', true);

        foreach (SchemaRegistryUtils::get() as $table => $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            // A table's can* flags switch its HTTP routes off; a tool for an action the
            // table does not allow would bypass that, so it is neither offered nor run.
            if ($config->canRead) {
                $tools[] = $this->define([
                    'name' => 'list_' . $table,
                    'description' => 'List records from ' . $table,
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'queryParams' => [
                                'type' => 'object',
                                'description' => "Filters are {column: 'operator.value'} pairs, e.g. {\"status\": \"eq.open\", \"total_amount\": \"gte.100\"} "
                                    . '(see filters[] on sp_api_get_endpoint for the operators each field supports). '
                                    . "Supports 'limit' (RECOMMENDED for AI queries: fast top/recent record retrieval without COUNT overhead), 'select', 'sortby', 'order'. "
                                    . "Use 'per_page' and 'page' only when actively paginating across multiple pages. "
                                    . "Do not nest a filter under a 'filter' key or use bracket syntax like column[operator]=value — pass the column name directly as the queryParams key.",
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                    'outputSchema' => $this->dataToolOutputSchema(),
                ], 'list', $table);

                $tools[] = $this->define([
                    'name' => 'read_' . $table,
                    'description' => 'Read a single record from ' . $table,
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => ['string', 'integer']],
                            'queryParams' => [
                                'type' => 'object',
                                'description' => 'Query parameters (e.g., select)',
                                'additionalProperties' => true,
                            ],
                        ],
                        'required' => ['id'],
                    ],
                    'outputSchema' => $this->dataToolOutputSchema(),
                ], 'read', $table);
            }

            if (!$readOnly) {
                $relationshipHint = empty($config->relationships)
                    ? ''
                    : ' This table has relationships — call sp_api_get_endpoint first and check includes[].writable/payloadHint: '
                        . 'writable relations can be nested directly in payload to write parent + related rows in a single call, '
                        . 'instead of one request per table.';
                $unknownFieldHint = ' Every payload key must be a real column or relationship alias from sp_api_get_endpoint '
                    . '(fields[]/includes[]) — an invented or misspelled key is rejected with an error naming it, not silently dropped.';

                if ($config->canCreate) {
                    $tools[] = $this->define([
                        'name' => 'create_' . $table,
                        'description' => 'Create a new record in ' . $table . '.' . $relationshipHint . $unknownFieldHint,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'payload' => ['type' => 'object', 'additionalProperties' => true],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                            ],
                            'required' => ['payload'],
                        ],
                        'outputSchema' => $this->dataToolOutputSchema(),
                    ], 'create', $table);
                }

                if ($config->canUpdate) {
                    $tools[] = $this->define([
                        'name' => 'update_' . $table,
                        'description' => 'Update an existing record in ' . $table . '.' . $relationshipHint . $unknownFieldHint,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => ['string', 'integer']],
                                'payload' => ['type' => 'object', 'additionalProperties' => true],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                            ],
                            'required' => ['id', 'payload'],
                        ],
                        'outputSchema' => $this->dataToolOutputSchema(),
                    ], 'update', $table);
                }

                if ($config->canDelete) {
                    $tools[] = $this->define([
                        'name' => 'delete_' . $table,
                        'description' => 'Delete a record from ' . $table,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => ['string', 'integer']],
                                'queryParams' => ['type' => 'object', 'additionalProperties' => true],
                            ],
                            'required' => ['id'],
                        ],
                        'outputSchema' => $this->dataToolOutputSchema(),
                    ], 'delete', $table);
                }
            }
        }

        return $tools;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function resources(): array
    {
        SchemaRegistryUtils::refresh();
        $resources = [];

        foreach (SchemaRegistryUtils::get() as $table => $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            $resources[] = [
                'uri' => 'schema://' . $table,
                'name' => $table . ' Schema',
                'description' => 'Database schema and configuration for ' . $table,
                'mimeType' => 'application/json',
            ];
        }

        return $resources;
    }

    /**
     * @param array{name: string, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>} $legacy
     */
    private function define(array $legacy, string $action, ?string $table = null): ToolDefinition
    {
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];

        return new ToolDefinition(
            name: $legacy['name'],
            description: $legacy['description'],
            inputSchema: $legacy['inputSchema'],
            outputSchema: $legacy['outputSchema'],
            action: $action,
            table: $table,
            title: match ($action) {
                'list' => 'List ' . $table,
                'read' => 'Read ' . $table,
                'create' => 'Create ' . $table,
                'update' => 'Update ' . $table,
                'delete' => 'Delete ' . $table,
                default => $this->schemaTitle($legacy['name']),
            },
            annotations: match ($action) {
                'create' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
                'update', 'delete' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                default => $readOnly,
            },
        );
    }

    private function schemaTitle(string $name): string
    {
        return match ($name) {
            'sp_api_list_endpoints' => 'List API endpoints',
            'sp_api_get_endpoint' => 'Get API endpoint',
            'sp_api_list_permissions' => 'List API permissions',
            'sp_api_get_api_guidance' => 'Get API guidance',
        };
    }

    /** @return array<string, mixed> */
    private function endpointsOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoints' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['endpoints'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function endpointOutputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function permissionsOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'permissions' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['permissions'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function guidanceOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dataMcp' => ['type' => 'object'],
                'schemaMcp' => ['type' => 'object'],
                'workflow' => ['type' => 'array', 'items' => ['type' => 'string']],
                'httpRules' => ['type' => 'object'],
                'querySyntaxExamples' => ['type' => 'object'],
                'nestedWriteExamples' => ['type' => 'object'],
                'references' => ['type' => 'string'],
                'headers' => ['type' => 'object'],
                'querySyntax' => ['type' => 'object'],
                'operators' => ['type' => 'object'],
                'pagination' => ['type' => 'object'],
                'errors' => ['type' => 'object'],
                'rateLimits' => ['type' => 'object'],
                'nestedWrites' => ['type' => 'object'],
                'validation' => ['type' => 'object'],
                'docs' => ['type' => 'object'],
                'realtime' => ['type' => 'object'],
                'modules' => ['type' => 'object'],
                'recommendations' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['dataMcp', 'schemaMcp', 'workflow', 'httpRules', 'querySyntaxExamples', 'nestedWriteExamples'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function dataToolOutputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'response' => ['type' => 'object'],
            ],
            'required' => ['response'],
            'additionalProperties' => false,
        ];
    }
}
