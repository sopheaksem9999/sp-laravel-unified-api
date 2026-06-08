<?php

declare(strict_types=1);

namespace Sopheak\Core\Console;

use RuntimeException;
use Illuminate\Console\Command;
use Sopheak\Core\Services\OpenApiService;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Throwable;

/**
 * Artisan command that exports the OpenAPI 3.1 specification generated
 * from the registered RecordTableType configurations.
 *
 * Usage:
 *   php artisan sp-laravel-api:export-openapi
 *   php artisan sp-laravel-api:export-openapi --output=public/openapi.json
 *   php artisan sp-laravel-api:export-openapi --format=yaml
 *   php artisan sp-laravel-api:export-openapi --output=public/openapi.yaml --format=yaml
 */
class ExportOpenApiCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sp-laravel-api:export-openapi
                            {--output= : Output file path (relative to project root). Defaults to the value of sp-laravel-api.openapi.output config key.}
                            {--format=json : Output format: json or yaml}
                            {--pretty : Pretty-print JSON output (ignored for YAML)}';

    /**
     * The console command description.
     */
    protected $description = 'Export the OpenAPI 3.1 specification generated from registered RecordTableType configs.';

    public function handle(): int
    {
        try {
            SchemaRegistryUtils::refresh();
            $spec = OpenApiService::generateInternal();
        } catch (Throwable $throwable) {
            $this->error('Failed to generate OpenAPI specification: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        $format = strtolower((string) ($this->option('format') ?: 'json'));
        if (!in_array($format, ['json', 'yaml', 'yml'], true)) {
            $this->error('Invalid format "' . $format . '". Supported values: json, yaml');

            return self::FAILURE;
        }

        $outputPath = (string) ($this->option('output') ?: config('sp-laravel-api.openapi.output', 'openapi-schema.json'));

        // Make relative paths absolute to project root
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = base_path($outputPath);
        }

        // Auto-fix extension when format doesn't match the provided path
        if (in_array($format, ['yaml', 'yml'], true) && str_ends_with($outputPath, '.json')) {
            $outputPath = substr($outputPath, 0, -5) . '.yaml';
        } elseif ($format === 'json' && (str_ends_with($outputPath, '.yaml') || str_ends_with($outputPath, '.yml'))) {
            $outputPath = preg_replace('/\.(yaml|yml)$/', '.json', $outputPath) ?? $outputPath;
        }

        // Ensure the directory exists
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $this->error('Failed to create directory: ' . $directory);

            return self::FAILURE;
        }

        try {
            $content = $this->renderSpec($spec, $format);
        } catch (Throwable $throwable) {
            $this->error('Failed to render specification: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        if (file_put_contents($outputPath, $content) === false) {
            $this->error('Failed to write file: ' . $outputPath);

            return self::FAILURE;
        }

        $tableCount = count($spec['paths'] ?? []);
        $this->info('OpenAPI specification exported successfully.');
        $this->line('  Output : ' . $this->relativePath($outputPath));
        $this->line('  Format : ' . strtoupper($format));
        $this->line('  Paths  : ' . $tableCount . ' path(s)');
        $this->line('  Version: ' . ($spec['openapi'] ?? 'n/a'));

        return self::SUCCESS;
    }

    /**
     * Render the spec array as the requested format string.
     *
     * @param array<string, mixed> $spec
     */
    private function renderSpec(array $spec, string $format): string
    {
        if (in_array($format, ['yaml', 'yml'], true)) {
            return $this->toYaml($spec);
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($spec, $flags);
        if ($json === false) {
            throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Convert a nested array to YAML without a third-party dependency.
     *
     * Covers the subset of YAML actually needed for an OpenAPI document
     * (scalars, sequences, mappings, multi-line strings).
     *
     * @param array<mixed, mixed> $data
     */
    private function toYaml(array $data, int $indent = 0): string
    {
        $lines = [];
        $pad   = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            $keyStr = is_int($key) ? '' : $this->yamlKey($key) . ': ';

            if (is_array($value)) {
                if ($value === []) {
                    $lines[] = $pad . $keyStr . '[]';
                } elseif (array_is_list($value)) {
                    $lines[] = $pad . rtrim($keyStr, ' ');
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $nested = $this->toYaml($item, $indent + 1);
                            $firstLine = ltrim(explode("\n", $nested)[0]);
                            $rest = implode("\n", array_slice(explode("\n", $nested), 1));
                            $lines[] = $pad . '- ' . $firstLine;
                            if ($rest !== '') {
                                $lines[] = $rest;
                            }
                        } else {
                            $lines[] = $pad . '- ' . $this->yamlScalar($item);
                        }
                    }
                } else {
                    if ($keyStr !== '') {
                        $lines[] = $pad . rtrim($keyStr, ' ');
                    }

                    $lines[] = $this->toYaml($value, $indent + 1);
                }
            } else {
                $lines[] = $pad . $keyStr . $this->yamlScalar($value);
            }
        }

        return implode("\n", $lines);
    }

    /** Quote a YAML mapping key if needed. */
    private function yamlKey(string $key): string
    {
        if (preg_match('/[:\#\[\]\{\},&\*\?\|\-<>=!%@`\'"]/', $key) || trim($key) !== $key) {
            return '"' . addcslashes($key, '"\\') . '"';
        }

        return $key;
    }

    /** Render a scalar value as a YAML string. */
    private function yamlScalar(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $str = (string) $value;
        // Use block scalar for multi-line strings
        if (str_contains($str, "\n")) {
            return "|\n  " . str_replace("\n", "\n  ", $str);
        }

        // Quote strings that could be misinterpreted by a YAML parser
        if (preg_match('/^[\s\-\?:\#\[\]\{\},&\*\!\|\>\'"%@`]|[\s:]$|^(true|false|null|~|\d.*)$/i', $str)) {
            return '"' . addcslashes($str, '"\\') . '"';
        }

        return $str;
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
