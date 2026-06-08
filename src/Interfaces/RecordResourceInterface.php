<?php

declare(strict_types=1);

namespace Sopheak\Core\Interfaces;

use Sopheak\Core\Types\RecordTableType;

interface RecordResourceInterface
{
    /**
     * Convert the resource definition to a RecordTableType.
     */
    public function toTableType(): RecordTableType;
}
