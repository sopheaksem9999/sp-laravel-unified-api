<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * Module recipes in sp_api_get_api_guidance (spec §6.6 M5): one block per
 * enabled module, built from the module's live config and routes.
 */
class McpModuleRecipesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function modules(): array
    {
        return app(SchemaTools::class)->apiGuidance()['modules'] ?? [];
    }

    private function enableAttachments(): void
    {
        Config::set('attachments.enabled', true);
        Config::set('attachments.tables', (require dirname(__DIR__, 2) . '/config/sp-attachments.php')['tables']);
        SchemaRegistryUtils::refresh();
    }

    private function enableAudit(): void
    {
        Config::set('audit.enabled', true);
        SchemaRegistryUtils::refresh();
    }

    private function listedUri(string $contains): string
    {
        foreach (app(SchemaTools::class)->listEndpoints([]) as $endpoint) {
            if (str_contains((string) $endpoint['uri'], $contains)) {
                return (string) $endpoint['uri'];
            }
        }

        $this->fail('No listed endpoint contains ' . $contains);
    }

    public function test_no_module_blocks_when_modules_are_off(): void
    {
        $this->assertSame([], $this->modules());
    }

    public function test_modules_are_omitted_one_by_one(): void
    {
        $this->enableAttachments();

        $this->assertSame(['attachments'], array_keys($this->modules()));
    }

    public function test_audit_recipe_uses_the_live_routes(): void
    {
        $this->enableAudit();
        $audit = $this->modules()['audit'];

        $this->assertSame($this->listedUri('field-timeline'), $audit['fieldTimeline']['uri']);
        $this->assertSame($this->listedUri('field-stats'), $audit['fieldStats']['uri']);
        $this->assertStringContainsString('entity_type=eq.', $audit['history']['request']);
        $this->assertStringContainsString('entity_id=eq.', $audit['history']['request']);
        $this->assertStringContainsString('/api/sp_audit_logs/rpc/field-timeline/', $audit['fieldTimeline']['uri']);
        $this->assertStringContainsString('old_data', implode(' ', $audit['notes']));
        $this->assertStringContainsString('not recorded', implode(' ', $audit['notes']));

        Config::set('record.rpc_prefix', 'calls');
        $renamed = $this->modules()['audit']['fieldTimeline']['uri'];

        $this->assertStringContainsString('/api/sp_audit_logs/calls/field-timeline/', $renamed);
        $this->assertSame($this->listedUri('field-timeline'), $renamed);
    }

    public function test_permissions_recipe_reads_the_separator_and_prefix(): void
    {
        Config::set('permissions.enabled', true);
        SchemaRegistryUtils::refresh();
        $permissions = $this->modules()['permissions'];

        $this->assertSame('{action}:{pmsName}', $permissions['nameFormat']);
        $this->assertSame('viewOwn', $permissions['viewOwn']['prefix']);
        $this->assertSame('viewOwn:invoices', $permissions['viewOwn']['example']);
        $this->assertContains('update', $permissions['actions']);
        $this->assertSame(['permissions' => [['id' => '<permission id>']]], $permissions['attachToRole']['payload']);
        $this->assertStringContainsString('exposes no endpoint for assigning roles', $permissions['assignRoles']);

        Config::set('record.permission_separator', '|');
        Config::set('record.own_records_permission_prefix', 'seeOwn');
        $changed = $this->modules()['permissions'];
        $this->assertSame('{action}|{pmsName}', $changed['nameFormat']);
        $this->assertSame('seeOwn|invoices', $changed['viewOwn']['example']);
    }

    public function test_assign_roles_names_the_users_endpoint_when_it_declares_a_roles_include(): void
    {
        Config::set('permissions.enabled', true);
        Config::set('record.tables', [
            'users' => new RecordTableType(
                table: 'users',
                pmsName: 'users',
                columns: ['id' => ['type' => 'integer', 'nullable' => false]],
                relationships: [
                    'roles' => new RecordMetaBelongsToManyType(related: 'sp_roles', table: 'sp_model_has_roles', foreignPivotKey: 'model_id', relatedPivotKey: 'role_id'),
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $assign = $this->modules()['permissions']['assignRoles'];

        $this->assertSame('PUT /api/users/{id}', $assign['request']);
        $this->assertSame(['roles' => [['id' => '<role id>']]], $assign['payload']);
    }

    public function test_attachments_recipe_lists_multipart_upload_and_link_flow(): void
    {
        $this->enableAttachments();
        $attachments = $this->modules()['attachments'];

        $this->assertSame('multipart/form-data', $attachments['upload']['contentType']);
        $this->assertContains('file', $attachments['upload']['fields']);
        $this->assertContains('record_type', $attachments['upload']['fields']);
        $this->assertContains('temp_private', $attachments['upload']['visibility']);
        $this->assertSame(['file'], $attachments['upload']['required']);
        $this->assertSame($this->listedUri('/rpc/upload'), $attachments['upload']['uri']);
        $this->assertArrayHasKey('attachment_ids', $attachments['linkAfterUpload']['payload']);
        $this->assertStringContainsString('record_type', $attachments['uploadAndLink']);
        $this->assertStringContainsString('record_id', $attachments['uploadAndLink']);
        $this->assertSame($this->listedUri('{id}/view'), $attachments['view']['uri']);
        $this->assertSame($this->listedUri('{id}/download'), $attachments['download']['uri']);
    }

    public function test_optional_attachment_features_follow_their_config(): void
    {
        $this->enableAttachments();
        Config::set('attachments.preview_url_enabled', false);
        Config::set('attachments.read_resizing', false);
        $off = $this->modules()['attachments'];
        $this->assertArrayNotHasKey('preview', $off);
        $this->assertArrayNotHasKey('query', $off['view']);

        Config::set('attachments.preview_url_enabled', true);
        Config::set('attachments.read_resizing', true);
        SchemaRegistryUtils::refresh();
        $on = $this->modules()['attachments'];
        $this->assertArrayHasKey('preview', $on);
        $this->assertSame(['w', 'h', 'fit', 'format', 'size_name'], $on['view']['query']);
    }
}
