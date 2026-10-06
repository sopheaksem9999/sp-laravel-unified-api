<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The review fixture for the agent-guidance tests: an `invoices` table with
 * ten columns and three includes (a belongsTo customer, a hasMany of items and
 * a belongsToMany of tags with a pivot `note`), as in the spec's size budget.
 */
trait BuildsGuidanceFixture
{
    protected function buildGuidanceFixture(bool $tenant = false): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) use ($tenant): void {
            $table->id();
            if ($tenant) {
                $table->string('tenant_id')->nullable();
            }

            $table->string('ref_number');
            $table->unsignedBigInteger('customer_id');
            $table->string('status')->default('draft');
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->date('issued_at')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('description')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('note')->nullable();
        });

        $public = new RecordTablePublic(read: true, write: true);

        Config::set('record.enable_tenant_id', $tenant);
        Config::set('record.tables', [
            'customers' => new RecordTableType(table: 'customers', pmsName: 'customers', public: $public),
            'invoices' => new RecordTableType(
                table: 'invoices',
                pmsName: 'invoices',
                hasTenantId: $tenant,
                softDeletes: true,
                public: $public,
                searchable: ['ref_number', 'customer.name'],
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'customers', foreignKey: 'customer_id'),
                    'items' => new RecordHasManyType(table: 'invoice_items', foreignKey: 'invoice_id'),
                    'tags' => new RecordMetaBelongsToManyType(
                        related: 'tags',
                        table: 'invoice_tag',
                        foreignPivotKey: 'invoice_id',
                        relatedPivotKey: 'tag_id',
                        withPivot: ['note'],
                    ),
                ],
            ),
            'invoice_items' => new RecordTableType(table: 'invoice_items', pmsName: 'invoice_items', public: $public),
            'tags' => new RecordTableType(table: 'tags', pmsName: 'tags', public: $public),
        ]);
        Config::set('record.mcp.enabled', true);
        Config::set('record.mcp.read_only', false);

        SchemaRegistryUtils::refresh();
    }
}
