<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use Illuminate\Console\Command;
use Sopheak\Core\Services\ApiClient\ApiClientEmitterInterface;
use Sopheak\Core\Services\ApiClient\ExportResult;
use Sopheak\Core\Services\ApiClientExportService;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

/**
 * Shared base for the Bruno / Postman exporter commands.
 *
 * Subclasses provide the emitter, the default output path, and the format
 * name used in error messages. All other behavior — flag parsing, regen
 * validation, diff summary, file writing — lives here.
 */
abstract class AbstractExportCommand extends Command
{
    abstract protected function emitter(): ApiClientEmitterInterface;

    abstract protected function defaultOutputPath(): string;

    abstract protected function formatName(): string;

    public function handle(): int
    {
        $outputPath = $this->resolveOutputPath();
        $regenKeys = $this->parseRegenKeys();
        $dryRun = (bool) $this->option('dry-run');

        try {
            SchemaRegistryUtils::refresh();
            $spec = OpenApiService::generateInternal();
        } catch (Throwable $throwable) {
            $this->error('Failed to generate OpenAPI specification: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        if ($regenKeys !== null && ($exitCode = $this->validateRegenKeys($regenKeys, $spec)) !== 0) {
            return $exitCode;
        }

        $existing = null;
        if (is_file($outputPath)) {
            $raw = (string) file_get_contents($outputPath);
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    $this->error('Existing file at ' . $outputPath . ' is not valid JSON.');

                    return self::FAILURE;
                }

                $existing = $decoded;
            }
        }

        try {
            $emitter = $this->emitter();
            $service = new ApiClientExportService();
            $result = $service->build($spec, $existing, $regenKeys, $emitter);
            $rendered = $emitter->render($result);
        } catch (Throwable $throwable) {
            $this->error('Failed to render ' . $this->formatName() . ' collection: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        $this->printSummary($spec, $outputPath, $result);

        if ($dryRun) {
            return self::SUCCESS;
        }

        if (! $this->writeCollection($outputPath, $rendered)) {
            return self::FAILURE;
        }

        $this->line('');
        $this->line('Done. Run with --regen=all to regenerate the full collection.');

        return self::SUCCESS;
    }

    private function resolveOutputPath(): string
    {
        $output = (string) ($this->option('output') ?: $this->defaultOutputPath());
        if (! str_starts_with($output, '/')) {
            return base_path($output);
        }

        return $output;
    }

    /**
     * @return string[]|null
     */
    private function parseRegenKeys(): ?array
    {
        $raw = $this->option('regen');
        if ($raw === null || $raw === '') {
            return null;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', (string) $raw))));
    }

    /**
     * @param  string[]              $regenKeys
     * @param  array<string, mixed>  $spec
     * @return int 0 if valid, 2 if invalid
     */
    private function validateRegenKeys(array $regenKeys, array $spec): int
    {
        if (in_array('all', $regenKeys, true)) {
            return 0;
        }

        $available = $this->collectSpecTags($spec);
        $invalid = array_values(array_filter($regenKeys, static fn (string $k): bool => ! isset($available[strtolower($k)])));

        if ($invalid !== []) {
            $this->error('Invalid --regen value(s): ' . implode(', ', $invalid));
            $this->line('Available tables: ' . implode(', ', array_keys($available)));

            return 2;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed> $spec
     * @return array<string, true>  map of lowercase tag => true
     */
    private function collectSpecTags(array $spec): array
    {
        $tags = [];
        foreach ($spec['paths'] ?? [] as $methods) {
            foreach ($methods as $op) {
                if (! is_array($op)) {
                    continue;
                }

                foreach ($op['tags'] ?? [] as $tag) {
                    $tags[strtolower((string) $tag)] = true;
                }
            }
        }

        return $tags;
    }

    /**
     * @param  array<string, mixed> $rendered
     */
    private function writeCollection(string $outputPath, array $rendered): bool
    {
        $directory = dirname($outputPath);
        if (! is_dir($directory)) {
            $created = @mkdir($directory, 0o755, true);
            if (! $created && ! is_dir($directory)) {
                $this->error('Failed to create directory: ' . $directory);

                return false;
            }
        }

        $json = json_encode($rendered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->error('Failed to encode collection: ' . json_last_error_msg());

            return false;
        }

        if (@file_put_contents($outputPath, $json) === false) {
            $this->error('Failed to write file: ' . $outputPath);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed> $spec
     */
    private function printSummary(array $spec, string $outputPath, ExportResult $result): void
    {
        $pathCount = count($spec['paths'] ?? []);
        $tagCount = count($this->collectSpecTags($spec));

        $this->line('OpenAPI spec loaded: ' . $pathCount . ' paths, ' . $tagCount . ' tags');
        $this->line('Collection: ' . $this->relativePath($outputPath));
        $this->line('');

        $this->line(sprintf('+ Added       (%d)  %s', count($result->added), implode(', ', $result->added)));
        $this->line(sprintf('~ Regenerated (%d)  %s', count($result->regenerated), implode(', ', $result->regenerated)));
        $this->line(sprintf('- Skipped     (%d)  %s', count($result->skipped), implode(', ', $result->skipped)));
        $this->line(sprintf('? Suggestions (%d)  %s', count($result->suggestions), implode(', ', $result->suggestions)));
    }

    private function relativePath(string $path): string
    {
        $base = base_path();
        if (str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base . DIRECTORY_SEPARATOR));
        }

        return $path;
    }
}
