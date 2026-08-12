<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * The record list/show cache must store JSON-safe arrays, not raw stdClass
 * rows. Serializing stores (database, file, redis-php) persist with PHP
 * serialize(); a stdClass row that round-trips through unserialize() into an
 * incomplete object makes every cached list/show request fail with a 500.
 *
 * @internal
 */
class RecordListCacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/sp-cache-serialization-' . uniqid();
        @mkdir($this->cacheDir, 0o755, true);

        Schema::create('video_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        DB::table('video_categories')->insert([
            ['name' => 'Intro'],
            ['name' => 'Advanced'],
        ]);

        Config::set('record.api_prefix', 'api');
        Config::set('record.cache.enabled', true);
        Config::set('record.cache.prefix', 'sp_laravel_api');
        Config::set('record.cache.ttl', 3600);
        Config::set('record.cache.per_table', ['video_categories' => true]);
        Config::set('record.tables', [
            'video_categories' => new RecordTableType(
                table: 'video_categories',
                pmsName: 'video_categories',
                public: new RecordTablePublic(read: true, write: true),
            ),
        ]);

        // A real serializing store — the in-memory array store never serializes,
        // so it cannot reproduce the unserialize failure the bug report describes.
        Config::set('cache.default', 'file');
        Config::set('cache.stores.file', [
            'driver' => 'file',
            'path' => $this->cacheDir,
        ]);

        SchemaRegistryUtils::refresh();
        app('cache')->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->cacheDir);
        parent::tearDown();
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['..', '.']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanupDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    public function test_list_round_trips_through_a_serializing_cache_store(): void
    {
        $first = $this->getJson('/api/video_categories?per_page=1');
        $first->assertOk();

        $second = $this->getJson('/api/video_categories?per_page=1');
        $second->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, count($second->json('data')));
    }

    public function test_show_round_trips_through_a_serializing_cache_store(): void
    {
        $first = $this->getJson('/api/video_categories/1');
        $first->assertOk();

        $second = $this->getJson('/api/video_categories/1');
        $second->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
    }

    public function test_cursor_pagination_round_trips_through_a_serializing_cache_store(): void
    {
        $first = $this->getJson('/api/video_categories?cursor=1&per_page=1');
        $first->assertOk();

        $second = $this->getJson('/api/video_categories?cursor=1&per_page=1');
        $second->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
    }

    public function test_cached_list_payload_contains_no_serialized_objects(): void
    {
        $this->getJson('/api/video_categories?per_page=1')->assertOk();
        $this->getJson('/api/video_categories?cursor=1&per_page=1')->assertOk();
        $this->getJson('/api/video_categories/1')->assertOk();
        $this->getJson('/api/video_categories?per_page=1')->assertOk();
        $this->getJson('/api/video_categories?cursor=1&per_page=1')->assertOk();
        $this->getJson('/api/video_categories/1')->assertOk();

        // The file store persists with PHP serialize(); the cached payload must
        // contain only arrays/scalars — no O:8:"stdClass" (or any object) rows.
        $contents = $this->collectCacheFiles();
        $this->assertNotEmpty($contents, 'Expected serialized cache files');

        foreach ($contents as $file => $body) {
            // Every file in this temp store belongs to this test's cache; the
            // cached payloads must contain arrays/scalars only — no serialized
            // objects (e.g. O:8:"stdClass") that a store's unserialize() can
            // fail to restore.
            $this->assertStringNotContainsString('O:8:"stdClass"', $body, 'Serialized stdClass row found in ' . $file);
            $this->assertStringNotContainsString('O:"', $body, 'Serialized object found in ' . $file);
        }
    }

    /**
     * @return array<string, string> file path => raw contents
     */
    private function collectCacheFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[(string) $file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
