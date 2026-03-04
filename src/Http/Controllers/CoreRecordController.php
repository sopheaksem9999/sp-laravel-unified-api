<?php

namespace Sopheak\Core\Http\Controllers;

use Illuminate\Routing\Controller;
use Sopheak\Core\Http\Controllers\Concerns\HasBulkOperations;
use Sopheak\Core\Http\Controllers\Concerns\HasControllerHelpers;
use Sopheak\Core\Http\Controllers\Concerns\HasCrudOperations;
use Sopheak\Core\Http\Controllers\Concerns\HasFunctionOperations;
use Sopheak\Core\Services\RecordService;

class CoreRecordController extends Controller
{
    use HasControllerHelpers;
    use HasCrudOperations;
    use HasBulkOperations;
    use HasFunctionOperations;

    public function __construct(
        protected RecordService $recordService
    ) {}
}
