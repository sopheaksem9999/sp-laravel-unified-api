<?php

declare(strict_types=1);

namespace Sopheak\Core\Ai;

use Illuminate\JsonSchema\Types\Type;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Sopheak\Core\Mcp\ToolContext;
use Sopheak\Core\Mcp\ToolDefinition;
use Sopheak\Core\Mcp\ToolError;
use Sopheak\Core\Mcp\ToolExecutor;
use RuntimeException;
use Throwable;

/**
 * One Laravel AI SDK tool over one catalog definition. It holds no executor and
 * no user, so a queued agent can serialise it; every call goes through
 * ToolExecutor, which makes the tenant, permission, viewOwn, hidden-column and
 * nested-write decisions MCP and HTTP get — there is no shortcut into
 * RecordService.
 *
 * Writes request a person's approval; reads never do.
 */
final class RecordTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    private const WRITES = ['create', 'update', 'delete'];

    public function __construct(
        private readonly ToolDefinition $definition,
        private readonly ?ToolContext $context = null,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function description(): string
    {
        return $this->definition->description;
    }

    public function action(): string
    {
        return $this->definition->action;
    }

    public function table(): ?string
    {
        return $this->definition->table;
    }

    public function context(): ?ToolContext
    {
        return $this->context;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return SchemaConverter::properties($this->definition->name, $this->definition->inputSchema);
    }

    /**
     * What the model sees. A refusal (forbidden, unauthenticated, tenant
     * refused, unknown table), a failed record operation or an argument of the
     * wrong shape is returned as an `{"error": …}` string so the model can
     * explain or fix it and the run continues.
     *
     * Anything unexpected is reported to the application and rethrown with a
     * message that names only the tool: laravel/ai turns a failure on the
     * approval-resume path into a tool result the model reads, and the original
     * message (SQL, file paths, values) must not travel with it.
     */
    public function handle(Request $request): string
    {
        try {
            $arguments = $this->arguments($request->all());
            if (is_string($arguments)) {
                return $this->encode(['error' => ['message' => $arguments]]);
            }

            $result = (new ToolExecutor(honourReadOnly: false))->call($this->definition->name, $arguments, $this->context);

            if ($result->isError) {
                return $this->encode(['error' => ['message' => (string) $result->message]]);
            }

            return $this->encode($result->structuredContent);
        } catch (ToolError $error) {
            return $this->encode(['error' => ['code' => $error->getCode(), 'message' => $error->getMessage()]]);
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(sprintf("The '%s' tool failed unexpectedly; see the application log.", $this->definition->name), 0, $exception);
        }
    }

    /**
     * Decode the parameters that travel as JSON text and check every argument
     * has the shape the executor expects. A wrong shape would otherwise reach
     * RecordService as a TypeError and fail the whole run.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|string the arguments, or the message to send back to the model
     */
    private function arguments(array $arguments): array|string
    {
        foreach (SchemaConverter::jsonStringProperties($this->definition->inputSchema) as $name) {
            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            $value = $arguments[$name];
            if (null === $value || (is_string($value) && '' === trim($value))) {
                unset($arguments[$name]);

                continue;
            }

            if (is_string($value)) {
                $value = json_decode($value, true);
            }

            if (!is_array($value) || ([] !== $value && array_is_list($value))) {
                return sprintf('%s must be a JSON-encoded object, for example {"limit": 10}.', $name);
            }

            if ([] === $value) {
                unset($arguments[$name]);
            } else {
                $arguments[$name] = $value;
            }
        }

        if (array_key_exists('id', (array) ($this->definition->inputSchema['properties'] ?? []))) {
            $id = $arguments['id'] ?? null;
            if (!is_int($id) && !(is_string($id) && '' !== trim($id))) {
                return 'id is required and must be a string or an integer.';
            }
        }

        return $arguments;
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        if (!in_array($this->definition->action, self::WRITES, true)) {
            return false;
        }

        return Approval::required($this->defaultReason());
    }

    /**
     * The reason shown to the person asked to approve a write.
     */
    public function defaultReason(): string
    {
        return sprintf('%s on %s changes data', $this->definition->action, $this->definition->table);
    }

    /**
     * Compact JSON for the model. Invalid UTF-8 (a binary or latin-1 column) is
     * substituted rather than turning the whole result into an empty string, and
     * an unencodable value (NAN) throws instead of returning nothing.
     *
     * @param array<string, mixed>|null $payload
     */
    private function encode(?array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
