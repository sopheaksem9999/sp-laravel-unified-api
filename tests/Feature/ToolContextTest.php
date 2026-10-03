<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Sopheak\Core\Mcp\ToolContext;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Tests\TestCase;

/**
 * A ToolContext gives a tool call an explicit user and tenant (a queued agent
 * has no request and no user) and must put the process back exactly as it
 * found it: workers reuse the request object across jobs.
 *
 * @internal
 */
class ToolContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('password')->nullable();
        });
        DB::table('users')->insert([
            ['id' => 5, 'name' => 'Five', 'password' => 'SECRET-HASH'],
            ['id' => 6, 'name' => 'Six', 'password' => 'OTHER-HASH'],
        ]);
    }

    private function user(int $id = 5): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'u' . $id, 'password' => 'SECRET-HASH']);
    }

    private function configuredGuard(): Guard
    {
        return auth()->guard(RecordConfigService::authGuard());
    }

    public function test_run_installs_the_user_on_both_guards_and_the_tenant_then_restores_them(): void
    {
        $seen = null;
        $result = (new ToolContext($this->user(), 't1'))->run(function () use (&$seen): string {
            $seen = [
                'configured' => $this->configuredGuard()->user()?->getAuthIdentifier(),
                'default' => auth()->guard()->user()?->getAuthIdentifier(),
                'tenant' => request()->attributes->get('resolved_tenant_id'),
            ];

            return 'value';
        });

        $this->assertSame('value', $result);
        $this->assertSame(['configured' => 5, 'default' => 5, 'tenant' => 't1'], $seen);
        $this->assertFalse($this->configuredGuard()->hasUser());
        $this->assertFalse(auth()->guard()->hasUser());
        $this->assertFalse(request()->attributes->has('resolved_tenant_id'));
    }

    public function test_run_restores_a_previous_user_and_tenant(): void
    {
        $this->configuredGuard()->setUser($this->user(5));
        request()->attributes->set('resolved_tenant_id', 't1');

        (new ToolContext($this->user(6), 't2'))->run(function (): void {
            $this->assertSame(6, $this->configuredGuard()->user()->getAuthIdentifier());
            $this->assertSame('t2', request()->attributes->get('resolved_tenant_id'));
        });

        $this->assertSame(5, $this->configuredGuard()->user()->getAuthIdentifier());
        $this->assertSame('t1', request()->attributes->get('resolved_tenant_id'));
    }

    public function test_run_restores_when_the_callback_throws(): void
    {
        $this->configuredGuard()->setUser($this->user(5));

        try {
            (new ToolContext($this->user(6), 't2'))->run(static function (): never {
                throw new RuntimeException('boom');
            });
            $this->fail('the exception must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('boom', $runtimeException->getMessage());
        }

        $this->assertSame(5, $this->configuredGuard()->user()->getAuthIdentifier());
        $this->assertFalse(auth()->guard()->hasUser());
        $this->assertFalse(request()->attributes->has('resolved_tenant_id'));
    }

    public function test_an_empty_context_changes_nothing(): void
    {
        $context = ToolContext::none();

        $this->assertTrue($context->isEmpty());
        $this->assertSame('x', $context->run(function (): string {
            $this->assertFalse($this->configuredGuard()->hasUser());
            $this->assertFalse(request()->attributes->has('resolved_tenant_id'));

            return 'x';
        }));
    }

    public function test_a_context_with_only_a_tenant_leaves_the_current_user_alone(): void
    {
        $this->configuredGuard()->setUser($this->user(5));

        (new ToolContext(null, 't9'))->run(function (): void {
            $this->assertSame(5, $this->configuredGuard()->user()->getAuthIdentifier());
            $this->assertSame('t9', request()->attributes->get('resolved_tenant_id'));
        });

        $this->assertSame(5, $this->configuredGuard()->user()->getAuthIdentifier());
    }

    public function test_withers_are_immutable(): void
    {
        $empty = ToolContext::none();
        $withUser = $empty->withUser($this->user());
        $withBoth = $withUser->withTenant('t1');

        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($withUser->isEmpty());
        $this->assertNull($withUser->tenantId);
        $this->assertSame('t1', $withBoth->tenantId);
        $this->assertSame(5, $withBoth->user()->getAuthIdentifier());
    }

    public function test_serialising_stores_a_key_not_the_user(): void
    {
        $serialized = serialize((new ToolContext($this->user(), 't1')));

        $this->assertStringNotContainsString('SECRET-HASH', $serialized);
        // The class name is stored as text (like SerializesModels), never the user object.
        $this->assertStringNotContainsString('O:27:"Illuminate\\Auth\\GenericUser"', $serialized);
        $this->assertStringNotContainsString('"name"', $serialized);

        $restored = unserialize($serialized);
        $this->assertSame('t1', $restored->tenantId);
        $this->assertSame(5, $restored->user()->getAuthIdentifier());
        $this->assertSame('Five', $restored->user()->name);
    }

    public function test_a_context_whose_user_no_longer_exists_has_no_user(): void
    {
        $serialized = serialize(new ToolContext($this->user(5), 't1'));
        DB::table('users')->where('id', 5)->delete();

        $restored = unserialize($serialized);

        $this->assertNull($restored->user());
        $this->assertSame('t1', $restored->tenantId);
    }

    public function test_a_context_whose_user_is_gone_refuses_to_run_instead_of_using_the_process_user(): void
    {
        $serialized = serialize(new ToolContext($this->user(5), 't1'));
        DB::table('users')->where('id', 5)->delete();
        $this->configuredGuard()->setUser($this->user(6));
        $ran = false;

        try {
            unserialize($serialized)->run(function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('a pinned identity that cannot be resolved must not run');
        } catch (ToolError $toolError) {
            $this->assertSame(-32001, $toolError->getCode());
            $this->assertSame('Unauthenticated', $toolError->getMessage());
        }

        $this->assertFalse($ran, 'the callback must not run as whoever the process holds');
        $this->assertSame(6, $this->configuredGuard()->user()->getAuthIdentifier());
        $this->assertFalse(request()->attributes->has('resolved_tenant_id'));
    }

    public function test_a_session_authenticated_request_cannot_lend_its_user_to_a_context_with_a_missing_user(): void
    {
        // forgetUser() on a session/token guard only clears the cached user; the next
        // user() call re-resolves the request's own user. The call must not get that far.
        $serialized = serialize(new ToolContext($this->user(5)));
        DB::table('users')->where('id', 5)->delete();
        $this->configuredGuard()->setUser($this->user(6));
        $seen = null;

        try {
            unserialize($serialized)->run(function () use (&$seen): void {
                $seen = $this->configuredGuard()->user()?->getAuthIdentifier();
            });
        } catch (ToolError) {
            // expected
        }

        $this->assertNull($seen);
    }

    public function test_the_class_of_the_stored_user_is_verified_on_restore(): void
    {
        // Two providers can hold different people under the same key (User #3, Admin #3).
        $serialized = serialize(new ToolContext($this->user(5)));

        Auth::provider('someone-else', static fn(): UserProvider => new class implements UserProvider {
            public function retrieveById($identifier): Authenticatable
            {
                return new class implements Authenticatable {
                    public function getAuthIdentifierName(): string
                    {
                        return 'id';
                    }

                    public function getAuthIdentifier(): int
                    {
                        return 5;
                    }

                    public function getAuthPasswordName(): string
                    {
                        return 'password';
                    }

                    public function getAuthPassword(): string
                    {
                        return '';
                    }

                    public function getRememberToken(): ?string
                    {
                        return null;
                    }

                    public function setRememberToken($value): void {}

                    public function getRememberTokenName(): string
                    {
                        return 'remember_token';
                    }
                };
            }

            public function retrieveByToken($identifier, $token): ?Authenticatable
            {
                return null;
            }

            public function updateRememberToken(Authenticatable $user, $token): void {}

            public function retrieveByCredentials(array $credentials): ?Authenticatable
            {
                return null;
            }

            public function validateCredentials(Authenticatable $user, array $credentials): bool
            {
                return false;
            }

            public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): bool
            {
                return false;
            }
        });
        config(['auth.providers.users' => ['driver' => 'someone-else']]);
        app('auth')->forgetGuards();

        $this->assertNull(unserialize($serialized)->user(), 'a different class under the same key is a different person');
    }

    public function test_a_user_without_an_identifier_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('identifier');

        new ToolContext(new GenericUser(['id' => null, 'name' => 'unsaved']));
    }
}
