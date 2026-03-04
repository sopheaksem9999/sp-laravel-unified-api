<?php

namespace Sopheak\Core\Exceptions;

use RuntimeException;
use Throwable;

class RecordServerException extends RuntimeException
{
    public function __construct(string $message, public readonly Throwable $cause)
    {
        parent::__construct($message, 0, $cause);
    }
}
