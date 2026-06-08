<?php

declare(strict_types=1);

namespace Sopheak\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class RecordDeleted
{
    use Dispatchable;

    public function __construct(
        public string $table,
        public array $oldPayload,
        public int|string $id,
        public array $auditContext = []
    ) {}
}
