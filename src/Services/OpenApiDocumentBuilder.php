<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Sopheak\Core\Exceptions\OpenApiContributionException;

class OpenApiDocumentBuilder
{
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private const COMPONENT_GROUPS = [
        'schemas', 'responses', 'parameters', 'examples', 'requestBodies',
        'headers', 'securitySchemes', 'links', 'callbacks',
    ];

    /** @var array<string, mixed> */
    private array $document;

    /** @var array<string, true> */
    private array $operationIds = [];

    /** @param array<string, mixed> $document */
    public function __construct(array $document)
    {
        $this->document = $document;
        $this->collectOperationIds();
    }

    /** @param array<string, mixed> $pathItem */
    public function addPath(string $path, array $pathItem): void
    {
        if (!str_starts_with($path, '/')) {
            throw new OpenApiContributionException("paths.{$path} must begin with '/'.");
        }

        if (isset($this->document['paths'][$path])) {
            throw new OpenApiContributionException("paths.{$path} conflicts with an existing path.");
        }

        $this->validatePathItem($path, $pathItem);
        $this->document['paths'][$path] = $pathItem;
    }

    /** @param array<string, mixed> $definition */
    public function addComponent(string $group, string $name, array $definition): void
    {
        if (!in_array($group, self::COMPONENT_GROUPS, true)) {
            throw new OpenApiContributionException("components.{$group} is not supported by OpenAPI 3.0.3.");
        }

        if (preg_match('/^[a-zA-Z0-9.\\-_]+$/', $name) !== 1) {
            throw new OpenApiContributionException("components.{$group}.{$name} has an invalid name.");
        }

        if (isset($this->document['components'][$group][$name])) {
            throw new OpenApiContributionException("components.{$group}.{$name} conflicts with an existing component.");
        }

        $this->document['components'][$group][$name] = $definition;
    }

    /** @param array<string, mixed> $tag */
    public function addTag(array $tag): void
    {
        $name = $tag['name'] ?? null;
        if (!is_string($name) || '' === trim($name)) {
            throw new OpenApiContributionException('tags entries require a non-empty name.');
        }

        foreach ($this->document['tags'] ?? [] as $existingTag) {
            if (is_array($existingTag) && ($existingTag['name'] ?? null) === $name) {
                throw new OpenApiContributionException("tags.{$name} conflicts with an existing tag.");
            }
        }

        $this->document['tags'][] = $tag;
    }

    public function addExtension(string $name, mixed $value): void
    {
        if (!str_starts_with($name, 'x-')) {
            throw new OpenApiContributionException("extensions.{$name} must begin with 'x-'.");
        }

        if (str_starts_with($name, 'x-sp-')) {
            throw new OpenApiContributionException("extensions.{$name} is reserved for the package.");
        }

        if (array_key_exists($name, $this->document)) {
            throw new OpenApiContributionException("extensions.{$name} conflicts with an existing extension.");
        }

        $this->document[$name] = $value;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }

    /** @param array<string, mixed> $pathItem */
    private function validatePathItem(string $path, array $pathItem): void
    {
        $pathParameters = $this->parametersByName($pathItem['parameters'] ?? [], $path);

        foreach ($pathItem as $key => $operation) {
            if (!in_array($key, self::HTTP_METHODS, true)) {
                continue;
            }

            if (!is_array($operation)) {
                throw new OpenApiContributionException("paths.{$path}.{$key} must be an operation object.");
            }

            $summary = $operation['summary'] ?? null;
            if (!is_string($summary) || '' === trim($summary)) {
                throw new OpenApiContributionException("paths.{$path}.{$key}.summary must be non-empty.");
            }

            $operationId = $operation['operationId'] ?? null;
            if (!is_string($operationId) || '' === trim($operationId)) {
                throw new OpenApiContributionException("paths.{$path}.{$key}.operationId must be non-empty.");
            }

            if (isset($this->operationIds[$operationId])) {
                throw new OpenApiContributionException("paths.{$path}.{$key}.operationId {$operationId} conflicts with an existing operation.");
            }

            $responses = $operation['responses'] ?? null;
            if (!is_array($responses) || [] === $responses) {
                throw new OpenApiContributionException("paths.{$path}.{$key}.responses must contain at least one response.");
            }

            $parameters = $pathParameters + $this->parametersByName($operation['parameters'] ?? [], $path);
            foreach ($this->pathPlaceholders($path) as $placeholder) {
                if (!isset($parameters[$placeholder])) {
                    throw new OpenApiContributionException("paths.{$path}.{$key} requires path parameter {$placeholder}.");
                }
            }

            $this->operationIds[$operationId] = true;
        }
    }

    /** @param mixed $parameters
     * @return array<string, true>
     */
    private function parametersByName(mixed $parameters, string $path): array
    {
        if (!is_array($parameters)) {
            throw new OpenApiContributionException("paths.{$path}.parameters must be an array.");
        }

        $pathParameters = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter) || 'path' !== ($parameter['in'] ?? null)) {
                continue;
            }

            $name = $parameter['name'] ?? null;
            if (!is_string($name) || '' === trim($name) || true !== ($parameter['required'] ?? false)) {
                throw new OpenApiContributionException("paths.{$path} path parameters require name and required=true.");
            }

            $pathParameters[$name] = true;
        }

        return $pathParameters;
    }

    /** @return array<string> */
    private function pathPlaceholders(string $path): array
    {
        preg_match_all('/\\{([^}]+)\\}/', $path, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function collectOperationIds(): void
    {
        foreach ($this->document['paths'] ?? [] as $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (!in_array($method, self::HTTP_METHODS, true) || !is_array($operation)) {
                    continue;
                }

                $operationId = $operation['operationId'] ?? null;
                if (is_string($operationId) && '' !== $operationId) {
                    $this->operationIds[$operationId] = true;
                }
            }
        }
    }
}
