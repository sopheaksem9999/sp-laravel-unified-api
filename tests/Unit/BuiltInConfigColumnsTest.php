<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Utilities\DefaultValidationUtils;

/**
 * The shipped table configs are written with RecordColumnType; the columns
 * they produce must behave like the array form they replaced.
 */
class BuiltInConfigColumnsTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function columns(string $file, string $table): array
    {
        return (require dirname(__DIR__, 2) . '/config/' . $file)['tables'][$table]->columns;
    }

    public function test_permission_and_audit_columns_keep_their_types_and_nullability(): void
    {
        $permissions = $this->columns('sp-permissions.php', 'sp_permissions');
        $this->assertSame(['type' => 'varchar', 'nullable' => false], $permissions['name']);
        $this->assertSame(['type' => 'text', 'nullable' => true], $permissions['description']);
        $this->assertSame(['type' => 'datetime', 'nullable' => true], $permissions['created_at']);

        $roles = $this->columns('sp-permissions.php', 'sp_roles');
        $this->assertSame(['type' => 'varchar', 'nullable' => true, 'default' => 'api'], $roles['guard_name']);
        $this->assertSame(['type' => 'boolean', 'nullable' => false, 'default' => false], $roles['is_system']);

        $audit = $this->columns('sp-audit.php', 'sp_audit_logs');
        $this->assertSame(['type' => 'json', 'nullable' => true], $audit['old_data']);
        $this->assertSame(['type' => 'varchar', 'nullable' => true], $audit['entity_id']);
        $this->assertSame('bigint', $audit['id']['type']);
        $this->assertFalse($audit['id']['nullable']);
        $this->assertSame('PRI', $audit['id']['key']);
    }

    public function test_id_columns_are_never_required_on_create(): void
    {
        foreach ([['sp-permissions.php', 'sp_permissions'], ['sp-permissions.php', 'sp_roles'], ['sp-audit.php', 'sp_audit_logs']] as [$file, $table]) {
            $this->assertFalse(DefaultValidationUtils::isRequiredColumn($this->columns($file, $table)['id']), $table);
        }
    }

    public function test_openapi_types_are_unchanged(): void
    {
        $audit = $this->columns('sp-audit.php', 'sp_audit_logs');
        $this->assertSame(['type' => 'object'], OpenApiService::columnTypeSchema($audit['old_data']));
        $this->assertSame(['type' => 'string'], OpenApiService::columnTypeSchema($audit['event']));
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], OpenApiService::columnTypeSchema($audit['created_at']));
    }

    public function test_attachment_and_webhook_columns_keep_their_types_and_hidden_secret(): void
    {
        $attachments = $this->columns('sp-attachments.php', 'sp_attachments');
        $this->assertSame(['type' => 'integer', 'nullable' => false], $attachments['size']);
        $this->assertSame(['type' => 'datetime', 'nullable' => true], $attachments['temp_timeout']);
        $this->assertSame(['type' => 'varchar', 'nullable' => false], $attachments['path']);

        $this->assertSame('PRI', $this->columns('sp-attachments.php', 'sp_attachment_links')['id']['key']);
        $this->assertSame(['type' => 'json', 'nullable' => true], $this->columns('sp-attachments.php', 'sp_attachment_folders')['metadata']);

        $webhooks = $this->columns('sp-webhooks.php', 'sp_webhook_deliveries');
        $this->assertSame(['type' => 'json', 'nullable' => false], $webhooks['payload']);
        $this->assertSame(['type' => 'integer', 'nullable' => true], $webhooks['response_status']);
        $this->assertSame(['type' => 'boolean', 'nullable' => false], $this->columns('sp-webhooks.php', 'sp_webhook_endpoints')['is_active']);
    }

    public function test_attachment_function_schemas_are_typed_and_complete(): void
    {
        $functions = (require dirname(__DIR__, 2) . '/config/sp-attachments.php')['tables']['sp_attachments']->functions;

        $this->assertSame(['file'], $functions['upload']->payloadSchema['required']);
        $this->assertSame(['type' => 'string', 'format' => 'binary', 'description' => 'The file to upload'], $functions['upload']->payloadSchema['properties']['file']);
        $this->assertSame(['type' => 'string', 'format' => 'binary'], $functions['{id}/download']->responseSchema);
        $this->assertSame([['required' => ['attachment_ids']], ['required' => ['attachment_id']]], $functions['record/{table}/{record_id}']->payloadSchema['anyOf']);
    }
}
