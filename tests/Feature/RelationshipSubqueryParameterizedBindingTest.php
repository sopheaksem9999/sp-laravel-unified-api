<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Sopheak\Core\Enums\RecordRelationshipsEnum;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\RelationshipResolverUtils;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class RelationshipSubqueryParameterizedBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->string('guard_name');
            $table->timestamps();
        });

        Config::set('record.tables.users', new RecordTableType(
            table: 'users',
            columns: [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
            relationships: [
                'roles' => new RecordMetaBelongsToManyType(
                    related: 'roles',
                    table: 'user_roles',
                    foreignPivotKey: 'user_id',
                    relatedPivotKey: 'role_id',
                    wherePivot: [
                        ['column' => 'guard_name', 'operator' => '=', 'value' => 'web'],
                    ],
                ),
            ],
        ));

        Config::set('record.tables.roles', new RecordTableType(
            table: 'roles',
            columns: [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
        ));

        Config::set('record.tables.user_roles', new RecordTableType(
            table: 'user_roles',
            columns: [
                'id' => ['type' => 'integer'],
                'user_id' => ['type' => 'integer'],
                'role_id' => ['type' => 'integer'],
                'guard_name' => ['type' => 'string'],
            ],
        ));

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function pivot_where_conditions_use_pdo_bindings_instead_of_string_concatenation(): void
    {
        $builder = DB::table('users');
        $builder = RelationshipResolverUtils::applySubqueryRelationships($builder, 'users', [
            'roles' => ['columns' => ['id', 'name']],
        ]);

        $bindings = $builder->getBindings();
        $this->assertContains('web', $bindings);
    }

    /** @test */
    public function morph_to_many_subquery_uses_pdo_bindings_for_relation_and_where_pivot(): void
    {
        Config::set('record.tables.users.relationships.permissions', new RecordMetaBelongsToManyType(
            related: 'roles',
            type: RecordRelationshipsEnum::MORPH_TO_MANY,
            table: 'user_roles',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'role_id',
            relation: 'App\\Models\\User',
            wherePivot: [
                ['column' => 'guard_name', 'operator' => '=', 'value' => 'api'],
            ]
        ));
        SchemaRegistryUtils::refresh();

        $builder = DB::table('users');
        $builder = RelationshipResolverUtils::applySubqueryRelationships($builder, 'users', [
            'permissions' => ['columns' => ['id', 'name']],
        ]);

        $bindings = $builder->getBindings();
        $this->assertContains('App\\Models\\User', $bindings);
        $this->assertContains('api', $bindings);
    }
}
