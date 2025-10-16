<?php

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Sopheak\Core\Support\SchemaRegistry;
use Mockery;

class SchemaRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_get_schema_registry()
    {
        // Mock config
        Config::shouldReceive('get')
               ->with('record.tables', [])
               ->andReturn([]);

        // Mock cache
        Cache::shouldReceive('get')
              ->andReturn(null);
        Cache::shouldReceive('put')
              ->andReturn(true);

        $schema = SchemaRegistry::get();

        $this->assertIsArray($schema);
    }

    /** @test */
    public function it_can_refresh_cache()
    {
        // Mock cache operations
        Cache::shouldReceive('forget')
              ->andReturn(true);
        
        Config::shouldReceive('get')
               ->with('record.tables', [])
               ->andReturn([]);

        // Should not throw any exceptions
        SchemaRegistry::refresh();
        
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /** @test */
    public function it_can_clear_all_cache()
    {
        // Mock cache operations
        Cache::shouldReceive('forget')
              ->andReturn(true);
        
        Config::shouldReceive('get')
               ->with('record.tables', [])
               ->andReturn([]);

        // Should not throw any exceptions
        SchemaRegistry::clearAllCache();
        
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /** @test */
    public function it_can_clear_table_cache()
    {
        // Mock cache operations
        Cache::shouldReceive('forget')
              ->andReturn(true);

        // Should not throw any exceptions
        SchemaRegistry::clearTableCache('test_table');
        
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /** @test */
    public function it_returns_empty_array_when_no_tables_configured()
    {
        // Mock config to return empty array
        Config::shouldReceive('get')
               ->with('record.tables', [])
               ->andReturn([]);

        // Mock cache
        Cache::shouldReceive('get')
              ->andReturn(null);
        Cache::shouldReceive('put')
              ->andReturn(true);

        $schema = SchemaRegistry::get();

        $this->assertIsArray($schema);
        $this->assertEmpty($schema);
    }

    /** @test */
    public function it_uses_memory_cache_when_available()
    {
        // First call - should hit config and cache
        Config::shouldReceive('get')
               ->with('record.tables', [])
               ->once()
               ->andReturn([]);

        Cache::shouldReceive('get')
              ->once()
              ->andReturn(null);
        Cache::shouldReceive('put')
              ->once()
              ->andReturn(true);

        $schema1 = SchemaRegistry::get();

        // Second call - should use memory cache (no config or cache calls)
        $schema2 = SchemaRegistry::get();

        $this->assertEquals($schema1, $schema2);
    }
}