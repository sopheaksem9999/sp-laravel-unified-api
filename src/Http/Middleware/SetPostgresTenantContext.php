<?php

namespace Sopheak\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Utilities\RecordUtils;

class SetPostgresTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $next($request);
        }

        $tenantId = RecordUtils::resolveTenantIdFromRequest($request);

        if ($tenantId !== null && $tenantId !== '') {
            DB::statement('SET SESSION app.tenant_id = ?', [$tenantId]);
        } else {
            DB::statement('SET SESSION app.tenant_id = NULL');
        }

        return $next($request);
    }
}
