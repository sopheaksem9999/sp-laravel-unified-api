<?php

namespace Sopheak\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\RecordUtils;

class SetPostgresTenantContext
{
    private const REQUEST_APPLIED_CONTEXTS_KEY = 'record_pgsql_tenant_context_applied';

    private const REQUEST_STATS_KEY = 'record_pgsql_tenant_context_stats';

    public function handle(Request $request, Closure $next)
    {
        $mode = RecordConfigService::pgsqlTenantContextMode();
        if ('off' === $mode) {
            $this->incrementStat($request, 'skipped_off');

            return $next($request);
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->incrementStat($request, 'skipped_driver');

            return $next($request);
        }

        $tenantId = RecordUtils::resolveTenantIdFromRequest($request);
        $normalizedTenantId = $tenantId !== null && $tenantId !== '' ? (string) $tenantId : '';

        if ($this->shouldSkipDuplicate($request, $mode, $normalizedTenantId)) {
            $this->incrementStat($request, 'skipped_duplicate');

            return $next($request);
        }

        $this->applyTenantContext($mode, $normalizedTenantId);
        $this->markApplied($request, $mode, $normalizedTenantId);
        $this->incrementStat($request, 'set');

        return $next($request);
    }

    private function applyTenantContext(string $mode, string $tenantId): void
    {
        if ('transaction_local' === $mode) {
            DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', '' !== $tenantId ? $tenantId : null]);

            return;
        }

        if ('' !== $tenantId) {
            DB::statement('SET SESSION app.tenant_id = ?', [$tenantId]);

            return;
        }

        DB::statement('SET SESSION app.tenant_id = NULL');
    }

    private function shouldSkipDuplicate(Request $request, string $mode, string $tenantId): bool
    {
        if ('session_once' !== $mode && 'transaction_local' !== $mode) {
            return false;
        }

        $applied = $request->attributes->get(self::REQUEST_APPLIED_CONTEXTS_KEY, []);

        return is_array($applied) && isset($applied[$this->contextKey($mode, $tenantId)]);
    }

    private function markApplied(Request $request, string $mode, string $tenantId): void
    {
        $applied = $request->attributes->get(self::REQUEST_APPLIED_CONTEXTS_KEY, []);
        $applied = is_array($applied) ? $applied : [];
        $applied[$this->contextKey($mode, $tenantId)] = true;
        $request->attributes->set(self::REQUEST_APPLIED_CONTEXTS_KEY, $applied);
    }

    private function contextKey(string $mode, string $tenantId): string
    {
        return $mode . ':default:' . $tenantId;
    }

    private function incrementStat(Request $request, string $key): void
    {
        $stats = $request->attributes->get(self::REQUEST_STATS_KEY, []);
        $stats = is_array($stats) ? $stats : [];
        $stats[$key] = (int) ($stats[$key] ?? 0) + 1;
        $request->attributes->set(self::REQUEST_STATS_KEY, $stats);
    }
}
