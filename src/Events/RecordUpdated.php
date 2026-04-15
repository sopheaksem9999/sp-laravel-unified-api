<?php

namespace Sopheak\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class RecordUpdated
{
    use Dispatchable;

    public function __construct(
        public string $table,
        public array $oldPayload,
        public array $newPayload,
        public int|string $id,
        public array $auditContext = []
    ) {}
}
