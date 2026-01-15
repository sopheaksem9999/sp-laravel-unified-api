<?php

namespace Sopheak\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Services\RecordService;
use Throwable;

class ProcessBulkOperationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param string $operation Operation type (create, update, delete)
     * @param string $table Target table name
     * @param array $items Array of items to process
     * @param mixed $tenantId Tenant ID
     * @param array $requestContext Request context (headers, user_id, etc.)
     */
    public function __construct(
        protected string $operation,
        protected string $table,
        protected array $items,
        protected mixed $tenantId,
        protected array $requestContext = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(RecordService $recordService): void
    {
        try {
            // Reconstruct Request
            $request = new Request();
            $request->merge(['items' => $this->items]);

            if (isset($this->requestContext['headers'])) {
                $request->headers->replace($this->requestContext['headers']);
            }

            if (isset($this->requestContext['server'])) {
                $request->server->replace($this->requestContext['server']);
            }

            // Restore Authentication Context
            if (isset($this->requestContext['user_id'])) {
                $guard = $this->requestContext['guard'] ?? RecordConfigService::authGuard();
                try {
                    $guardInstance = Auth::guard($guard);
                    $user = null;

                    if (method_exists($guardInstance, 'loginUsingId')) {
                        $user = $guardInstance->loginUsingId($this->requestContext['user_id']);
                    } else {
                        // Handle stateless guards (e.g., Sanctum, API)
                        $providerName = config(sprintf('auth.guards.%s.provider', $guard));
                        $provider = $providerName ? Auth::createUserProvider($providerName) : null;

                        if ($provider) {
                            $user = $provider->retrieveById($this->requestContext['user_id']);
                            if ($user) {
                                $guardInstance->setUser($user);
                            }
                        }
                    }

                    $request->setUserResolver(fn() => Auth::guard($guard)->user());
                } catch (Throwable $e) {
                    Log::warning("ProcessBulkOperationJob: Failed to restore user session: " . $e->getMessage());
                }
            }

            // Execute Bulk Operation
            $recordService->bulkRecord($request, $this->table, $this->tenantId, $this->operation);
        } catch (Throwable $throwable) {
            Log::error("ProcessBulkOperationJob Failed", [
                'table' => $this->table,
                'operation' => $this->operation,
                'error' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString()
            ]);

            // Re-throw to ensure job is marked as failed in queue
            throw $throwable;
        }
    }
}
