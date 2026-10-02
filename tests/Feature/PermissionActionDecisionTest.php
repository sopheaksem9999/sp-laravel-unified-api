<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\PermissionUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The action decision HTTP, MCP and nested child writes share.
 *
 * @internal
 */
class PermissionActionDecisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $columns = ['id' => ['type' => 'integer', 'nullable' => false], 'name' => ['type' => 'string', 'nullable' => true]];
        Config::set('record.tables', [
            'widgets' => new RecordTableType(table: 'widgets', pmsName: 'widget', hasTenantId: false, columns: $columns),
            'public_widgets' => new RecordTableType(table: 'public_widgets', pmsName: 'public_widget', hasTenantId: false, public: new RecordTablePublic(read: true, write: true), columns: $columns),
            'open_widgets' => new RecordTableType(table: 'open_widgets', pmsName: null, hasTenantId: false, columns: $columns),
            'custom_widgets' => new RecordTableType(table: 'custom_widgets', pmsName: 'custom_widget', hasTenantId: false, columns: $columns, permissions: ['create' => ['custom:make']]),
        ]);
        SchemaRegistryUtils::refresh();
    }

    private function user(int $id = 1): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'u']);
    }

    /** @test */
    public function a_public_action_is_allowed_without_a_user(): void
    {
        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision(null, 'public_widgets', 'create'));
    }

    /** @test */
    public function no_user_on_an_authenticated_table_is_unauthenticated(): void
    {
        $this->assertSame(PermissionUtils::DECISION_UNAUTHENTICATED, PermissionUtils::actionDecision(null, 'widgets', 'create'));
    }

    /** @test */
    public function a_table_without_a_pms_name_needs_no_permission(): void
    {
        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(), 'open_widgets', 'create'));
    }

    /** @test */
    public function the_gate_decides_granted_and_denied(): void
    {
        Gate::before(fn($user, string $ability): ?bool => 'create:widget' === $ability ? true : null);

        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(), 'widgets', 'create'));
        $this->assertSame(PermissionUtils::DECISION_FORBIDDEN, PermissionUtils::actionDecision($this->user(), 'widgets', 'delete'));
    }

    /** @test */
    public function a_permission_override_map_is_honoured(): void
    {
        Gate::before(fn($user, string $ability): ?bool => 'custom:make' === $ability ? true : null);

        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(), 'custom_widgets', 'create'));
        $this->assertSame(PermissionUtils::DECISION_FORBIDDEN, PermissionUtils::actionDecision($this->user(), 'widgets', 'create'));
    }

    /** @test */
    public function a_super_admin_is_allowed_without_the_permission(): void
    {
        Config::set('permissions.super_admin_callback', fn($user): bool => 7 === (int) $user->id);

        $this->assertSame(PermissionUtils::DECISION_ALLOWED, PermissionUtils::actionDecision($this->user(7), 'widgets', 'delete'));
        $this->assertSame(PermissionUtils::DECISION_FORBIDDEN, PermissionUtils::actionDecision($this->user(8), 'widgets', 'delete'));
    }
}
