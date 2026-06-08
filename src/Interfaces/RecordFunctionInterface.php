<?php

declare(strict_types=1);

namespace Sopheak\Core\Interfaces;

use Sopheak\Core\Types\RecordFunctionType;

interface RecordFunctionInterface
{
    /**
     * Convert the resource definition to a RecordFunctionType.
     */
    public function toFunctionType(): RecordFunctionType;
}
