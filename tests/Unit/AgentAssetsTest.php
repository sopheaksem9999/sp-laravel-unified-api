<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Unit;

use Illuminate\Support\Facades\File;
use Sopheak\Core\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class AgentAssetsTest extends TestCase
{
    public function test_agent_guidelines_exist_and_cover_core_conventions(): void
    {
        $path = __DIR__ . '/../../resources/agent/guidelines/core.blade.php';

        $this->assertFileExists($path);

        $content = (string) File::get($path);

        foreach (['RecordTableType', 'sp-laravel-api:record', 'sp-laravel-api:validate', 'isAuthRead'] as $needle) {
            $this->assertStringContainsString($needle, $content, sprintf('Guidelines must mention [%s]', $needle));
        }

        $boostPath = __DIR__ . '/../../resources/boost/guidelines/core.blade.php';
        $this->assertFileExists($boostPath, 'Laravel Boost third-party discovery requires resources/boost/guidelines/core.blade.php');
        $this->assertSame($content, (string) File::get($boostPath), 'resources/boost and resources/agent guidelines must stay in sync');
    }

    public function test_agent_skill_exists_with_valid_frontmatter_and_debug_workflow(): void
    {
        $path = __DIR__ . '/../../resources/agent/skills/sp-laravel-api-development/SKILL.md';

        $this->assertFileExists($path);

        $content = (string) File::get($path);

        $this->assertStringStartsWith('---', $content, 'Skill must start with YAML frontmatter');

        $parts = explode('---', $content, 3);
        $this->assertCount(3, $parts, 'Skill must contain closing frontmatter delimiter');

        $frontmatter = Yaml::parse($parts[1]);

        $this->assertSame('sp-laravel-api-development', $frontmatter['name'] ?? null);
        $this->assertNotEmpty($frontmatter['description'] ?? null);

        $body = $parts[2];

        foreach (['sp-laravel-api:validate', 'sp_api_get_endpoint', 'composer format-check'] as $needle) {
            $this->assertStringContainsString($needle, $body, sprintf('Skill body must mention [%s]', $needle));
        }

        $boostPath = __DIR__ . '/../../resources/boost/skills/sp-laravel-api-development/SKILL.md';
        $this->assertFileExists($boostPath, 'Laravel Boost third-party discovery requires resources/boost/skills/sp-laravel-api-development/SKILL.md');
        $this->assertSame($content, (string) File::get($boostPath), 'resources/boost and resources/agent skill files must stay in sync');
    }
}
