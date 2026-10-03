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

    /** @var array<string, true> */
    private array $operationIds = [];

    /** @param array<string, mixed> $document */
    public function __construct(private array $document)
    {
        $this->collectOperationIds();
    }

    /** @param array<string, mixed> $pathItem */
    public function addPath(string $path, array $pathItem): void
    {
        if (!str_starts_with($path, '/')) {
            throw new OpenApiContributionException(sprintf("paths.%s must begin with '/'.", $path));
        }

        if (isset($this->document['paths'][$path])) {
            throw new OpenApiContributionException(sprintf('paths.%s conflicts with an existing path.', $path));
        }

        $this->validatePathItem($path, $pathItem);
        $this->document['paths'][$path] = $pathItem;
    }

    /** @param array<string, mixed> $definition */
    public function addComponent(string $group, string $name, array $definition): void
    {
        if (!in_array($group, self::COMPONENT_GROUPS, true)) {
            throw new OpenApiContributionException(sprintf('components.%s is not supported by OpenAPI 3.0.3.', $group));
        }

        if (preg_match('/^[a-zA-Z0-9.\\-_]+$/', $name) !== 1) {
            throw new OpenApiContributionException(sprintf('components.%s.%s has an invalid name.', $group, $name));
        }

        if (isset($this->document['components'][$group][$name])) {
            throw new OpenApiContributionException(sprintf('components.%s.%s conflicts with an existing component.', $group, $name));
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
                throw new OpenApiContributionException(sprintf('tags.%s conflicts with an existing tag.', $name));
            }
        }

        $this->document['tags'][] = $tag;
    }

    public function addExtension(string $name, mixed $value): void
    {
        if (!str_starts_with($name, 'x-')) {
            throw new OpenApiContributionException(sprintf("extensions.%s must begin with 'x-'.", $name));
        }

        if (str_starts_with($name, 'x-sp-')) {
            throw new OpenApiContributionException(sprintf('extensions.%s is reserved for the package.', $name));
        }

        if (array_key_exists($name, $this->document)) {
            throw new OpenApiContributionException(sprintf('extensions.%s conflicts with an existing extension.', $name));
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
                throw new OpenApiContributionException(sprintf('paths.%s.%s must be an operation object.', $path, $key));
            }

            $summary = $operation['summary'] ?? null;
            if (!is_string($summary) || '' === trim($summary)) {
                throw new OpenApiContributionException(sprintf('paths.%s.%s.summary must be non-empty.', $path, $key));
            }

            $operationId = $operation['operationId'] ?? null;
            if (!is_string($operationId) || '' === trim($operationId)) {
                throw new OpenApiContributionException(sprintf('paths.%s.%s.operationId must be non-empty.', $path, $key));
            }

            if (isset($this->operationIds[$operationId])) {
                throw new OpenApiContributionException(sprintf('paths.%s.%s.operationId %s conflicts with an existing operation.', $path, $key, $operationId));
            }

            $responses = $operation['responses'] ?? null;
            if (!is_array($responses) || [] === $responses) {
                throw new OpenApiContributionException(sprintf('paths.%s.%s.responses must contain at least one response.', $path, $key));
            }

            $parameters = $pathParameters + $this->parametersByName($operation['parameters'] ?? [], $path);
            foreach ($this->pathPlaceholders($path) as $placeholder) {
                if (!isset($parameters[$placeholder])) {
                    throw new OpenApiContributionException(sprintf('paths.%s.%s requires path parameter %s.', $path, $key, $placeholder));
                }
            }

            $this->operationIds[$operationId] = true;
        }
    }

    /**
     * @return array<string, true>
     */
    private function parametersByName(mixed $parameters, string $path): array
    {
        if (!is_array($parameters)) {
            throw new OpenApiContributionException(sprintf('paths.%s.parameters must be an array.', $path));
        }

        $pathParameters = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            if ('path' !== ($parameter['in'] ?? null)) {
                continue;
            }

            $name = $parameter['name'] ?? null;
            if (!is_string($name) || '' === trim($name) || true !== ($parameter['required'] ?? false)) {
                throw new OpenApiContributionException(sprintf('paths.%s path parameters require name and required=true.', $path));
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
                if (!in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }

                if (!is_array($operation)) {
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
