<?php

namespace Sopheak\Core\Resources;

use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Sopheak\Core\Types\RecordFunctionType;

abstract class GlobalFunction implements RecordFunctionInterface
{
    /**
     * Define the function configuration.
     */
    abstract public function configure(): RecordFunctionType;

    public function toFunctionType(): RecordFunctionType
    {
        return $this->configure();
    }
}
