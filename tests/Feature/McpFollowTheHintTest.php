<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Mcp\SchemaTools;
use Sopheak\Core\Tests\Concerns\BuildsGuidanceFixture;
use Sopheak\Core\Tests\Concerns\ParsesPayloadHints;
use Sopheak\Core\Tests\TestCase;

/**
 * An agent that follows the advertised payload hint must get the database
 * change the hint describes (spec §9 "Follow the hint", finding W1).
 */
class McpFollowTheHintTest extends TestCase
{
    use BuildsGuidanceFixture;
    use ParsesPayloadHints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGuidanceFixture();
        DB::table('invoices')->insert(['id' => 1, 'ref_number' => 'INV-1', 'customer_id' => 1]);
    }

    /** @return array<int, array<string, mixed>> */
    private function example(string $alias): array
    {
        foreach (app(SchemaTools::class)->getEndpoint(['endpoint' => 'invoices'])['includes'] as $include) {
            if ($include['name'] === $alias) {
                return $this->hintExample($include);
            }
        }

        $this->fail('No include named ' . $alias);
    }

    public function test_the_has_many_hint_creates_updates_and_deletes_children(): void
    {
        DB::table('invoice_items')->insert([
            ['id' => 2, 'invoice_id' => 1, 'description' => 'old'],
            ['id' => 5, 'invoice_id' => 1, 'description' => 'doomed'],
        ]);

        $this->putJson('/api/invoices/1', ['items' => $this->example('items')])->assertSuccessful();

        $this->assertSame('example', DB::table('invoice_items')->where('id', 2)->value('description'));
        $this->assertNull(DB::table('invoice_items')->where('id', 5)->first());
        $this->assertSame(2, DB::table('invoice_items')->where('invoice_id', 1)->count(), 'item 2 plus the created one');
    }

    public function test_the_many_to_many_hint_attaches_updates_pivots_creates_and_detaches(): void
    {
        DB::table('tags')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 5, 'name' => 'c']]);
        DB::table('invoice_tag')->insert(['invoice_id' => 1, 'tag_id' => 5]);

        $this->putJson('/api/invoices/1', ['tags' => $this->example('tags')])->assertSuccessful();

        $linked = DB::table('invoice_tag')->where('invoice_id', 1)->pluck('note', 'tag_id')->all();
        $this->assertArrayHasKey(1, $linked);
        $this->assertSame('example', $linked[2]);
        $this->assertArrayNotHasKey(5, $linked, 'tag 5 is detached');
        $this->assertSame(1, DB::table('tags')->where('id', 5)->count(), 'detaching never deletes the related row');
        $this->assertCount(3, $linked, 'tags 1 and 2 plus the created one');
        $this->assertSame(4, DB::table('tags')->count());
    }

    public function test_the_guidance_child_collection_example_writes_as_described(): void
    {
        DB::table('invoice_items')->insert([
            ['id' => 105, 'invoice_id' => 1, 'description' => 'old', 'quantity' => 1],
            ['id' => 88, 'invoice_id' => 1, 'description' => 'doomed', 'quantity' => 1],
        ]);
        $example = app(SchemaTools::class)->apiGuidance()['nestedWriteExamples']['childCollections']['example'];

        $this->putJson('/api/invoices/1', ['items' => $example['items']])->assertSuccessful();

        $this->assertSame(12, (int) DB::table('invoice_items')->where('id', 105)->value('quantity'));
        $this->assertNull(DB::table('invoice_items')->where('id', 88)->first());
        $this->assertSame(1, DB::table('invoice_items')->where('description', 'Development services')->count());
    }

    public function test_the_guidance_many_to_many_example_attaches_every_id(): void
    {
        DB::table('tags')->insert([['id' => 1, 'name' => 'a'], ['id' => 3, 'name' => 'b'], ['id' => 5, 'name' => 'c']]);
        $example = app(SchemaTools::class)->apiGuidance()['nestedWriteExamples']['manyToManyPivot']['example'];
        $ids = array_values(array_filter($example, is_array(...)))[0];

        $this->putJson('/api/invoices/1', ['tags' => $ids])->assertSuccessful();

        $this->assertSame([1, 3, 5], DB::table('invoice_tag')->where('invoice_id', 1)->orderBy('tag_id')->pluck('tag_id')->map(static fn($v): int => (int) $v)->all());
    }

    public function test_the_cursor_recipe_in_the_guidance_really_pages(): void
    {
        $cursor = app(SchemaTools::class)->apiGuidance()['querySyntaxExamples']['paginationAndSorting']['cursorBased'];
        $this->assertSame(['cursor', 'direction', 'per_page'], array_keys($cursor['example']), 'limit would win over the cursor and never page');
        $this->assertStringContainsString('per_page', $cursor['syntax']);

        foreach (range(2, 5) as $id) {
            DB::table('invoices')->insert(['id' => $id, 'ref_number' => 'INV-' . $id, 'customer_id' => 1]);
        }

        $first = $this->getJson('/api/invoices?cursor=&direction=next&per_page=2&sortby=id&order=asc')->assertOk();
        $second = $this->getJson('/api/invoices?' . http_build_query(['cursor' => $first->json('meta.cursor'), 'direction' => 'next', 'per_page' => 2, 'sortby' => 'id', 'order' => 'asc']))->assertOk();

        $ids = fn ($response): array => array_column($response->json('data'), 'id');
        $this->assertSame([1, 2], $ids($first));
        $this->assertSame([3, 4], $ids($second), 'following meta.cursor returns the next page');
    }

    public function test_bare_ids_attach_as_the_many_to_many_hint_says(): void
    {
        DB::table('tags')->insert(['id' => 1, 'name' => 'a']);

        $this->putJson('/api/invoices/1', ['tags' => [1]])->assertSuccessful();

        $this->assertSame(1, DB::table('invoice_tag')->where('invoice_id', 1)->where('tag_id', 1)->count());
    }
}
