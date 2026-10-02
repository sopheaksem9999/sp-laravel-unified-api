<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Guidance;

use Illuminate\Support\Facades\Route;
use Sopheak\Core\Constants\HttpErrorCodeConstant;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\FilterOperatorCatalog;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The reference material every agent needs once, not per endpoint: the headers,
 * the query language, the operator catalogue, paging, errors, rate limits,
 * nested-write rules, the docs fallback, realtime events and performance
 * advice. Everything is read from live config, routes and the registry, so a
 * renamed header or a changed limit changes the guidance with it.
 */
final readonly class ApiReference
{
    private EndpointContext $context;

    public function __construct()
    {
        $this->context = new EndpointContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?string $driver = null): array
    {
        $driver ??= FilterOperatorCatalog::currentDriver();
        $reference = [
            'references' => 'A {"$ref": "#/path"} inside an sp_api_get_endpoint result stands for an identical schema written out once elsewhere in that result (for example #/actions/list/response/dataSchema/items); follow the JSON pointer to read it.',
            'headers' => $this->headers(),
            'querySyntax' => $this->querySyntax(),
            'operators' => $this->operators($driver),
            'pagination' => $this->pagination(),
            'errors' => $this->errors(),
            'rateLimits' => $this->rateLimits(),
            'nestedWrites' => $this->nestedWrites(),
            'validation' => [
                'defaults' => "When an endpoint's validation.defaults.enabled is true, column-derived rules (type, required, unique, foreign-key existence) are enforced even with no custom validator; see fields[].required and maxLength. When it is false only custom validators run, and the database constraints still apply.",
            ],
        ];

        $docs = $this->docs();
        if (null !== $docs) {
            $reference['docs'] = $docs;
        }

        if (RecordConfigService::broadcastEventsEnabled()) {
            $reference['realtime'] = $this->realtime();
        }

        // Left out when no module is enabled: an empty PHP array would reach a
        // client as `[]`, where the output schema declares an object.
        $modules = (new ModuleRecipes())->build();
        if ([] !== $modules) {
            $reference['modules'] = $modules;
        }

        $reference['recommendations'] = $this->recommendations($driver);

        return $reference;
    }

    /**
     * @return array<string, mixed>
     */
    private function operators(string $driver): array
    {
        $catalogue = FilterOperatorCatalog::catalogue($driver);
        $negatable = FilterOperatorCatalog::negatable($driver);
        $names = array_column($catalogue, 'name');

        return [
            'driver' => $driver,
            'negation' => 'Prefix an operator listed under negatable with "not." (not.eq.5, not.in.(1,2)) to negate it. An operator under notNegatable has no negated form, and a not. prefix on it is ignored by the API, so the filter would silently not apply: do not use it.',
            'negatable' => $negatable,
            'notNegatable' => array_values(array_diff($names, $negatable)),
            'catalogue' => $catalogue,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Authorization' => 'Bearer <token> — send it wherever an action\'s headers block lists it (no headers block: nothing to send)',
        ];
        if (RecordConfigService::enableTenantId()) {
            $headers[RecordConfigService::tenantHeader()] = '<tenant id> — required on tenant-scoped tables (their headers block lists it); without it the API answers 422';
        }

        $headers['Accept'] = 'application/json';
        $headers['Content-Type'] = 'application/json (multipart/form-data for file uploads)';

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function querySyntax(): array
    {
        $syntax = [
            'filters' => 'One query key per column: {column}={operator}.{value}, for example status=eq.open. Never wrap filters in filter[...].',
            'relationshipFilters' => ['items.qty=gt.1', 'customer.name=ilike.acme'],
            'negation' => ['not.eq.5', 'not.in.(1,2,3)', 'not.like.ACME'],
            'modifiers' => ['name=like(any).{ACME,SHOP}', 'name=ilike(all).{spx,admin}'],
            'grouped' => 'or=(status.eq.open,total.gt.100) and and=(...); inside a group write {column}.{operator}.{value} and never use = or &.',
            'substring' => 'like and ilike already match substrings — do not add %: name=like.acme.',
            'lists' => 'in.(a,b,c) or in.a,b,c',
            'ranges' => 'between.1,10 (inclusive)',
            'nulls' => 'is.null and is_not.null',
            'selectVsWith' => 'select chooses the columns and includes (select=id,title,items(*)); with adds includes to the default columns (with=items).',
        ];

        $searchable = false;
        $softDeletes = false;
        foreach (SchemaRegistryUtils::get() as $config) {
            if ($config instanceof RecordTableType) {
                $searchable = $searchable || !empty($config->searchable);
                $softDeletes = $softDeletes || $config->softDeletes;
            }
        }

        if ($searchable) {
            $syntax['search'] = "search=<text> searches the columns in the list action's search.columns (tables without it do not support search).";
        }

        if ($softDeletes) {
            $syntax['trashed'] = 'On soft-delete tables deleted rows are hidden; add with_trashed=true to include them or only_trashed=true for just those.';
        }

        return $syntax;
    }

    /**
     * @return array<string, mixed>
     */
    private function pagination(): array
    {
        return [
            'defaultMode' => RecordConfigService::paginationDefaultMode(),
            'limit' => ['syntax' => 'limit=N', 'max' => RecordConfigService::limitMax(), 'note' => 'No count query; use it for top-N and previews.'],
            'page' => ['syntax' => 'page=N&per_page=M', 'max' => RecordConfigService::perPageMax(), 'note' => 'Returns meta.page, meta.per_page and meta.total.'],
            'cursor' => [
                'syntax' => 'cursor=&direction=next&per_page=M (first page: empty cursor)',
                'note' => 'Pass meta.cursor back verbatim as an opaque token. sortby chooses the paged column (meta.cursor_column); cursor_column overrides it. direction is next or prev.',
            ],
            'totals' => [
                'skip' => 'skip_total=true or total=false leaves out the COUNT query',
                'add' => 'add_total=true or total=true asks for it',
                'skipTotalDefault' => RecordConfigService::skipTotalDefault(),
            ],
            'boundaryCursors' => RecordConfigService::cursorBoundaryEnabled()
                ? 'Cursor responses include meta.last_cursor; send boundary_cursors=false to skip that extra query.'
                : 'meta.last_cursor is not computed (boundary cursors are off).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errors(): array
    {
        $rows = [
            ['status' => 401, 'error_code' => HttpErrorCodeConstant::INVALID_ACCESS, 'name' => 'INVALID_ACCESS', 'when' => 'No valid bearer token on a non-public endpoint.'],
            ['status' => 403, 'error_code' => HttpErrorCodeConstant::PERMISSION_DENIED, 'name' => 'PERMISSION_DENIED', 'when' => 'Authenticated, but the user lacks the permission for this action (see sp_api_get_endpoint permissions).'],
            ['status' => 404, 'error_code' => HttpErrorCodeConstant::RESOURCE_NOT_FOUND, 'name' => 'RESOURCE_NOT_FOUND', 'when' => 'No such endpoint or record — including a record that exists but is outside your tenant, own-records or soft-delete scope.'],
            ['status' => 422, 'error_code' => HttpErrorCodeConstant::INVALID_REQUEST, 'name' => 'INVALID_REQUEST', 'when' => 'Validation failed. The body carries errors: {field: [messages]}.'],
        ];
        if (RecordConfigService::enableTenantId()) {
            // A missing tenant header is answered as a validation failure whose errors name the header.
            $rows[] = ['status' => 422, 'error_code' => HttpErrorCodeConstant::INVALID_REQUEST, 'name' => 'TENANT_HEADER_MISSING', 'when' => sprintf('The %s header is missing on a tenant-scoped table; errors names the header.', RecordConfigService::tenantHeader())];
        }

        $rows[] = ['status' => 429, 'error_code' => null, 'name' => 'THROTTLED', 'when' => 'Too many requests for the rate-limit group; wait the Retry-After header seconds.'];

        return [
            'http' => $rows,
            'envelope' => 'Failures use {success: false, error_code, message, errors, meta}; branch on error_code, not on message.',
            'mcp' => [
                '-32001' => 'Unauthenticated, or an unknown or unauthorised table for this caller',
                '-32002' => 'Forbidden: the caller lacks the permission for this action',
                '-32601' => 'Tool not found (including write tools while record.mcp.read_only is on)',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rateLimits(): array
    {
        $groups = [];
        foreach (['api-reads' => 'list', 'api-writes' => 'create', 'api-functions' => 'rpc'] as $group => $action) {
            $groups[$group] = $this->context->throttle($action) ?? ['group' => $group];
        }

        return $groups + ['onThrottle' => 'On 429 wait Retry-After seconds, then retry. Batch instead of looping.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedWrites(): array
    {
        return [
            'rules' => [
                '"_delete": true needs the related row\'s primary key ("id" unless its table names another; includes[].payloadHint spells it) to delete or detach it.',
                'The parent and every child item are written in one transaction: any failure rolls everything back.',
                "Each child item needs the child table's own create, update or delete permission (the permissions of includes[].table, as sp_api_get_endpoint reports them). Attaching an existing row needs only the parent's permission.",
                'canCreate, canUpdate or canDelete set to false on the child table, or allowCreate/allowUpdate/allowDelete on the relationship, refuse that kind of item with 422.',
                'Attaching a record the caller cannot read (other tenant, outside own-records scope) is a 422.',
                'Nested arrays are written by create and update (single and bulk). The upsert endpoints ignore them: write the children with a separate create or update call.',
                'A bare id attaches in belongsToMany, morphToMany and hasManyThrough arrays; in hasMany and morphMany arrays it is a 422, and so is an empty value.',
                'Nested child changes are not recorded in the audit log.',
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function docs(): ?array
    {
        if ((bool) config('record.api_docs.is_private', false)) {
            return null;
        }

        $uris = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uris[$route->uri()] = true;
        }

        $prefix = trim(RecordConfigService::apiPrefix(), '/');
        $openapi = $prefix . '/docs/openapi.json';
        $llms = $prefix . '/docs/llms.txt';
        if (!isset($uris[$openapi]) || !isset($uris[$llms])) {
            return null;
        }

        return [
            'openapi' => '/' . $openapi,
            'llms' => '/' . $llms,
            'use' => 'Fallback for anything the tools do not cover: the OpenAPI document and an LLM-oriented summary of the same API.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function realtime(): array
    {
        $tables = RecordConfigService::broadcastTables();

        return [
            'channel' => 'private-tenant.{tenantId}',
            'listen' => "Echo.private('tenant.42').listen('.invoices.created', callback)",
            'noTenant' => 'tenant.global',
            'event' => '{table}.{action} where action is created, updated, deleted or restored; the payload is {table, action, record, tenant_id, timestamp}',
            'tables' => [] === $tables ? 'all' : array_values($tables),
            'auth' => "The client needs the application's broadcasting auth (a private channel authorisation for tenant.{tenantId}).",
        ];
    }

    /**
     * @return array<int, array{rule: string, reason: string}>
     */
    private function recommendations(string $driver): array
    {
        $queued = 'sync' !== config('queue.default', 'sync');
        $items = [
            ['rule' => 'Select only the columns you need (select=id,title,total); avoid select=* on wide tables.', 'reason' => 'Fewer columns means less to read, serialise and send.'],
            ['rule' => 'Keep includes to what you need and nesting to two levels or fewer; filter on related data with relationship filters (items.qty=gt.1) instead of including and filtering yourself.', 'reason' => 'Each include adds queries, and a nested include switches the loader to batched queries.'],
            ['rule' => sprintf('Use limit=N (max %d) for top-N, latest-N and previews.', RecordConfigService::limitMax()), 'reason' => 'limit skips the count query.'],
            ['rule' => 'Add skip_total=true (or total=false) when you paginate and do not need the total.', 'reason' => 'The total is a separate COUNT(*) over everything that matches.'],
            ['rule' => 'Use cursor paging (cursor=&direction=next) for deep or sequential reads instead of high page numbers.', 'reason' => 'Offset paging slows down as the page number grows; cursor paging does not.'],
        ];

        if (RecordConfigService::bulkOperationsEnabled()) {
            $items[] = [
                'rule' => sprintf('Use the bulk endpoints for more than one write: up to %d items per request, in one transaction%s.', RecordConfigService::bulkMax(), $queued ? ', and add async=true for large batches' : ''),
                'reason' => 'One request replaces many round trips and stays inside the rate limits.',
            ];
        }

        $items[] = ['rule' => 'Use upsert with match_on=col1,col2 instead of reading a record and then creating or updating it.', 'reason' => 'One request replaces a read and a write, and it is race-free.'];

        if ('pgsql' === $driver) {
            $items[] = ['rule' => "Prefer fts (or a table's search parameter) over like or contains on large text columns.", 'reason' => 'Full-text search can use an index; a substring match scans the column.'];
        }

        $limits = [];
        foreach ($this->rateLimits() as $group => $throttle) {
            if (is_array($throttle) && isset($throttle['limit'])) {
                $limits[] = sprintf('%s %d per %d s', $group, $throttle['limit'], $throttle['perSeconds'] ?? 60);
            }
        }

        $items[] = [
            'rule' => 'Stay within the read, write and function limits' . ([] === $limits ? '' : ' (' . implode(', ', $limits) . ')') . '; batch instead of looping, and back off on 429.',
            'reason' => 'Requests over the limit are rejected until the window resets.',
        ];

        if (RecordConfigService::cacheEnabled()) {
            $items[] = ['rule' => 'Do not add cache-busting parameters to reads.', 'reason' => 'Reads may be served from the cache, and writes clear it automatically.'];
        }

        return $items;
    }
}
