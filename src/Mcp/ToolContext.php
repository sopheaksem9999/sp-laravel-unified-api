<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use InvalidArgumentException;
use Sopheak\Core\Services\RecordConfigService;
use Sopheak\Core\Utilities\RecordUtils;

/**
 * Who and which tenant one tool call runs as, when there is no request to say
 * so — a queued or background agent has neither a user nor a tenant header.
 *
 * {@see self::run()} installs them for the duration of the call and always puts
 * the process back: a worker reuses the same request object across jobs, so a
 * tenant left behind would be the next job's tenant.
 *
 * A context that is serialised (a queued agent carries its tools) stores the
 * user's key, never the user: a model held inside a tool would otherwise be
 * written out whole, attributes and password hash included. The user is
 * resolved again, lazily, through the configured guard's user provider.
 */
final class ToolContext
{
    private const TENANT_ATTRIBUTE = 'resolved_tenant_id';

    private mixed $userKey;

    private ?string $userClass;

    public function __construct(private ?Authenticatable $user = null, public readonly mixed $tenantId = null)
    {
        if ($this->user instanceof Authenticatable && null === $this->user->getAuthIdentifier()) {
            // Without a key the context could not name the user (or restore it after
            // serialising), and would silently fall back to the process's own user.
            throw new InvalidArgumentException('The user has no auth identifier (an unsaved model?); a ToolContext needs one to name who the call runs as.');
        }

        $this->userKey = $this->user?->getAuthIdentifier();
        $this->userClass = $this->user instanceof Authenticatable ? $this->user::class : null;
    }

    public static function none(): self
    {
        return new self();
    }

    public function withUser(Authenticatable $user): self
    {
        return new self($user, $this->tenantId);
    }

    public function withTenant(mixed $tenantId): self
    {
        $context = new self(null, $tenantId);
        $context->user = $this->user;
        $context->userKey = $this->userKey;
        $context->userClass = $this->userClass;

        return $context;
    }

    /**
     * The user this context runs as, resolved through the configured guard's
     * user provider when the context was restored from a serialised form.
     */
    public function user(): ?Authenticatable
    {
        if (!$this->user instanceof Authenticatable && null !== $this->userKey) {
            $guard = auth()->guard(RecordConfigService::authGuard());
            $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
            $retrieved = $provider?->retrieveById($this->userKey);

            // The same key can name different people under different providers (User #3,
            // Admin #3); only the class that was captured counts as the same person.
            $this->user = $retrieved instanceof Authenticatable && $retrieved::class === $this->userClass ? $retrieved : null;
        }

        return $this->user;
    }

    public function isEmpty(): bool
    {
        return null === $this->userKey && RecordUtils::isTenantIdMissing($this->tenantId);
    }

    /**
     * Run $callback as this context's user and tenant, then restore the guards'
     * users and the request's tenant attribute exactly as they were — also when
     * the callback throws.
     */
    public function run(Closure $callback): mixed
    {
        if ($this->isEmpty()) {
            return $callback();
        }

        $user = $this->user();
        // A context that names a user pins the identity. If that user cannot be resolved
        // (deleted, another provider, a guard without a user provider) the call must not
        // run: clearing the guards is not enough, because a session or token guard
        // re-resolves the request's own user on the next user() call.
        $pinsUser = null !== $this->userKey;
        if ($pinsUser && !$user instanceof Authenticatable) {
            throw new ToolError('Unauthenticated', -32001);
        }

        $guards = $this->guards();
        $previous = [];
        foreach ($guards as $name => $guard) {
            $previous[$name] = $guard->hasUser() ? $guard->user() : null;
        }

        $attributes = request()->attributes;
        $hadTenant = $attributes->has(self::TENANT_ATTRIBUTE);
        $previousTenant = $attributes->get(self::TENANT_ATTRIBUTE);

        try {
            if ($pinsUser) {
                foreach ($guards as $guard) {
                    $this->install($guard, $user);
                }
            }

            if (!RecordUtils::isTenantIdMissing($this->tenantId)) {
                $attributes->set(self::TENANT_ATTRIBUTE, $this->tenantId);
            }

            return $callback();
        } finally {
            if ($pinsUser) {
                foreach ($guards as $name => $guard) {
                    $this->install($guard, $previous[$name]);
                }
            }

            if ($hadTenant) {
                $attributes->set(self::TENANT_ATTRIBUTE, $previousTenant);
            } else {
                $attributes->remove(self::TENANT_ATTRIBUTE);
            }
        }
    }

    private function install(Guard $guard, ?Authenticatable $user): void
    {
        if ($user instanceof Authenticatable) {
            $guard->setUser($user);
        } elseif (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }
    }

    /**
     * @return array{tenantId: mixed, userKey: mixed, userClass: ?string}
     */
    public function __serialize(): array
    {
        return ['tenantId' => $this->tenantId, 'userKey' => $this->userKey, 'userClass' => $this->userClass];
    }

    /**
     * @param array{tenantId: mixed, userKey: mixed, userClass?: ?string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->tenantId = $data['tenantId'];
        $this->userKey = $data['userKey'];
        $this->userClass = $data['userClass'] ?? null;
        $this->user = null;
    }

    /**
     * The guard the package authorizes with and the default guard, which
     * OwnRecordsScope reads.
     *
     * @return array<string, Guard>
     */
    private function guards(): array
    {
        $names = array_unique(array_filter([RecordConfigService::authGuard(), config('auth.defaults.guard')]));

        $guards = [];
        foreach ($names as $name) {
            $guards[(string) $name] = auth()->guard((string) $name);
        }

        return $guards;
    }
}
