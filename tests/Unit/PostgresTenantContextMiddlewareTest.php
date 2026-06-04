<?php

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Sopheak\Core\Http\Middleware\SetPostgresTenantContext;
use Sopheak\Core\Tests\TestCase;

class PostgresTenantContextMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('record.tenant_header', 'X-Tenant-ID');
        Config::set('record.pgsql_tenant_context.mode', 'session');
    }

    public function test_session_mode_preserves_existing_set_session_behavior(): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
        DB::shouldReceive('statement')
            ->once()
            ->with('SET SESSION app.tenant_id = ?', ['acme']);

        $request = Request::create('/api/users', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme']);

        $response = app(SetPostgresTenantContext::class)->handle($request, fn() => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_session_once_mode_skips_duplicate_context_for_same_request(): void
    {
        Config::set('record.pgsql_tenant_context.mode', 'session_once');

        DB::shouldReceive('getDriverName')->twice()->andReturn('pgsql');
        DB::shouldReceive('statement')
            ->once()
            ->with('SET SESSION app.tenant_id = ?', ['acme']);

        $middleware = app(SetPostgresTenantContext::class);
        $request = Request::create('/api/users', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme']);

        $middleware->handle($request, fn() => response('first'));
        $middleware->handle($request, fn() => response('second'));

        $stats = $request->attributes->get('record_pgsql_tenant_context_stats');

        $this->assertIsArray($stats);
        $this->assertSame(1, $stats['set']);
        $this->assertSame(1, $stats['skipped_duplicate']);
    }

    public function test_off_mode_skips_tenant_context_sql(): void
    {
        Config::set('record.pgsql_tenant_context.mode', 'off');

        DB::shouldReceive('getDriverName')->never();
        DB::shouldReceive('statement')->never();

        $request = Request::create('/api/users', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme']);

        $response = app(SetPostgresTenantContext::class)->handle($request, fn() => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_transaction_local_mode_uses_request_scoped_set_config(): void
    {
        Config::set('record.pgsql_tenant_context.mode', 'transaction_local');

        DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT set_config(?, ?, true)', ['app.tenant_id', 'acme']);

        $request = Request::create('/api/users', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme']);

        $response = app(SetPostgresTenantContext::class)->handle($request, fn() => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }
}
