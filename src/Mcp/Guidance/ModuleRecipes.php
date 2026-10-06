<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp\Guidance;

use Sopheak\Core\Constants\RecordConstants;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

/**
 * Task recipes for the built-in modules (audit, permissions, attachments),
 * one block per module the app has enabled. Every URL is built from the
 * module's registered table and function keys and the live route prefixes,
 * never typed out, so the recipe cannot drift from the routes.
 */
final readonly class ModuleRecipes
{
    private const PERMISSIONS_TABLE = 'sp_roles';

    private const ATTACHMENTS_TABLE = 'sp_attachments';

    private EndpointContext $context;

    public function __construct()
    {
        $this->context = new EndpointContext();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function build(): array
    {
        $recipes = [];
        foreach (['audit' => $this->audit(...), 'permissions' => $this->permissions(...), 'attachments' => $this->attachments(...)] as $name => $recipe) {
            $block = $recipe();
            if (null !== $block) {
                $recipes[$name] = $block;
            }
        }

        return $recipes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function audit(): ?array
    {
        $key = (string) config('audit.audit_log_model', 'sp_audit_logs');
        $table = SchemaRegistryUtils::getTable($key);
        if (!(bool) config('audit.enabled', false) || !$table instanceof RecordTableType) {
            return null;
        }

        $columns = (array) $table->columns;
        $api = '/' . trim(RecordConfigService::apiPrefix(), '/') . '/' . $key;
        $recipe = ['table' => $key, 'summary' => 'Read-only history of who changed which record and how.'];

        if (isset($columns['entity_type'], $columns['entity_id'])) {
            $recipe['history'] = [
                'request' => 'GET ' . $api . '?entity_type=eq.<table name>&entity_id=eq.<record id>&sortby=created_at&order=desc',
                'note' => 'entity_type is the audited table name and entity_id the record id; newest first.',
            ];
        }

        foreach (['stats' => 'stats', 'fieldTimeline' => 'field-timeline', 'fieldStats' => 'field-stats'] as $name => $prefix) {
            foreach ($this->functionKeys($table) as $fnKey) {
                if ($fnKey === $prefix || str_starts_with($fnKey, $prefix . '/')) {
                    $recipe[$name] = ['method' => 'GET', 'uri' => $this->context->rpcUri($key, $fnKey)]
                        + ($fnKey === $prefix ? [] : ['pathParameters' => ['entityType' => 'the audited table name', 'entityId' => 'the record id', 'field' => 'the column name']]);
                }
            }
        }

        $json = array_keys(array_filter($columns, static fn(mixed $column): bool => 'json' === ColumnTypes::family((string) (is_array($column) ? ($column['type'] ?? '') : $column))));
        $recipe['notes'] = [
            [] === $json
                ? 'There is no JSON-path filter; use fieldTimeline for the history of one field.'
                : sprintf('%s are JSON columns: filter them as text with contains (old_data=contains.draft). There is no JSON-path filter; use fieldTimeline for the history of one field.', implode(', ', $json)),
            'Nested child changes made through a parent write are not recorded in the audit log.',
        ];

        return $recipe;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function permissions(): ?array
    {
        if (!(bool) config('permissions.enabled', false)) {
            return null;
        }

        $separator = RecordConfigService::permissionSeparator();
        $roles = SchemaRegistryUtils::getTable(self::PERMISSIONS_TABLE);
        $recipe = [
            'summary' => 'Permissions are named {action}' . $separator . '{pmsName} and granted to roles.',
            'nameFormat' => '{action}' . $separator . '{pmsName}',
            'actions' => [
                RecordConstants::ACTION_VIEW, RecordConstants::ACTION_CREATE, RecordConstants::ACTION_UPDATE,
                RecordConstants::ACTION_DELETE, RecordConstants::ACTION_RESTORE, 'force_delete',
            ],
            'viewOwn' => [
                'prefix' => RecordConfigService::ownRecordsPermissionPrefix(),
                'example' => RecordConfigService::ownRecordsPermissionPrefix() . $separator . 'invoices',
                'effect' => 'Holding viewOwn instead of view limits reads, writes and relationships to records the user created.',
            ],
            'listing' => "sp_api_list_permissions returns bare permission names only; read a role's permissions through its permissions include.",
        ];

        if ($roles instanceof RecordTableType) {
            $uri = '/' . trim(RecordConfigService::apiPrefix(), '/') . '/' . self::PERMISSIONS_TABLE;
            if (isset($roles->relationships['permissions'])) {
                $recipe['attachToRole'] = [
                    'request' => 'PUT ' . $uri . '/{id}',
                    'payload' => ['permissions' => [['id' => '<permission id>']]],
                    'note' => 'Permissions you leave out stay attached; detach one with {"id": ..., "_delete": true}.',
                ];
            }
        }

        $recipe['assignRoles'] = $this->assignRoles();

        return $recipe;
    }

    /**
     * @return array<string, mixed>|string
     */
    private function assignRoles(): array|string
    {
        foreach (SchemaRegistryUtils::get() as $key => $config) {
            if (!$config instanceof RecordTableType) {
                continue;
            }

            if (str_starts_with((string) $key, 'sp_')) {
                continue;
            }

            foreach (array_keys((array) $config->relationships) as $alias) {
                try {
                    $resolved = RelationshipResolverUtils::resolveRelationship((string) $key, (string) $alias);
                } catch (Throwable) {
                    continue;
                }

                if (is_array($resolved) && self::PERMISSIONS_TABLE === ($resolved['table'] ?? null) && PayloadSchemaBuilder::isAttaching((string) ($resolved['type'] ?? ''))) {
                    return [
                        'request' => 'PUT /' . trim(RecordConfigService::apiPrefix(), '/') . '/' . $key . '/{id}',
                        'payload' => [(string) $alias => [['id' => '<role id>']]],
                    ];
                }
            }
        }

        return 'This package exposes no endpoint for assigning roles to users unless your users table declares a roles relationship; none is declared.';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attachments(): ?array
    {
        $table = SchemaRegistryUtils::getTable(self::ATTACHMENTS_TABLE);
        if (!(bool) config('attachments.enabled', true) || !$table instanceof RecordTableType) {
            return null;
        }

        $functions = (array) $table->functions;
        $recipe = ['summary' => 'Upload a file, then link it to a record (or upload and link in one call).'];

        if (isset($functions['upload'])) {
            $schema = $this->functionConfig($functions['upload'])['payloadSchema'] ?? [];
            $recipe['upload'] = [
                'method' => 'POST',
                'uri' => $this->context->rpcUri(self::ATTACHMENTS_TABLE, 'upload'),
                'contentType' => 'multipart/form-data',
                'fields' => array_keys((array) ($schema['properties'] ?? [])),
                'required' => $schema['required'] ?? [],
                'visibility' => $schema['properties']['visibility']['enum'] ?? [],
            ];
            $recipe['uploadAndLink'] = "Send record_type (the record's table name) and record_id with the upload to link it in the same call.";
        }

        $linkKey = 'record/{table}/{record_id}';
        if (isset($functions[$linkKey])) {
            $recipe['linkAfterUpload'] = [
                'method' => 'POST',
                'uri' => $this->context->rpcUri(self::ATTACHMENTS_TABLE, $linkKey),
                'pathParameters' => ['table' => "the record's table name", 'record_id' => 'the record id'],
                'payload' => ['attachment_ids' => ['<attachment id>'], 'collection_name' => '<optional>'],
            ];
            $recipe['listForRecord'] = ['method' => 'GET', 'uri' => $this->context->rpcUri(self::ATTACHMENTS_TABLE, $linkKey)];
        }

        foreach (['view' => '{id}/view', 'download' => '{id}/download', 'preview' => '{id}/preview'] as $name => $fnKey) {
            if (isset($functions[$fnKey])) {
                $recipe[$name] = ['method' => 'GET', 'uri' => $this->context->rpcUri(self::ATTACHMENTS_TABLE, $fnKey)];
            }
        }

        $resizing = $this->context->resizingQuerySchema();
        if (isset($recipe['view']) && null !== $resizing) {
            $recipe['view']['query'] = array_keys($resizing['properties']);
        }

        return $recipe;
    }

    /**
     * @return array<int, string>
     */
    private function functionKeys(RecordTableType $table): array
    {
        return array_map(strval(...), array_keys((array) $table->functions));
    }

    /**
     * @return array<string, mixed>
     */
    private function functionConfig(mixed $function): array
    {
        return $function instanceof RecordFunctionType ? $function->toArray() : (array) $function;
    }
}
