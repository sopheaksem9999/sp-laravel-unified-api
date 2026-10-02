<?php

declare(strict_types=1);

namespace Sopheak\Core\Mcp;

final readonly class ToolResult
{
    /**
     * @param array<string, mixed>|null $structuredContent
     */
    private function __construct(
        public ?array $structuredContent,
        public mixed $legacyContent,
        public bool $isError,
        public ?string $message = null,
    ) {}

    /**
     * @param array<string, mixed> $structuredContent
     */
    public static function ok(array $structuredContent, mixed $legacyContent): self
    {
        return new self($structuredContent, $legacyContent, false);
    }

    public static function error(string $message): self
    {
        return new self(null, null, true, $message);
    }

    /**
     * The text copy is compact JSON: structuredContent already carries the
     * same value, and pretty-printing it more than doubled every response.
     *
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        if ($this->isError) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => (string) $this->message]]];
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($this->legacyContent, JSON_UNESCAPED_SLASHES),
                ],
            ],
            'structuredContent' => $this->structuredContent,
        ];
    }
}
