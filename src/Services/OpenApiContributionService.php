<?php

declare(strict_types=1);

namespace Sopheak\Core\Services;

use Sopheak\Core\Contracts\OpenApiDocumentContributorInterface;
use Sopheak\Core\Exceptions\OpenApiContributionException;
use Throwable;

class OpenApiContributionService
{
    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function apply(array $document): array
    {
        $contributions = config('sp-laravel-api.openapi.contributions', []);
        if (!is_array($contributions) || [] === $contributions) {
            return $document;
        }

        $builder = new OpenApiDocumentBuilder($document);

        foreach ($contributions['components'] ?? [] as $group => $definitions) {
            if (!is_array($definitions)) {
                throw new OpenApiContributionException("components.{$group} must be an array.");
            }

            foreach ($definitions as $name => $definition) {
                if (!is_string($name) || !is_array($definition)) {
                    throw new OpenApiContributionException("components.{$group} entries must use string names and array definitions.");
                }

                $builder->addComponent($group, $name, $definition);
            }
        }

        foreach ($contributions['tags'] ?? [] as $tag) {
            if (!is_array($tag)) {
                throw new OpenApiContributionException('tags entries must be arrays.');
            }

            $builder->addTag($tag);
        }

        foreach ($contributions['paths'] ?? [] as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                throw new OpenApiContributionException('paths entries must use string paths and array path items.');
            }

            $builder->addPath($path, $pathItem);
        }

        foreach ($contributions['extensions'] ?? [] as $name => $value) {
            if (!is_string($name)) {
                throw new OpenApiContributionException('extension names must be strings.');
            }

            $builder->addExtension($name, $value);
        }

        foreach ($contributions['contributors'] ?? [] as $class) {
            $this->applyContributor($builder, $class);
        }

        $document = $builder->document();
        $this->validateLocalComponentReferences($document, $document);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, array<string, mixed>> $packageChannels
     * @return array<int, array<string, mixed>>
     */
    public function realtimeChannels(array $document, array $packageChannels): array
    {
        $channels = config('sp-laravel-api.openapi.realtime.channels', []);
        if (!is_array($channels)) {
            throw new OpenApiContributionException('realtime.channels must be an array.');
        }

        $names = [];
        foreach ($packageChannels as $channel) {
            if (is_string($channel['name'] ?? null)) {
                $names[$channel['name']] = true;
            }
        }

        foreach ($channels as $index => $channel) {
            if (!is_array($channel)) {
                throw new OpenApiContributionException("realtime.channels.{$index} must be an array.");
            }

            $name = $channel['name'] ?? null;
            if (!is_string($name) || '' === trim($name)) {
                throw new OpenApiContributionException("realtime.channels.{$index}.name must be non-empty.");
            }

            if (isset($names[$name])) {
                throw new OpenApiContributionException("realtime.channels.{$index}.name {$name} conflicts with an existing channel.");
            }

            $this->validateRealtimeChannel($channel, "realtime.channels.{$index}", $document);
            $names[$name] = true;
            $packageChannels[] = $channel;
        }

        return $packageChannels;
    }

    private function applyContributor(OpenApiDocumentBuilder $builder, mixed $class): void
    {
        if (!is_string($class) || '' === trim($class)) {
            throw new OpenApiContributionException('contributors entries must be non-empty class strings.');
        }

        try {
            $contributor = app($class);
        } catch (Throwable $throwable) {
            throw new OpenApiContributionException("Contributor {$class} could not be resolved: {$throwable->getMessage()}", previous: $throwable);
        }

        if (!$contributor instanceof OpenApiDocumentContributorInterface) {
            throw new OpenApiContributionException("Contributor {$class} must implement OpenApiDocumentContributorInterface.");
        }

        try {
            $contributor->contribute($builder);
        } catch (OpenApiContributionException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new OpenApiContributionException("Contributor {$class} failed: {$throwable->getMessage()}", previous: $throwable);
        }
    }

    /** @param array<string, mixed> $channel
     * @param array<string, mixed> $document
     */
    private function validateRealtimeChannel(array $channel, string $source, array $document): void
    {
        $pattern = $channel['pattern'] ?? null;
        if (!is_string($pattern) || '' === trim($pattern)) {
            throw new OpenApiContributionException("{$source}.pattern must be non-empty.");
        }

        if (!is_bool($channel['private'] ?? null)) {
            throw new OpenApiContributionException("{$source}.private must be boolean.");
        }

        $parameters = $channel['parameters'] ?? [];
        if (!is_array($parameters)) {
            throw new OpenApiContributionException("{$source}.parameters must be an array.");
        }

        preg_match_all('/\\{([^}]+)\\}/', $pattern, $matches);
        $placeholders = array_values(array_unique($matches[1] ?? []));
        $parameterNames = array_keys($parameters);
        sort($placeholders);
        sort($parameterNames);
        if ($placeholders !== $parameterNames) {
            throw new OpenApiContributionException("{$source}.parameters must exactly match pattern placeholders.");
        }

        $events = $channel['events'] ?? null;
        if (!is_array($events) || [] === $events) {
            throw new OpenApiContributionException("{$source}.events must be a non-empty array.");
        }

        foreach ($events as $eventIndex => $event) {
            if (!is_array($event)) {
                throw new OpenApiContributionException("{$source}.events.{$eventIndex} must be an array.");
            }

            $hasName = is_string($event['name'] ?? null) && '' !== trim($event['name']);
            $hasPattern = is_string($event['pattern'] ?? null) && '' !== trim($event['pattern']);
            if ($hasName === $hasPattern) {
                throw new OpenApiContributionException("{$source}.events.{$eventIndex} requires exactly one of name or pattern.");
            }

            $payload = $event['payload'] ?? null;
            if (!is_array($payload) || [] === $payload) {
                throw new OpenApiContributionException("{$source}.events.{$eventIndex}.payload must be a schema or reference.");
            }

            $reference = $payload['$ref'] ?? null;
            if (is_string($reference)) {
                $this->assertLocalComponentReferenceExists($reference, $source . ".events.{$eventIndex}.payload", $document);
            }
        }

        if (isset($channel['authorization']) && !is_string($channel['authorization'])) {
            throw new OpenApiContributionException("{$source}.authorization must be a string.");
        }
    }

    /** @param array<string, mixed> $document */
    private function assertLocalComponentReferenceExists(string $reference, string $source, array $document): void
    {
        if (!str_starts_with($reference, '#/components/')) {
            throw new OpenApiContributionException("{$source} must use a local #/components reference.");
        }

        $segments = explode('/', substr($reference, 2));
        if (3 !== count($segments) || 'components' !== $segments[0]) {
            throw new OpenApiContributionException("{$source} has an invalid component reference.");
        }

        [, $group, $name] = $segments;
        if (!isset($document['components'][$group][$name])) {
            throw new OpenApiContributionException("{$source} references missing component {$reference}.");
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function validateLocalComponentReferences(mixed $value, array $document, string $source = 'document'): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $nestedValue) {
            $nestedSource = $source . '.' . (string) $key;
            if ('$ref' === $key && is_string($nestedValue) && str_starts_with($nestedValue, '#/components/')) {
                $this->assertLocalComponentReferenceExists($nestedValue, $nestedSource, $document);
            }

            $this->validateLocalComponentReferences($nestedValue, $document, $nestedSource);
        }
    }
}
