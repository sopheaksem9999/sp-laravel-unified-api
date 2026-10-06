<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use Closure;
use Exception;
use Sopheak\Core\Constants\RecordConstants;
use Sopheak\Core\Mcp\Guidance\ApiReference;
use Sopheak\Core\Mcp\Guidance\ColumnTypes;
use Sopheak\Core\Mcp\Guidance\EndpointContext;
use Sopheak\Core\Mcp\Guidance\IncludeGuide;
use Sopheak\Core\Mcp\Guidance\PayloadSchemaBuilder;
use Sopheak\Core\Mcp\Guidance\SchemaDeduper;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Types\RecordValidationType;
use Sopheak\Core\Utilities\DefaultValidationUtils;
use Sopheak\Core\Utilities\FilterOperatorCatalog;
use Sopheak\Core\Utilities\PermissionUtils;
use Illuminate\Support\Facades\Route;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

/**
 * The four schema discovery tools (`sp_api_*`): endpoint list, endpoint detail,
 * permissions and API guidance. Moved verbatim from McpServerService so both
 * MCP drivers serve identical content; they never return database rows.
 */
final class SchemaTools
{
    /**
     * @param array<string, mixed> $args
     */
    public function listEndpoints(array $args): array
    {
        SchemaRegistryUtils::refresh();
        $search = isset($args['search']) ? mb_strtolower((string) $args['search']) : null;
        $endpoints = [];

        $apiPrefix = RecordConfigService::apiPrefix();
        $rpcPrefix = trim(RecordConfigService::rpcPrefix(), '/');

        foreach (SchemaRegistryUtils::get() as $table => $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            $tableEndpoints = [];

            // List endpoint
            if ($config->canRead) {
                $tableEndpoints[] = [
                    'name' => $table,
                    'method' => ['GET'],
                    'uri' => '/' . $apiPrefix . '/' . $table,
                    'table' => $table,
                    'actions' => ['list'],
                    'isRead' => true,
                    'isWrite' => false,
                ];

                // Detail endpoints
                $detailMethods = ['GET'];
                if ($config->canUpdate) {
                    $detailMethods[] = 'PUT';
                    $detailMethods[] = 'PATCH';
                }

                if ($config->canDelete) {
                    $detailMethods[] = 'DELETE';
                }

                $rawActions = ['read'];
                if ($config->canUpdate) {
                    $rawActions[] = 'update';
                }

                if ($config->canDelete) {
                    $rawActions[] = 'delete';
                }

                $tableEndpoints[] = [
                    'name' => $table . '.detail',
                    'method' => $detailMethods,
                    'uri' => '/' . $apiPrefix . '/' . $table . '/{id}',
                    'table' => $table,
                    'actions' => $rawActions,
                    'isRead' => true,
                    'isWrite' => $config->canUpdate || $config->canDelete,
                ];
            }

            // Create endpoint
            if ($config->canCreate) {
                $tableEndpoints[] = [
                    'name' => $table . '.create',
                    'method' => ['POST'],
                    'uri' => '/' . $apiPrefix . '/' . $table,
                    'table' => $table,
                    'actions' => ['create'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Upsert endpoint
            if ($config->canUpsert ?? true) {
                $tableEndpoints[] = [
                    'name' => $table . '.upsert',
                    'method' => ['POST'],
                    'uri' => '/' . $apiPrefix . '/' . $table . '/upsert',
                    'table' => $table,
                    'actions' => ['upsert'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Restore endpoint (soft-deletable tables only)
            if ($config->canUpdate && ($config->softDeletes ?? false)) {
                $tableEndpoints[] = [
                    'name' => $table . '.restore',
                    'method' => ['POST'],
                    'uri' => '/' . $apiPrefix . '/' . $table . '/{id}/restore',
                    'table' => $table,
                    'actions' => ['restore'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Force delete endpoint
            if ($config->canDelete) {
                $tableEndpoints[] = [
                    'name' => $table . '.forceDelete',
                    'method' => ['DELETE'],
                    'uri' => '/' . $apiPrefix . '/' . $table . '/{id}/force',
                    'table' => $table,
                    'actions' => ['forceDelete'],
                    'isRead' => false,
                    'isWrite' => true,
                ];
            }

            // Bulk endpoints
            if (RecordConfigService::bulkOperationsEnabled()) {
                if ($config->canCreate) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkCreate',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/create',
                        'table' => $table,
                        'actions' => ['bulkCreate'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canUpdate) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkUpdate',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/update',
                        'table' => $table,
                        'actions' => ['bulkUpdate'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canDelete) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkDelete',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/delete',
                        'table' => $table,
                        'actions' => ['bulkDelete'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canUpsert ?? true) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulkUpsert',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk/upsert',
                        'table' => $table,
                        'actions' => ['bulkUpsert'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }

                if ($config->canCreate && $config->canUpdate && $config->canDelete) {
                    $tableEndpoints[] = [
                        'name' => $table . '.bulk',
                        'method' => ['POST'],
                        'uri' => '/' . $apiPrefix . '/' . $table . '/bulk',
                        'table' => $table,
                        'actions' => ['bulkMixed'],
                        'isRead' => false,
                        'isWrite' => true,
                    ];
                }
            }

            // Table RPC functions
            if (!empty($config->functions)) {
                foreach ($config->functions as $fnName => $fn) {
                    $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                    $methods = (array) ($fnConfig['httpMethod'] ?? 'GET');
                    $rpcUri = $rpcPrefix === ''
                        ? '/' . $apiPrefix . '/' . $table . '/' . $fnName
                        : '/' . $apiPrefix . '/' . $table . '/' . $rpcPrefix . '/' . $fnName;

                    $tableEndpoints[] = array_merge([
                        'name' => $table . '.' . $fnName,
                        'method' => $methods,
                        'uri' => $rpcUri,
                        'table' => $table,
                        'actions' => ['rpc'],
                        'permission' => $fnConfig['pmsName'] ?? null,
                        'isPublic' => $fnConfig['isPublic'] ?? false,
                    ], $this->functionCallContext($fnConfig, $config, (string) $table, (string) $fnName));
                }
            }

            // Filter by search
            if ($search !== null) {
                $tableEndpoints = array_values(array_filter($tableEndpoints, function (array $ep) use ($search, $table): bool {
                    if (str_contains(mb_strtolower((string) $ep['name']), $search)) {
                        return true;
                    }

                    if (str_contains(mb_strtolower((string) $ep['uri']), $search)) {
                        return true;
                    }

                    return str_contains(mb_strtolower($table), $search);
                }));
            }

            $endpoints = array_merge($endpoints, $tableEndpoints);
        }

        // Global RPC functions
        $globalFunctions = RecordConfigService::globalFunctions();
        foreach ($globalFunctions as $fnName => $fn) {
            $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
            $methods = (array) ($fnConfig['httpMethod'] ?? 'GET');
            $globalUri = $rpcPrefix === ''
                ? '/' . $apiPrefix . '/' . $fnName
                : '/' . $apiPrefix . '/' . $rpcPrefix . '/' . $fnName;

            $endpoints[] = array_merge([
                'name' => 'global.' . $fnName,
                'method' => $methods,
                'uri' => $globalUri,
                'table' => null,
                'actions' => ['rpc'],
                'permission' => $fnConfig['pmsName'] ?? null,
                'isPublic' => $fnConfig['isPublic'] ?? false,
            ], $this->functionCallContext($fnConfig));
        }

        return $this->withEndpointSummaries($endpoints);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, bool|string|mixed[]|null>
     */
    public function getEndpoint(array $args): array
    {
        $endpoint = $args['endpoint'] ?? null;
        if (!$endpoint) {
            throw new Exception('Missing required parameter: endpoint');
        }

        SchemaRegistryUtils::refresh();
        $config = SchemaRegistryUtils::getTable($endpoint);
        if (!$config instanceof RecordTableType || !($config instanceof RecordTableType)) {
            throw new Exception('Endpoint not found: ' . $endpoint);
        }

        $apiPrefix = RecordConfigService::apiPrefix();
        $rpcPrefix = trim(RecordConfigService::rpcPrefix(), '/');

        // Actions
        $actions = [];
        if ($config->canRead) {
            $actions['list'] = ['method' => 'GET', 'uri' => '/' . $apiPrefix . '/' . $config->table];
            $actions['read'] = ['method' => 'GET', 'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}'];
        }

        if ($config->canCreate) {
            $actions['create'] = ['method' => 'POST', 'uri' => '/' . $apiPrefix . '/' . $config->table];
        }

        if ($config->canUpdate) {
            $actions['update'] = ['method' => ['PUT', 'PATCH'], 'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}'];
        }

        if ($config->canDelete) {
            $actions['delete'] = ['method' => 'DELETE', 'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}'];
        }

        if ($config->canUpsert ?? true) {
            $actions['upsert'] = [
                'method' => 'POST',
                'uri' => '/' . $apiPrefix . '/' . $config->table . '/upsert',
                'note' => 'Requires a ?match_on=col1,col2 query parameter naming the columns to match an existing record on.',
            ];
        }

        if ($config->canUpdate && ($config->softDeletes ?? false)) {
            $actions['restore'] = [
                'method' => 'POST',
                'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}/restore',
                'note' => 'Restores a soft-deleted record.',
            ];
        }

        if ($config->canDelete) {
            $actions['forceDelete'] = [
                'method' => 'DELETE',
                'uri' => '/' . $apiPrefix . '/' . $config->table . '/{id}/force',
                'note' => 'Permanently deletes the record, bypassing soft deletes.',
            ];
        }

        if (RecordConfigService::bulkOperationsEnabled()) {
            $bulkMax = RecordConfigService::bulkMax();

            if ($config->canCreate) {
                $actions['bulkCreate'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/create',
                    'note' => sprintf('Body: a JSON array of records to create (max %d per request).', $bulkMax),
                ];
            }

            if ($config->canUpdate) {
                $actions['bulkUpdate'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/update',
                    'note' => sprintf('Body: a JSON array of records to update, each including its primary key (max %d per request).', $bulkMax),
                ];
            }

            if ($config->canDelete) {
                $actions['bulkDelete'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/delete',
                    'note' => sprintf('Body: a JSON array of records naming the primary key to delete (max %d per request).', $bulkMax),
                ];
            }

            if ($config->canUpsert ?? true) {
                $actions['bulkUpsert'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk/upsert',
                    'note' => sprintf('Body: a JSON array of records to upsert (max %d per request). Requires ?match_on=col1,col2.', $bulkMax),
                ];
            }

            if ($config->canCreate && $config->canUpdate && $config->canDelete) {
                $actions['bulkMixed'] = [
                    'method' => 'POST',
                    'uri' => '/' . $apiPrefix . '/' . $config->table . '/bulk',
                    'note' => sprintf("Body: a JSON array of records (max %d per request). Each item's operation (create/update/delete/upsert) ", $bulkMax)
                        . "is auto-detected from its shape, or set explicitly via an 'operation' field per item.",
                ];
            }
        }

        $actions = $this->onlyRequestedActions($actions, $args['actions'] ?? null);

        // Fields from column definitions
        $fields = [];
        if (!empty($config->columns)) {
            $hiddenCols = (array) ($config->columnHiddens ?? []);
            foreach ($config->columns as $colName => $colDef) {
                if (is_string($colDef)) {
                    $colDef = ['type' => $colDef];
                }

                $isHidden = in_array($colName, $hiddenCols, true);
                $isWriteDisabled = in_array($colName, (array) ($config->columnWriteDisabled ?? []), true);

                $in = [];
                if (!$isHidden) {
                    $in[] = 'read';
                }

                if (!$isWriteDisabled) {
                    $in[] = 'write';
                }

                $field = [
                    'name' => $colName,
                    'type' => $colDef['type'] ?? 'string',
                    'nullable' => !(($colDef['nullable'] ?? false) === false),
                    'in' => $in,
                ];
                if ($isHidden) {
                    $field['hidden'] = true;
                }

                if (isset($colDef['enum'])) {
                    $field['enum'] = $colDef['enum'];
                }

                if (!$isWriteDisabled && PayloadSchemaBuilder::writableColumn($config, (string) $colName, 'create') && DefaultValidationUtils::isRequiredColumn($colDef)) {
                    $field['required'] = true;
                }

                if (isset($colDef['maxLength'])) {
                    $field['maxLength'] = (int) $colDef['maxLength'];
                }

                $fields[] = $field;
            }
        }

        // Relationships / includes
        $includes = [];
        if (!empty($config->relationships)) {
            $guide = new IncludeGuide();
            foreach ($config->relationships as $relName => $rel) {
                $includes[] = $guide->describe((string) $endpoint, $config, (string) $relName, $rel);
            }
        }

        $actions = SchemaDeduper::actions($this->withActionContexts($actions, $fields, $config, $includes));

        // RPC functions
        $rpcFunctions = [];
        if (!empty($config->functions)) {
            foreach ($config->functions as $fnName => $fn) {
                $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                $methods = (array) ($fnConfig['httpMethod'] ?? 'GET');
                $rpcUri = $rpcPrefix === ''
                    ? '/' . $apiPrefix . '/' . $config->table . '/' . $fnName
                    : '/' . $apiPrefix . '/' . $config->table . '/' . $rpcPrefix . '/' . $fnName;

                $rf = [
                    'name' => $fnName,
                    'method' => $methods,
                    'uri' => $rpcUri,
                ];
                if (!empty($fnConfig['description'])) {
                    $rf['description'] = $fnConfig['description'];
                }

                $rf = array_merge($rf, $this->functionCallContext($fnConfig, $config, (string) $endpoint, (string) $fnName));

                $rpcFunctions[] = $rf;
            }
        }

        // Build filter list from columns (excluding hidden columns)
        $filters = [];
        if (!empty($config->columns)) {
            $hiddenCols = (array) ($config->columnHiddens ?? []);
            foreach ($config->columns as $colName => $colDef) {
                if (in_array($colName, $hiddenCols, true)) {
                    continue;
                }

                if (is_string($colDef)) {
                    $colDef = ['type' => $colDef];
                }

                $operators = FilterOperatorCatalog::forFamily(ColumnTypes::family((string) ($colDef['type'] ?? 'string')));
                if (!empty($operators)) {
                    $filters[] = ['field' => $colName, 'operators' => $operators];
                }
            }
        }

        // Sortable fields — all visible columns are sortable
        $hiddenCols = (array) ($config->columnHiddens ?? []);
        $allCols = array_keys((array) ($config->columns ?? []));
        $sorts = array_values(array_diff($allCols, $hiddenCols));
        if (empty($sorts)) {
            $sorts = [(string) ($config->primaryKey ?? 'id')];
        }

        // Permissions
        $perms = [];
        $permissionActions = [
            RecordConstants::READ => 'read',
            RecordConstants::WRITE => 'write',
            RecordConstants::ACTION_CREATE => 'create',
            RecordConstants::ACTION_UPDATE => 'update',
            RecordConstants::ACTION_DELETE => 'delete',
            'force_delete' => 'force_delete',
        ];
        foreach ($permissionActions as $action => $key) {
            try {
                $mapped = PermissionUtils::mapPermissions($config->table, $action);
                if (!empty($mapped)) {
                    $perms[$key] = $mapped;
                }
            } catch (Throwable) {
                // Skip if mapping fails
            }
        }

        // RPC function permissions
        if (!empty($config->functions)) {
            foreach ($config->functions as $fnName => $fn) {
                $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                $pmsName = $fnConfig['pmsName'] ?? null;
                if ($pmsName && !($fnConfig['isPublic'] ?? false)) {
                    $perms[$fnName] = (array) $pmsName;
                }
            }
        }

        // Validation info
        $validation = [];
        if ($config->createValidator !== null) {
            $validation['create'] = $this->describeValidator($config->createValidator);
        }

        if ($config->updateValidator !== null) {
            $validation['update'] = $this->describeValidator($config->updateValidator);
        }

        if ($config->deleteValidator !== null) {
            $validation['delete'] = $this->describeValidator($config->deleteValidator);
        }

        $validation['defaults'] = ['enabled' => RecordConfigService::defaultValidationEnabled()];

        // Scopes from searchable / column indexes
        $scopes = [];
        if (!empty($config->searchable)) {
            $scopes = $config->searchable;
        }

        return [
            'name' => $config->table,
            'table' => $config->table,
            'primaryKey' => $config->primaryKey ?? 'id',
            'softDeletes' => $config->softDeletes,
            'hasTenantId' => $config->hasTenantId,
            'isAuthRead' => $config->isAuthRead,
            'isAuthWrite' => $config->isAuthWrite,
            'authGuard' => RecordConfigService::authGuard(),
            'actions' => $actions,
            'fields' => $fields,
            'filters' => SchemaDeduper::filters($filters),
            'sorts' => $sorts,
            'includes' => $includes,
            'rpcFunctions' => $rpcFunctions,
            'permissions' => $perms,
            'validation' => $validation,
            'scopes' => $scopes,
        ];
    }

    public function listPermissions(): array
    {
        SchemaRegistryUtils::refresh();
        $permissions = [];
        $guard = RecordConfigService::authGuard();

        $permissionActions = [
            RecordConstants::READ,
            RecordConstants::WRITE,
            RecordConstants::ACTION_CREATE,
            RecordConstants::ACTION_UPDATE,
            RecordConstants::ACTION_DELETE,
            'force_delete',
        ];

        foreach (SchemaRegistryUtils::get() as $config) {
            if (!($config instanceof RecordTableType)) {
                continue;
            }

            foreach ($permissionActions as $action) {
                try {
                    $mapped = PermissionUtils::mapPermissions($config->table, $action);
                    foreach ($mapped as $perm) {
                        $permissions[] = [
                            'name' => $perm,
                            'guard' => $guard,
                            'table' => $config->table,
                        ];
                    }
                } catch (Throwable) {
                    // Skip if mapping fails
                }
            }

            // RPC function permissions
            if (!empty($config->functions)) {
                foreach ($config->functions as $fn) {
                    $fnConfig = $fn instanceof RecordFunctionType ? $fn->toArray() : (array) $fn;
                    $pmsName = $fnConfig['pmsName'] ?? null;
                    if ($pmsName && !($fnConfig['isPublic'] ?? false)) {
                        foreach ((array) $pmsName as $perm) {
                            $permissions[] = [
                                'name' => $perm,
                                'guard' => $guard,
                                'table' => $config->table,
                            ];
                        }
                    }
                }
            }
        }

        // Deduplicate
        $seen = [];
        $permissions = array_filter($permissions, function (array $p) use (&$seen): bool {
            $key = $p['name'] . '|' . $p['guard'];
            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        });

        return array_values($permissions);
    }

    /** @return array<string, mixed> */
    public function apiGuidance(): array
    {
        SchemaRegistryUtils::refresh();
        $apiPrefix = trim(RecordConfigService::apiPrefix(), '/');
        // Advertise the routes that exist. record.mcp.route_prefix never moved
        // a route (deprecated, no effect), so it must not shape these URLs.
        $dataRoute = Route::has('mcp.message') ? route('mcp.message', [], false) : '/' . $apiPrefix . '/mcp/message';
        $schemaRoute = Route::has('api_schema_mcp') ? route('api_schema_mcp', [], false) : '/' . $apiPrefix . '/mcp/schema';

        $guidance = [
            'dataMcp' => [
                'route' => $dataRoute,
                'purpose' => 'Use authorized CRUD tools to read or modify real database records.',
            ],
            'schemaMcp' => [
                'route' => $schemaRoute,
                'purpose' => 'Use schema discovery tools for API metadata only; this endpoint never returns database rows.',
            ],
            'workflow' => [
                'Call sp_api_list_endpoints to discover an endpoint.',
                'Call sp_api_get_endpoint before making an HTTP or Data MCP call.',
                'Use only the documented method, URI, parameters, and writeable fields.',
            ],
            'httpRules' => [
                'get' => 'Do not send a request body; send filters, selection, sorting, and pagination as query parameters. For optimal query performance, prefer "limit" over "per_page" when retrieving records without needing multi-page navigation.',
                'write' => 'Send only documented writeable fields in the JSON request body.',
                'response' => 'Successful package API responses use success, error_code, data, and meta.',
            ],
            'querySyntaxExamples' => [
                'description' => 'For GET HTTP requests and list_{table} queryParams. Do not use bracket syntax (filter[col]=val).',
                'fieldFiltering' => [
                    'syntax' => '{column: "operator.value"}',
                    'examples' => [
                        'status' => 'eq.active',
                        'total_amount' => 'gte.100',
                        'role' => 'in.admin,manager',
                        'name' => 'like.acme',
                        'deleted_at' => 'is.null',
                    ],
                ],
                'relationshipSelection' => [
                    'syntax' => 'select=col1,col2,relation(*),childRelation(id,name)',
                    'examples' => [
                        'select' => 'id,title,total_amount,items(*),customer(*)',
                    ],
                ],
                'paginationAndSorting' => [
                    'recordLimiting' => [
                        'syntax' => 'limit=N',
                        'recommendedForAgents' => true,
                        'explanation' => 'Use "limit" to fetch a fixed number of records (e.g. top 5, latest 10, search previews). This performs an ultra-fast query without computing expensive COUNT(*) pagination totals.',
                        'example' => ['limit' => 10, 'sortby' => 'created_at', 'order' => 'desc'],
                    ],
                    'pageBased' => [
                        'syntax' => 'page=N&per_page=M',
                        'explanation' => 'Use "per_page" with "page" ONLY when multi-page UI pagination is actively required. Computing total counts for per_page adds query overhead.',
                        'example' => ['page' => 1, 'per_page' => 25],
                    ],
                    'cursorBased' => [
                        'syntax' => 'cursor=TOKEN&per_page=N (first page: cursor= empty)',
                        'explanation' => 'Use cursor pagination for large-scale sequential traversal without offset degradation. Page with per_page — a limit parameter takes precedence and ignores the cursor. Pass meta.cursor back verbatim.',
                        'example' => ['cursor' => 'eyJpZCI6MTAwfQ==', 'direction' => 'next', 'per_page' => 25],
                    ],
                    'sorting' => ['sortby' => 'created_at', 'order' => 'desc'],
                ],
                'groupedLogic' => [
                    'syntax' => 'or=(condition1,condition2)',
                    'example' => ['or' => '(status.eq.pending,priority.eq.high)'],
                ],
            ],
            'nestedWriteExamples' => [
                'description' => 'In create_{table} and update_{table} payloads, writable relationships (hasMany, morphMany, belongsToMany, morphToMany, hasManyThrough) can be nested directly in the parent payload in a single atomic request.',
                'childCollections' => [
                    'explanation' => 'Pass an array under the relationship alias key. Include "id" to update, omit "id" to create, or add "_delete": true to delete.',
                    'example' => [
                        'title' => 'Invoice #1001',
                        'customer_id' => 42,
                        'items' => [
                            ['description' => 'Development services', 'quantity' => 10, 'unit_price' => 150.0],
                            ['id' => 105, 'quantity' => 12],
                            ['id' => 88, '_delete' => true],
                        ],
                    ],
                ],
                'manyToManyPivot' => [
                    'explanation' => 'Attach existing records by id (bare ids or {"id": N} objects). Links you leave out are kept; detach with {"id": N, "_delete": true}.',
                    'example' => [
                        'name' => 'Support Agent',
                        'roles' => [1, 3, 5],
                    ],
                ],
                'parentBelongsTo' => [
                    'explanation' => 'Always set the foreign key column on the parent record. Do not nest an object under the belongsTo relation alias.',
                    'example' => [
                        'customer_id' => 42,
                    ],
                ],
            ],
        ];

        return array_merge($guidance, (new ApiReference())->build());
    }

    /**
     * Keeps only the actions an agent asked for, so one call can stay small.
     *
     * @param array<string, array<string, mixed>> $actions
     * @return array<string, array<string, mixed>>
     */
    private function onlyRequestedActions(array $actions, mixed $requested): array
    {
        if (null === $requested) {
            return $actions;
        }

        if (!is_array($requested) || !array_is_list($requested) || array_filter($requested, static fn(mixed $name): bool => !is_string($name)) !== []) {
            throw new Exception('Invalid parameter: actions must be a list of action names, for example ["list", "create"]');
        }

        $unknown = array_values(array_diff($requested, array_keys($actions)));
        if ([] !== $unknown) {
            throw new Exception(sprintf('Unknown action(s): %s. Available: %s', implode(', ', $unknown), implode(', ', array_keys($actions))));
        }

        return array_intersect_key($actions, array_flip($requested));
    }

    /**
     * @param array<string, array<string, mixed>> $actions
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $includes
     * @return array<string, array<string, mixed>>
     */
    private function withActionContexts(array $actions, array $fields, RecordTableType $config, array $includes): array
    {
        $context = new EndpointContext();
        foreach ($actions as $action => $definition) {
            $bodyless = in_array($action, ['list', 'read', 'delete', 'forceDelete', 'restore'], true);
            $payload = $bodyless ? null : PayloadSchemaBuilder::forAction($config, $action, $fields, $includes);
            if (str_starts_with($action, 'bulk') && is_array($payload)) {
                $payload['maxItems'] = RecordConfigService::bulkMax();
            }

            $definition['request'] = [
                'pathParameters' => str_contains((string) $definition['uri'], '{id}') ? ['id'] : [],
                'queryParameters' => $this->queryParametersForAction($action, $config),
                'payload' => $payload,
            ];
            $definition['response'] = [
                'envelope' => ['success', 'error_code', 'data', 'meta'],
                'dataSchema' => $this->dataSchemaForAction($action, $fields),
            ];
            $definition['guidance'] = $bodyless
                ? 'Do not send a request body; send filters and selection as query parameters when the action supports them.'
                : 'Send only documented writeable fields in the JSON request body.';
            $headers = $context->headers($config, $action);
            if ([] !== $headers) {
                $definition['headers'] = $headers;
            }

            $throttle = $context->throttle($action);
            if (null !== $throttle) {
                $definition['rateLimit'] = $throttle;
            }

            if ('list' === $action) {
                $definition += $context->listExtras($config);
            } elseif (str_starts_with($action, 'bulk')) {
                $definition += $context->bulkExtras();
            }

            $actions[$action] = $definition;
        }

        return $actions;
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     * @return array<int, array<string, mixed>>
     */
    private function withEndpointSummaries(array $endpoints): array
    {
        foreach ($endpoints as $index => $endpoint) {
            if (isset($endpoint['request'], $endpoint['response'])) {
                continue;
            }

            $actions = $endpoint['actions'] ?? [];
            $bodyless = array_reduce(
                $actions,
                static fn(bool $carry, mixed $action): bool => $carry && in_array($action, ['list', 'read', 'delete', 'forceDelete'], true),
                true,
            );
            $isCollection = in_array('list', $actions, true);
            $endpoint['request'] = [
                'payload' => $bodyless ? null : [
                    'description' => 'Inspect sp_api_get_endpoint for the exact payload schema before calling.',
                ],
            ];
            $endpoint['response'] = [
                'summary' => $isCollection
                    ? 'Returns a response containing an array of matching records and pagination metadata.'
                    : 'Returns a response containing the action result and metadata.',
            ];
            $endpoint['guidance'] = $bodyless
                ? 'Do not send a request body. Inspect sp_api_get_endpoint for supported query parameters.'
                : 'Call sp_api_get_endpoint before sending a request body.';
            $endpoints[$index] = $endpoint;
        }

        return $endpoints;
    }

    /**
     * @param array<string, mixed> $function
     * @return array<string, mixed>
     */
    private function functionCallContext(array $function, ?RecordTableType $table = null, ?string $tableKey = null, ?string $fnName = null): array
    {
        $context = new EndpointContext();
        $payloadSchema = $function['payloadSchema'] ?? null;
        $querySchema = $function['querySchema'] ?? null;
        $responseSchema = $function['responseSchema'] ?? [
            'type' => 'object',
            'additionalProperties' => true,
            'description' => 'No response schema is configured for this custom RPC.',
        ];

        // The attachments module's view RPC resizes images on request when the app enables it.
        if ('sp_attachments' === $tableKey && '{id}/view' === $fnName) {
            $resizing = $context->resizingQuerySchema();
            if (null !== $resizing) {
                $querySchema = is_array($querySchema)
                    ? array_replace_recursive($querySchema, ['type' => 'object'], $resizing)
                    : $resizing;
            }
        }

        $hasBinary = false;
        foreach ((array) ($payloadSchema['properties'] ?? []) as $property) {
            if (is_array($property) && 'binary' === ($property['format'] ?? null)) {
                $hasBinary = true;
            }
        }

        $guidance = match (true) {
            null === $payloadSchema => 'No request body schema is configured; do not invent a payload. Check request.querySchema and the function description before calling.',
            $hasBinary => 'Send multipart/form-data: file fields are binary parts, every other field a form field. The fields are described in request.payload.',
            default => 'Send a JSON request body that conforms to request.payload.',
        };

        $call = [
            'request' => [
                'querySchema' => $querySchema,
                'payload' => $payloadSchema,
            ],
            'response' => [
                'dataSchema' => $responseSchema,
            ],
            'guidance' => $guidance,
        ];

        $headers = $context->rpcHeaders($function, $table);
        if ([] !== $headers) {
            $call['headers'] = $headers;
        }

        $throttle = $context->throttle('rpc');
        if (null !== $throttle) {
            $call['rateLimit'] = $throttle;
        }

        return $call;
    }

    /** @return array<int, string> */
    private function queryParametersForAction(string $action, RecordTableType $config): array
    {
        if (in_array($action, ['list', 'read'], true)) {
            $parameters = ['{column}={operator}.{value}', 'select', 'with', 'sortby', 'order', 'limit', 'per_page', 'page', 'cursor'];
            if ('list' === $action && !empty($config->searchable)) {
                $parameters[] = 'search';
            }

            return $config->softDeletes ? [...$parameters, 'with_trashed', 'only_trashed'] : $parameters;
        }

        return in_array($action, ['upsert', 'bulkUpsert'], true) ? ['match_on'] : [];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function dataSchemaForAction(string $action, array $fields): array
    {
        $recordSchema = $this->recordSchema($fields);

        if (in_array($action, ['list', 'bulkCreate', 'bulkUpdate', 'bulkDelete', 'bulkUpsert', 'bulkMixed'], true)) {
            return [
                'type' => 'array',
                'items' => $recordSchema,
            ];
        }

        return $recordSchema;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function recordSchema(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            if (!in_array('read', $field['in'] ?? [], true)) {
                continue;
            }

            $properties[(string) $field['name']] = ColumnTypes::jsonSchema((string) ($field['type'] ?? 'string'));
            if (isset($field['enum'])) {
                $properties[(string) $field['name']]['enum'] = $field['enum'];
            }
        }

        // No additionalProperties:false — a response also carries whatever
        // relationships the request selected (select=*,items(*)).
        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    private function describeValidator(mixed $validator): string
    {
        if ($validator === null) {
            return 'none';
        }

        if ($validator instanceof Closure) {
            return 'custom (Closure)';
        }

        if ($validator instanceof RecordValidationType) {
            return $validator->class . '@' . $validator->functionName;
        }

        if (is_array($validator) && count($validator) === 2) {
            $class = is_object($validator[0]) ? $validator[0]::class : $validator[0];

            return $class . '@' . $validator[1];
        }

        if (is_string($validator)) {
            return $validator;
        }

        return 'custom';
    }
}
