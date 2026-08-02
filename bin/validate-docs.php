<?php

declare(strict_types=1);

final class DocsValidator
{
    private const REQUIRED_FRONTMATTER_KEYS = ['title', 'description', 'keywords'];

    public function run(string $docsRoot): int
    {
        $docsRoot = rtrim($docsRoot, DIRECTORY_SEPARATOR);
        if (!is_dir($docsRoot)) {
            fwrite(STDERR, "Docs directory not found: {$docsRoot}\n");
            return 1;
        }

        $files = $this->findMarkdownFiles($docsRoot);
        $errors = [];

        foreach ($files as $file) {
            $rel = ltrim(str_replace($docsRoot, '', $file), DIRECTORY_SEPARATOR);

            if ($rel === 'index.md') {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                $errors[] = "{$rel}: unable to read";
                continue;
            }

            $frontmatter = $this->parseFrontmatter($content);
            if ($frontmatter === null) {
                $errors[] = "{$rel}: missing YAML frontmatter (--- ... ---)";
                continue;
            }

            foreach (self::REQUIRED_FRONTMATTER_KEYS as $key) {
                if (!array_key_exists($key, $frontmatter)) {
                    $errors[] = "{$rel}: missing frontmatter key: {$key}";
                }
            }

            if (isset($frontmatter['keywords'])) {
                if (!is_array($frontmatter['keywords']) || count($frontmatter['keywords']) < 1) {
                    $errors[] = "{$rel}: frontmatter keywords must be a non-empty list";
                }
            }

            if (isset($frontmatter['title']) && is_string($frontmatter['title'])) {
                if (preg_match('/^SP\\s+Laravel\\s+API\\b/i', $frontmatter['title']) === 1) {
                    $errors[] = "{$rel}: title must not start with \"SP Laravel API\" (keep titles short)";
                }
            }

            $linkErrors = $this->validateLinks($rel, $content);
            array_push($errors, ...$linkErrors);
        }

        if (count($errors) > 0) {
            fwrite(STDERR, "Docs validation failed:\n");
            foreach ($errors as $e) {
                fwrite(STDERR, "- {$e}\n");
            }
            return 1;
        }

        fwrite(STDOUT, "Docs validation OK (" . count($files) . " files scanned)\n");
        return 0;
    }

    private function findMarkdownFiles(string $docsRoot): array
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($it as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }
            if (!$fileInfo->isFile()) {
                continue;
            }
            if (str_ends_with($fileInfo->getFilename(), '.md')) {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function parseFrontmatter(string $content): ?array
    {
        if (!preg_match('/^---\\R([\\s\\S]*?)\\R---\\R/', $content, $m)) {
            return null;
        }

        $block = $m[1] ?? '';
        $lines = preg_split('/\\R/', $block) ?: [];

        $data = [];
        $currentKey = null;

        foreach ($lines as $line) {
            if (preg_match('/^\\s*([A-Za-z0-9_-]+)\\s*:\\s*(.*?)\\s*$/', $line, $km)) {
                $currentKey = $km[1];
                $value = $km[2] ?? '';
                $value = trim($value);
                $value = preg_replace('/^["\']|["\']$/', '', $value ?? '');

                if ($value === '') {
                    $data[$currentKey] = [];
                } else {
                    $data[$currentKey] = $value;
                }
                continue;
            }

            if ($currentKey !== null && preg_match('/^\\s*-\\s*(.+?)\\s*$/', $line, $im)) {
                if (!isset($data[$currentKey]) || !is_array($data[$currentKey])) {
                    $data[$currentKey] = [];
                }
                $item = $im[1] ?? '';
                $item = preg_replace('/^["\']|["\']$/', '', trim($item));
                if ($item !== '') {
                    $data[$currentKey][] = $item;
                }
            }
        }

        return $data;
    }

    private function validateLinks(string $relPath, string $content): array
    {
        $errors = [];

        if (preg_match_all('/\\[[^\\]]+\\]\\(([^)]+)\\)/', $content, $matches)) {
            foreach ($matches[1] as $target) {
                $target = trim($target);

                if ($target === '' || str_starts_with($target, '#')) {
                    continue;
                }

                if (preg_match('/^(https?:|mailto:)/i', $target) === 1) {
                    continue;
                }

                if (preg_match('/^(\\.\\.\\/|\\.\\/)/', $target) === 1) {
                    $errors[] = "{$relPath}: avoid relative links ({$target}); use site-absolute links like /guide/...";
                    continue;
                }

                if (str_ends_with($target, '.md')) {
                    $errors[] = "{$relPath}: avoid .md links ({$target}); use extensionless site paths";
                    continue;
                }
            }
        }

        return $errors;
    }
}

$docsRoot = $argv[1] ?? (__DIR__ . '/../docs');
$validator = new DocsValidator();
exit($validator->run($docsRoot));
