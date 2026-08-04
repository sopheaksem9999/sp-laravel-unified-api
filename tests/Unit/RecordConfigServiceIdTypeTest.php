<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use InvalidArgumentException;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;

class RecordConfigServiceIdTypeTest extends TestCase
{
    /** @test */
    public function it_defaults_to_integer_when_config_is_absent(): void
    {
        $this->app['config']->offsetUnset('record.id_type');

        $this->assertSame('integer', RecordConfigService::idType());
    }

    /** @test */
    public function it_returns_uuid_when_configured(): void
    {
        $this->app['config']->set('record.id_type', 'uuid');

        $this->assertSame('uuid', RecordConfigService::idType());
    }

    /** @test */
    public function it_normalizes_case_and_surrounding_whitespace(): void
    {
        $this->app['config']->set('record.id_type', '  UUID ');

        $this->assertSame('uuid', RecordConfigService::idType());
    }

    /** @test */
    public function it_throws_on_an_unrecognized_value(): void
    {
        $this->app['config']->set('record.id_type', 'uuidv4');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('record.id_type must be "uuid" or "integer", got "uuidv4"');

        RecordConfigService::idType();
    }

    /** @test */
    public function it_throws_on_a_non_string_value(): void
    {
        $this->app['config']->set('record.id_type', 123);

        $this->expectException(InvalidArgumentException::class);

        RecordConfigService::idType();
    }
}
