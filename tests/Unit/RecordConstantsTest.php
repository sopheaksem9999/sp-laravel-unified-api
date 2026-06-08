<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sopheak\Core\Constants\RecordConstants;

class RecordConstantsTest extends TestCase
{
    public function test_constants_values(): void
    {
        $this->assertSame('read', RecordConstants::READ);
        $this->assertSame('view', RecordConstants::VIEW);
        $this->assertSame('see', RecordConstants::SEE);
        $this->assertSame('write', RecordConstants::WRITE);
        $this->assertSame('edit', RecordConstants::EDIT);
        $this->assertSame('destroy', RecordConstants::DESTROY);
        $this->assertSame('create', RecordConstants::CREATE);
        $this->assertSame('update', RecordConstants::UPDATE);
        $this->assertSame('delete', RecordConstants::DELETE);
        $this->assertSame('restore', RecordConstants::RESTORE);

        $this->assertSame('view', RecordConstants::ACTION_VIEW);
        $this->assertSame('create', RecordConstants::ACTION_CREATE);
        $this->assertSame('update', RecordConstants::ACTION_UPDATE);
        $this->assertSame('delete', RecordConstants::ACTION_DELETE);
        $this->assertSame('restore', RecordConstants::ACTION_RESTORE);
    }
}
