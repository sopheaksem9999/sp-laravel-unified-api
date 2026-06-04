---
title: "AI SDK Integration"
description: "How to integrate Laravel AI SDK (Agents) into sp-laravel-api using table functions and global functions, with tenancy, auth, validation, and optional queue execution."
keywords:
  - ai
  - ai sdk
  - laravel ai
  - agents
  - functions
  - rpc
  - queue
  - streaming
  - prompts
---

# Module: Laravel AI SDK (Agents) Integration

This package does not depend on the Laravel AI SDK directly. Instead, the recommended integration is to implement AI SDK agents in your application and expose them through `sp-laravel-api` **table functions** or **global functions**.

This keeps `sp-laravel-api` focused on dynamic CRUD + orchestration, while your app owns provider configuration, model choice, and cost controls.

## Recommended Approach

- Use a **function endpoint** (table or global) as the API surface area.
- Put AI logic in an application service class (e.g., `App\Services\Ai\CustomerSummaryService`).
- For long-running work, use **Laravel queues** (return a job id, poll status, or push updates).

## Example 1: Table Function (Sync Response)

### 1) Add a function endpoint on a table

In `config/records/tables/customers.php`:

```php
<?php

use App\Services\Ai\CustomerSummaryService;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableType;

return new RecordTableType(
    pmsName: 'customer',
    table: 'customers',
    isAuthRead: true,
    isAuthWrite: true,
    relationships: [],
    functions: [
        'aiSummary' => new RecordFunctionType(
            type: 'class',
            class: CustomerSummaryService::class,
            functionName: 'aiSummary',
            httpMethod: ['POST'],
            required_params: ['customer_id'],
            description: 'Generate an AI summary for a single customer.',
        ),
    ],
    hasTenantId: true,
);
```

### 2) Implement the service class in the application

In `app/Services/Ai/CustomerSummaryService.php` (application code):

```php
<?php

namespace App\Services\Ai;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerSummaryService
{
    public function aiSummary(Request $request): array
    {
        $customerId = $request->input('customer_id');

        $customer = DB::table('customers')->where('id', $customerId)->first();
        abort_if(!$customer, 404, 'Customer not found');

        // Use Laravel AI SDK here (pseudo-code):
        // $summary = (new \App\Agents\CustomerSummaryAgent)->prompt([...]);

        return [
            'customer_id' => $customerId,
            'summary' => '...generated summary...',
        ];
    }
}
```

### 3) Call the function endpoint

```http
POST /api/v1/customers/fn/aiSummary
Content-Type: application/json

{ "customer_id": 123 }
```

## Example 2: Global Function (Queued Response)

Use this pattern when the AI call may take a long time.

### 1) Configure a global function endpoint

In `config/records/global-functions/ai.php`:

```php
<?php

use App\Services\Ai\CustomerSummaryAsyncService;
use Sopheak\Core\Types\RecordFunctionType;

return [
    'customer-summary' => new RecordFunctionType(
        type: 'class',
        class: CustomerSummaryAsyncService::class,
        functionName: 'dispatchCustomerSummary',
        httpMethod: ['POST'],
        required_params: ['customer_id'],
        description: 'Dispatch a background job to generate a customer summary.',
    ),
];
```

### 2) Dispatch a job and return an id

In `app/Services/Ai/CustomerSummaryAsyncService.php` (application code):

```php
<?php

namespace App\Services\Ai;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerSummaryAsyncService
{
    public function dispatchCustomerSummary(Request $request): array
    {
        $jobId = (string) Str::uuid();
        $customerId = $request->input('customer_id');

        // Dispatch your queued job (pseudo-code):
        // \App\Jobs\GenerateCustomerSummaryJob::dispatch($jobId, $customerId);

        return [
            'job_id' => $jobId,
            'status' => 'queued',
        ];
    }
}
```

### 3) Recommended: store results in a table

For queued AI results, store outputs in a table such as `ai_jobs` or `ai_results` and expose it via normal `sp-laravel-api` CRUD reads:

```http
GET /api/v1/ai_jobs?filter[job_id]=...
```

## Security Notes

- Always validate required params (`required_params` or validators).
- Keep tenant scoping enabled where applicable (`hasTenantId=true`).
- Apply per-function middleware if needed (rate limit, auth guard, etc.).

## Caching Notes

AI function responses are normal function responses. If caching is enabled for function endpoints, ensure you invalidate cache when underlying data changes.
