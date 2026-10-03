<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\DefaultValidationUtils;
use Sopheak\Core\Utilities\NestedWriteAuthorizer;
use Sopheak\Core\Utilities\RecordUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Sopheak\Core\Utilities\TableValidatorRunner;
use Sopheak\Core\Utilities\TenantScopedIncludes;
use Throwable;

/**
 * Runs a data tool call through the record hooks and validators of the HTTP
 * API: global and table before* hooks, the table's validators and column
 * default validation, the write, then the after* hooks (and the webhooks
 * delivered through them) and the RecordMutated broadcast — in the order the
 * CRUD controller runs them.
 *
 * The data still comes from RecordService::execute*, so a tool's result, its
 * audit row (RecordCreated / RecordUpdated / RecordDeleted) and its cache
 * invalidation are unchanged. Hooks receive a Request built for the call: the
 * payload as its JSON body, queryParams as its query string, the current
 * request's client details and user, and the resolved tenant.
 */
final readonly class RecordHookPipeline
{
    private const CLIENT_SERVER_KEYS = ['REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];

    public function __construct(private RecordService $records) {}

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function list(string $table, array $queryParams, mixed $tenantId): array
    {
        $context = ['type' => 'index', RecordConfigService::tenantColumn() => $tenantId];
        $request = $this->before('beforeRead', $this->schema($table)->beforeRead ?? null, $table, $this->request('GET', $queryParams, [], $tenantId), $context);
        $query = $request->query->all();
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        $result = RecordService::executeGetByFilter($table, $query, $tenantId, true, 'id');
        $data = RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table));
        $meta = (array) ($result['meta'] ?? []);

        $this->afterRead($table, $request, $context + [
            'filters' => $result['filters'] ?? [],
            'data' => $data,
            'meta' => $meta,
            'response' => RecordApiResponseService::successWrapped($data, $meta),
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function read(string $table, mixed $id, array $queryParams, mixed $tenantId): array
    {
        $context = ['type' => 'show', 'id' => $id, RecordConfigService::tenantColumn() => $tenantId];
        $request = $this->before('beforeRead', $this->schema($table)->beforeRead ?? null, $table, $this->request('GET', $queryParams, [], $tenantId), $context);
        $query = $request->query->all();
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        $result = RecordService::executeGetById($table, $id, $query, $tenantId);
        $record = RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table));

        if (!empty($record)) {
            $this->afterRead($table, $request, $context + ['record' => $record, 'response' => RecordApiResponseService::successWrapped($record)]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function create(string $table, array $payload, array $queryParams, mixed $tenantId): array
    {
        $schema = $this->schema($table);
        $tenantColumn = RecordConfigService::tenantColumn();
        $request = $this->before('beforeCreate', $schema->beforeCreate ?? null, $table, $this->request('POST', $queryParams, $payload, $tenantId), [$tenantColumn => $tenantId]);

        $this->validate($schema->createValidator, $request, null);
        if (RecordConfigService::defaultValidationEnabled() && (!RecordConfigService::defaultValidationOnlyWhenMissing() || null === $schema->createValidator)) {
            $this->validateRules(DefaultValidationUtils::buildCreateRules($schema), $request);
        }

        [$payload, $query] = $this->payloadAndQuery($request);
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        return NestedWriteAuthorizer::enforce(fn(): array => DB::transaction(function () use ($table, $payload, $query, $tenantId, $tenantColumn, $request): array {
            $outcome = null;
            $result = RecordService::executeCreate($table, $payload, $query, $tenantId, $outcome);

            $this->hook(fn() => $this->records->processAfterWriteHooks($request, $table, 'create', [
                'id' => $outcome['id'] ?? null,
                'payload' => $outcome['payload'] ?? $payload,
                $tenantColumn => $outcome[$tenantColumn] ?? $tenantId,
                'response' => RecordApiResponseService::successWrapped(RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table))),
            ]));

            return $result;
        }));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function update(string $table, mixed $id, array $payload, array $queryParams, mixed $tenantId): array
    {
        $schema = $this->schema($table);
        $tenantColumn = RecordConfigService::tenantColumn();
        $request = $this->before('beforeUpdate', $schema->beforeUpdate ?? null, $table, $this->request('PUT', $queryParams, $payload, $tenantId), ['id' => $id, $tenantColumn => $tenantId]);

        $this->validate($schema->updateValidator, $request, (string) $id);
        if (RecordConfigService::defaultValidationEnabled() && (!RecordConfigService::defaultValidationOnlyWhenMissing() || null === $schema->updateValidator)) {
            $this->validateRules(DefaultValidationUtils::buildUpdateRules($schema, $id), $request);
        }

        [$payload, $query] = $this->payloadAndQuery($request);
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        return NestedWriteAuthorizer::enforce(fn(): array => DB::transaction(function () use ($table, $id, $payload, $query, $tenantId, $tenantColumn, $request): array {
            $outcome = null;
            $result = RecordService::executeUpdate($table, $id, $payload, $query, $tenantId, $outcome);

            if (!empty($outcome['exists'])) {
                $this->hook(fn() => $this->records->processAfterWriteHooks($request, $table, 'update', [
                    'id' => $id,
                    'payload' => $outcome['payload'] ?? $payload,
                    $tenantColumn => $outcome[$tenantColumn] ?? $tenantId,
                    'updated' => $outcome['updated'] ?? false,
                    'response' => RecordApiResponseService::successWrapped(RecordService::stripHiddenColumns($result['data'] ?? [], $this->schema($table))),
                ]));
            }

            return $result;
        }));
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function delete(string $table, mixed $id, array $queryParams, mixed $tenantId): array
    {
        $schema = $this->schema($table);
        $tenantColumn = RecordConfigService::tenantColumn();
        $record = $this->records->fetchRawRecord($table, $id, $tenantId);
        $request = $this->before('beforeDelete', $schema->beforeDelete ?? null, $table, $this->request('DELETE', $queryParams, [], $tenantId), ['id' => $id, $tenantColumn => $tenantId, 'record' => $record]);

        $this->validate($schema->deleteValidator, $request, (string) $id);
        $query = $request->query->all();
        $this->refuseTenantScopedIncludes($table, $query, $tenantId);

        return NestedWriteAuthorizer::enforce(fn(): array => DB::transaction(function () use ($table, $id, $query, $tenantId, $tenantColumn, $request, $record, $schema): array {
            $outcome = null;
            $result = RecordService::executeDelete($table, $id, $query, $tenantId, $outcome);
            $affected = (int) ($outcome['affected'] ?? 0);

            if ($affected > 0) {
                $this->hook(fn() => $this->records->processAfterWriteHooks($request, $table, 'delete', [
                    'id' => $id,
                    $tenantColumn => $tenantId,
                    'affected' => $affected,
                    'soft_deleted' => $schema->softDeletes,
                    'record' => $record,
                    'response' => RecordApiResponseService::successWrapped(['deleted' => $affected]),
                ]));
            }

            return $result;
        }));
    }

    private function schema(string $table): RecordTableType
    {
        $schema = SchemaRegistryUtils::getTable($table);
        if (!$schema instanceof RecordTableType) {
            throw new ToolError('Unknown table: ' . $table, -32001);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $payload
     */
    private function request(string $method, array $queryParams, array $payload, mixed $tenantId): Request
    {
        $current = request();
        $isRead = 'GET' === $method;
        $server = array_intersect_key($current->server->all(), array_flip(self::CLIENT_SERVER_KEYS));
        $server['HTTP_ACCEPT'] = 'application/json';
        if (!$isRead) {
            // A JSON body, as an HTTP client sends it. Not on a read: there, a hook's
            // $request->merge() must land in the query string, as it does over HTTP.
            $server['CONTENT_TYPE'] = 'application/json';
        }

        // The query is set directly, not through the URI: parsing a URI query turns
        // `rel.column` keys into `rel_column`. A payload that cannot be encoded is
        // refused (JsonException) rather than sent as an empty body.
        $request = Request::create('/', $method, [], [], [], $server, $isRead ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        $request->query->replace($queryParams);

        $queryString = http_build_query($queryParams);
        $request->server->set('QUERY_STRING', $queryString);
        $request->server->set('REQUEST_URI', '/' . ('' === $queryString ? '' : '?' . $queryString));

        // Hooks call $request->user(): the user this call runs as.
        $request->setUserResolver(static fn(?string $guard = null): mixed => null === $guard
            ? (auth(RecordConfigService::authGuard())->user() ?? auth()->user())
            : auth($guard)->user());

        if ($current->attributes->has('request_id')) {
            $request->attributes->set('request_id', $current->attributes->get('request_id'));
        }

        if (!RecordUtils::isTenantIdMissing($tenantId)) {
            // Where the HTTP API's own hooks find it: the attribute and the tenant header.
            $request->attributes->set('resolved_tenant_id', $tenantId);
            $request->headers->set(RecordConfigService::tenantHeader(), (string) $tenantId);
        }

        return $request;
    }

    /**
     * Global hook first, then the table's — the controller's order.
     *
     * @param array<string, mixed> $context
     */
    private function before(string $hook, mixed $tableTrigger, string $table, Request $request, array $context): Request
    {
        $params = $this->hook(fn(): array => $this->records->executeGlobalTrigger($hook, [$request, $table, $context]));
        $request = ($params[0] ?? null) instanceof Request ? $params[0] : $request;

        $params = $this->hook(fn(): array => $this->records->executeTableTrigger($tableTrigger, [$request, $table, $context]));

        return ($params[0] ?? null) instanceof Request ? $params[0] : $request;
    }

    /**
     * Table hook first, then the global one — the controller's order.
     *
     * @param array<string, mixed> $context
     */
    private function afterRead(string $table, Request $request, array $context): void
    {
        $this->hook(fn(): array => $this->records->executeTableTrigger($this->schema($table)->afterRead ?? null, [$request, $table, $context]));
        $this->hook(fn(): array => $this->records->executeGlobalTrigger('afterRead', [$request, $table, $context]));
    }

    /**
     * Run app hook or validator code. A refusal the HTTP API also passes on —
     * an HTTP response, a validation failure — passes through; anything else
     * becomes RecordHookFailed, whose details only reach the log.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function hook(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (HttpResponseException|ValidationException|ToolError $refusal) {
            throw $refusal;
        } catch (Throwable $throwable) {
            throw new RecordHookFailed($throwable);
        }
    }

    private function validate(mixed $validatorConfig, Request $request, ?string $id): void
    {
        $failed = $this->hook(fn(): ?ValidatorContract => TableValidatorRunner::firstFailure($validatorConfig, $request, $id));
        if ($failed instanceof ValidatorContract) {
            throw new ValidationException($failed);
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function validateRules(array $rules, Request $request): void
    {
        if ([] !== $rules) {
            Validator::make($request->all(), $rules)->validate();
        }
    }

    /**
     * The query string is not part of the payload — the controller's rule.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function payloadAndQuery(Request $request): array
    {
        $query = $request->query->all();

        return [$request->except(array_keys($query)), $query];
    }

    /**
     * A beforeRead hook can add an include after the executor's own check ran.
     *
     * @param array<string, mixed> $query
     */
    private function refuseTenantScopedIncludes(string $table, array $query, mixed $tenantId): void
    {
        if (!RecordConfigService::enableTenantId() || !RecordUtils::isTenantIdMissing($tenantId)) {
            return;
        }

        $used = TenantScopedIncludes::requested($table, $query);
        if ([] !== $used) {
            throw new ToolError(sprintf('Tenant context is required to include %s, but none was resolved from the request.', implode(', ', $used)), -32001);
        }
    }
}
