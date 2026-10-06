<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\Concerns\ParsesPayloadHints;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The nested-write hints must name the related table's real primary key. A
 * hint that says {"id": 2} for a child keyed `line_id` updates nothing: the
 * row is created again, or a delete is refused.
 */
class McpFollowTheHintCustomKeyTest extends TestCase
{
    use ParsesPayloadHints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('parents', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });
        Schema::create('lines', function (Blueprint $table): void {
            $table->id('line_id');
            $table->unsignedBigInteger('parent_id');
            $table->string('text')->nullable();
            $table->timestamps();
        });
        Schema::create('labels', function (Blueprint $table): void {
            $table->id('label_id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('parent_label', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('label_id');
            $table->string('note')->nullable();
        });

        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.tables', [
            'parents' => new RecordTableType(
                table: 'parents',
                pmsName: 'parents',
                public: $public,
                relationships: [
                    'lines' => new RecordHasManyType(table: 'lines', foreignKey: 'parent_id'),
                    'labels' => new RecordMetaBelongsToManyType(related: 'labels', table: 'parent_label', foreignPivotKey: 'parent_id', relatedPivotKey: 'label_id', withPivot: ['note']),
                ],
            ),
            'lines' => new RecordTableType(table: 'lines', pmsName: 'lines', public: $public, primaryKey: 'line_id'),
            'labels' => new RecordTableType(table: 'labels', pmsName: 'labels', public: $public, primaryKey: 'label_id'),
        ]);
        SchemaRegistryUtils::refresh();
        DB::table('parents')->insert(['id' => 1, 'title' => 'P']);
    }

    /** @return array<int, array<string, mixed>> */
    private function example(string $alias): array
    {
        foreach (app(SchemaTools::class)->getEndpoint(['endpoint' => 'parents'])['includes'] as $include) {
            if ($include['name'] === $alias) {
                return $this->hintExample($include);
            }
        }

        $this->fail('No include named ' . $alias);
    }

    public function test_the_has_many_hint_uses_the_childs_primary_key(): void
    {
        DB::table('lines')->insert([
            ['line_id' => 2, 'parent_id' => 1, 'text' => 'old'],
            ['line_id' => 5, 'parent_id' => 1, 'text' => 'doomed'],
        ]);
        $example = $this->example('lines');

        $this->assertSame(2, $example[1]['line_id']);
        $this->assertSame(['line_id' => 5, '_delete' => true], $example[2]);
        $this->assertArrayNotHasKey('id', $example[1]);

        $this->putJson('/api/parents/1', ['lines' => $example])->assertSuccessful();

        $this->assertSame('example', DB::table('lines')->where('line_id', 2)->value('text'), 'updated in place, not duplicated');
        $this->assertNull(DB::table('lines')->where('line_id', 5)->first());
        $this->assertSame(2, DB::table('lines')->count());
    }

    public function test_the_many_to_many_hint_uses_the_related_primary_key(): void
    {
        DB::table('labels')->insert([['label_id' => 1, 'name' => 'a'], ['label_id' => 2, 'name' => 'b'], ['label_id' => 5, 'name' => 'c']]);
        DB::table('parent_label')->insert(['parent_id' => 1, 'label_id' => 5]);
        $example = $this->example('labels');

        $this->assertSame(['label_id' => 1], $example[0]);
        $this->assertSame(['label_id' => 5, '_delete' => true], $example[3]);

        $this->putJson('/api/parents/1', ['labels' => $example])->assertSuccessful();

        $linked = DB::table('parent_label')->where('parent_id', 1)->pluck('note', 'label_id')->all();
        $this->assertArrayHasKey(1, $linked);
        $this->assertSame('example', $linked[2]);
        $this->assertArrayNotHasKey(5, $linked);
    }

    public function test_the_hint_text_and_rules_name_the_key_too(): void
    {
        $hints = array_column(app(SchemaTools::class)->getEndpoint(['endpoint' => 'parents'])['includes'], 'payloadHint', 'name');

        $this->assertStringContainsString('"line_id"', $hints['lines']);
        $this->assertStringContainsString('{"label_id": N} attaches', $hints['labels']);

        $rules = implode(' ', app(SchemaTools::class)->apiGuidance()['nestedWrites']['rules']);
        $this->assertStringContainsString('primary key', $rules);
    }
}
