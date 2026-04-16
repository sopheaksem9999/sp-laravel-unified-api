<?php

namespace Sopheak\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class RecordCreated
{
    use Dispatchable;

    public function __construct(
        public string $table,
        public array $payload,
        public int|string $id,
        public array $auditContext = []
    ) {}
}
