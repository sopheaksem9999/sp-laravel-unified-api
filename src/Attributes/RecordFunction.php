<?php

namespace Sopheak\Core\Attributes;

use Attribute;
use Sopheak\Core\Enums\RecordFunctionMethodEnum;

/**
 * Declare a table-scoped custom function on a method.
 *
 * This attribute is discovered only when attribute discovery is enabled and the
 * class is discovered as a RecordTable via #[RecordTable].
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RecordFunction
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $table = null,
        public readonly array|string|RecordFunctionMethodEnum $httpMethod = [RecordFunctionMethodEnum::GET->value],
        public readonly bool $isPublic = false,
        public readonly array|string|null $pmsName = null,
        public readonly bool $disableCache = false,
        public readonly ?int $cacheTTL = null,
        public readonly ?string $description = null,
        public readonly ?array $querySchema = null,
        public readonly ?array $payloadSchema = null,
        public readonly ?array $responseSchema = null,
        public readonly array|string|null $clearCacheTables = null,
        public readonly array|string|null $middleware = null,
    ) {}
}
