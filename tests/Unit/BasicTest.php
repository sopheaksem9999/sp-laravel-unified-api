<?php

namespace Sopheak\Core\Tests\Unit;

use Sopheak\Core\Tests\TestCase;

class BasicTest extends TestCase
{
    /** @test */
    public function it_can_run_basic_test(): void
    {
        $this->assertTrue(true);
    }

    /** @test */
    public function it_has_laravel_application(): void
    {
        $this->assertNotNull($this->app);
    }

    /** @test */
    public function it_can_access_config(): void
    {
        $this->assertIsArray(config('record.tables'));
    }
}