<?php

namespace Sopheak\Core\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Sopheak\Core\Types\RecordFunctionType;

class LegacyFunction
{
    public function handle(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Legacy function executed',
        ]);
    }
}
