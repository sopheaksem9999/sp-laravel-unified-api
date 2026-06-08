<?php

declare(strict_types=1);

namespace Sopheak\Core\Attributes;

use Attribute;
use Sopheak\Core\Enums\RecordFunctionMethodEnum;

/**
 * Declare a global custom function on a method.
 *
 * This attribute is discovered from configured attribute discovery paths when
 * attribute discovery is enabled.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RecordGlobalFunction
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly array|string|RecordFunctionMethodEnum $httpMethod = [RecordFunctionMethodEnum::GET->value],
        public readonly bool $isPublic = true,
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
