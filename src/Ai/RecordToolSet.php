<?php

declare(strict_types=1);

namespace Sopheak\Core\Ai;

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use IteratorAggregate;
use Sopheak\Core\Mcp\ToolContext;
use Sopheak\Core\Mcp\ToolDefinition;
use Traversable;

/**
 * An immutable set of record tools for an agent's `tools()`; spread it:
 * `...RecordTools::for('invoices')->only(['list', 'create'])`.
 *
 * Every method returns a new set. The tools are built when the set is iterated,
 * so a context or approval choice made after `only()` still reaches them.
 *
 * @implements IteratorAggregate<int, RecordTool>
 * @implements Arrayable<int, RecordTool>
 */
final readonly class RecordToolSet implements Arrayable, Countable, IteratorAggregate
{
    /** The data actions a set can be narrowed by. */
    public const ACTIONS = ['list', 'read', 'create', 'update', 'delete'];

    private const WRITES = ['create', 'update', 'delete'];

    private const APPROVAL_DEFAULT = 'default';

    private const APPROVAL_NONE = 'none';

    private const APPROVAL_REQUIRED = 'required';

    /**
     * @param list<ToolDefinition> $definitions
     */
    private function __construct(
        private array $definitions,
        private string $approval = self::APPROVAL_DEFAULT,
        private ?string $reason = null,
        private ?ToolContext $context = null,
    ) {}

    /**
     * @param list<ToolDefinition> $definitions
     */
    public static function of(array $definitions): self
    {
        return new self(array_values($definitions));
    }

    /**
     * Keep only these actions. An action a table in the set does not allow is
     * an error, not a silent omission: asking for `create` on a table whose
     * `canCreate` is false would otherwise hand the developer a set that quietly
     * lacks what they wrote.
     *
     * @param list<string> $actions
     */
    public function only(array $actions): self
    {
        $this->assertActions($actions);

        $kept = array_values(array_filter($this->definitions, static fn(ToolDefinition $d): bool => in_array($d->action, $actions, true)));

        foreach (array_unique(array_filter(array_map(static fn(ToolDefinition $d): ?string => $d->table, $this->definitions))) as $table) {
            foreach ($actions as $action) {
                $present = array_filter($kept, static fn(ToolDefinition $d): bool => $d->table === $table && $d->action === $action);
                if ([] === $present) {
                    throw new InvalidArgumentException(sprintf("Action '%s' is not available for table '%s' in this set (its can* flag or an earlier only()/except() excludes it).", $action, $table));
                }
            }
        }

        return $this->with(definitions: $kept);
    }

    /**
     * @param list<string> $actions
     */
    public function except(array $actions): self
    {
        $this->assertActions($actions);

        return $this->with(definitions: array_values(array_filter(
            $this->definitions,
            static fn(ToolDefinition $d): bool => !in_array($d->action, $actions, true),
        )));
    }

    /**
     * Writes run without a person's decision. Reads never needed one.
     */
    public function withoutApproval(): self
    {
        return $this->with(approval: self::APPROVAL_NONE, reason: null);
    }

    /**
     * Writes wait for a person's decision (the default), optionally with your
     * own reason shown to the approver.
     */
    public function requireApproval(?string $reason = null): self
    {
        return $this->with(approval: self::APPROVAL_REQUIRED, reason: $reason);
    }

    /**
     * Run the tools as this user, for agents with no request (queued, commands).
     */
    public function actingAs(Authenticatable $user): self
    {
        return $this->with(context: ($this->context ?? ToolContext::none())->withUser($user));
    }

    /**
     * Run the tools in this tenant, for agents with no request.
     */
    public function forTenant(mixed $tenantId): self
    {
        return $this->with(context: ($this->context ?? ToolContext::none())->withTenant($tenantId));
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_map(static fn(ToolDefinition $d): string => $d->name, $this->definitions);
    }

    /**
     * @return ArrayIterator<int, RecordTool>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * @return array<int, RecordTool>
     */
    public function toArray(): array
    {
        RecordTools::assertInstalled();

        $tools = [];
        foreach ($this->definitions as $definition) {
            $tool = new RecordTool($definition, $this->context);

            if (in_array($definition->action, self::WRITES, true)) {
                if (self::APPROVAL_NONE === $this->approval) {
                    $tool->withoutApproval();
                } elseif (self::APPROVAL_REQUIRED === $this->approval) {
                    $tool->requireApproval($this->reason ?? $tool->defaultReason());
                }
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * @param list<string> $actions
     */
    private function assertActions(array $actions): void
    {
        foreach ($actions as $action) {
            if (!in_array($action, self::ACTIONS, true)) {
                throw new InvalidArgumentException(sprintf("Unknown action '%s'. Valid actions: %s.", $action, implode(', ', self::ACTIONS)));
            }
        }
    }

    /**
     * @param list<ToolDefinition>|null $definitions
     */
    private function with(?array $definitions = null, ?string $approval = null, ?string $reason = null, ?ToolContext $context = null): self
    {
        return new self(
            $definitions ?? $this->definitions,
            $approval ?? $this->approval,
            null === $approval ? $this->reason : $reason,
            $context ?? $this->context,
        );
    }
}
