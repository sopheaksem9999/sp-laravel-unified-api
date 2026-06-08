<?php

declare(strict_types=1);

namespace Sopheak\Core\Resources;

use Sopheak\Core\Interfaces\RecordResourceInterface;
use Sopheak\Core\Types\RecordTableType;

abstract class RecordResource implements RecordResourceInterface
{
    /**
     * Define the table configuration.
     */
    abstract public function configure(): RecordTableType;

    /**
     * Convert the resource definition to a RecordTableType.
     */
    public function toTableType(): RecordTableType
    {
        return $this->configure();
    }
}
