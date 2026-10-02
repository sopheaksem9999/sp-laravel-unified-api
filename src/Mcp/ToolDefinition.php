<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

/**
 * One MCP tool, independent of any transport. The `legacy` JSON-RPC server and
 * the `laravel` driver both render it.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $outputSchema
     * @param array<string, bool>  $annotations
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
        public string $action,
        public ?string $table = null,
        public ?string $title = null,
        public array $annotations = [],
    ) {}

    /**
     * Legacy keys first, in their historical order, then the additive ones.
     *
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        $wire = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
        ];

        if (null !== $this->title) {
            $wire['title'] = $this->title;
        }

        if ([] !== $this->annotations) {
            $wire['annotations'] = $this->annotations;
        }

        return $wire;
    }
}
