<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Types\RecordTablePermissionType;
use Sopheak\Core\Types\RecordTableType;

class RecordTablePermissionTypeTest extends TestCase
{
    public function test_it_constructs_with_typed_properties(): void
    {
        $perms = new RecordTablePermissionType(
            read: ['view_booking', 'viewOwn_booking'],
            create: 'create_booking',
            update: 'update_booking',
            delete: 'delete_booking',
            forceDelete: 'permanently_delete_booking',
            restore: 'restore_booking',
            custom: [
                'export' => 'export_bookings',
            ],
        );

        $expected = [
            'read' => ['view_booking', 'viewOwn_booking'],
            'create' => ['create_booking'],
            'update' => ['update_booking'],
            'delete' => ['delete_booking'],
            'force_delete' => ['permanently_delete_booking'],
            'restore' => ['restore_booking'],
            'export' => ['export_bookings'],
        ];

        $this->assertSame($expected, $perms->toArray());
    }

    public function test_it_accepts_snake_case_force_delete(): void
    {
        $perms = new RecordTablePermissionType(
            read: 'view_item',
            force_delete: 'purge_item',
        );

        $this->assertSame([
            'read' => ['view_item'],
            'force_delete' => ['purge_item'],
        ], $perms->toArray());
    }

    public function test_it_filters_empty_and_whitespace_only_strings(): void
    {
        $perms = new RecordTablePermissionType(
            read: ['view_item', '  ', ''],
            create: '   ',
        );

        $this->assertSame([
            'read' => ['view_item'],
        ], $perms->toArray());
    }

    public function test_array_access_works(): void
    {
        $perms = new RecordTablePermissionType(
            read: ['view_user'],
            create: 'create_user',
        );

        $this->assertTrue(isset($perms['read']));
        $this->assertTrue(isset($perms['create']));
        $this->assertFalse(isset($perms['delete']));
        $this->assertSame(['view_user'], $perms['read']);
        $this->assertNull($perms['nonexistent']);
    }

    public function test_round_trip_via_from_array_and_set_state(): void
    {
        $data = [
            'read' => ['view_booking'],
            'create' => ['create_booking'],
            'force_delete' => ['purge_booking'],
            'custom_action' => ['do_custom'],
        ];

        $fromArray = RecordTablePermissionType::fromArray($data);
        $this->assertSame($data, $fromArray->toArray());

        $exported = var_export($fromArray, true);
        $evaled = eval('return ' . $exported . ';');
        $this->assertInstanceOf(RecordTablePermissionType::class, $evaled);
        $this->assertSame($data, $evaled->toArray());
    }

    public function test_it_integrates_seamlessly_with_record_table_type(): void
    {
        $typedPerms = new RecordTablePermissionType(
            read: ['view_posts', 'viewOwn_posts'],
            create: 'create_posts',
            delete: 'delete_posts',
        );

        $table = new RecordTableType(
            table: 'posts',
            permissions: $typedPerms,
        );

        $this->assertIsArray($table->permissions);
        $this->assertSame([
            'read' => ['view_posts', 'viewOwn_posts'],
            'create' => ['create_posts'],
            'delete' => ['delete_posts'],
        ], $table->permissions);

        $this->assertInstanceOf(RecordTablePermissionType::class, $table->permissionsType);
        $this->assertSame($typedPerms, $table->permissionsType);
    }
}

