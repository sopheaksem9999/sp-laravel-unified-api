<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Fixtures\AttributeDiscovery;

use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordGlobalFunction;

class GlobalFunctionAttributeResource
{
    /**
     * @return array<string, bool>
     */
    #[RecordGlobalFunction(name: 'ping', httpMethod: ['GET'], isPublic: true)]
    public static function ping(Request $request): array
    {
        return ['pong' => true];
    }
}
