<?php

declare(strict_types=1);

namespace Sopheak\Core\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;
use Sopheak\Core\Enums\RecordApiJsonResponseEnum;
use Sopheak\Core\Services\RecordApiResponseService;
use Sopheak\Core\Utilities\PermissionUtils;

/**
 * A nested child write the caller is not authorised for. Carries the same
 * 401/403 response a direct request on the child table would get, so the
 * HTTP controllers' HttpResponseException handling returns it unchanged.
 * MCP maps $decision to its own error codes.
 */
final class NestedWriteRefusedException extends HttpResponseException
{
    public function __construct(public readonly string $decision)
    {
        parent::__construct(PermissionUtils::DECISION_UNAUTHENTICATED === $decision
            ? RecordApiResponseService::errorWrapped('Unauthenticated', RecordApiJsonResponseEnum::UNAUTHORIZED->value)
            : RecordApiResponseService::errorWrapped('Forbidden', RecordApiJsonResponseEnum::FORBIDDEN->value));
    }
}
