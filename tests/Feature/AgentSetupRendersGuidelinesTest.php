<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Sopheak\Core\Tests\TestCase;

/**
 * The agent guidelines ship as `core.blade.php`, and Laravel Boost used to render
 * them before writing the `.md`. `sp-laravel-api:agent` replaced that path but
 * copied the file verbatim, so the installed rules contained raw Blade directives
 * (`@verbatim` / `@endverbatim`) that an agent then reads as content.
 *
 * @internal
 */
class AgentSetupRendersGuidelinesTest extends TestCase
{
    private string $rulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rulesPath = base_path('.agents/rules/sp-laravel-api.md');
        File::delete($this->rulesPath);
    }

    protected function tearDown(): void
    {
        File::delete($this->rulesPath);
        parent::tearDown();
    }

    /** @test */
    public function installed_rules_contain_no_unrendered_blade_directives(): void
    {
        $this->artisan('sp-laravel-api:agent', ['--rules' => true])->assertExitCode(0);

        $this->assertFileExists($this->rulesPath);
        $contents = (string) File::get($this->rulesPath);

        $this->assertNotSame('', trim($contents), 'the rules file must not be empty');
        foreach (['@verbatim', '@endverbatim', '@if', '@foreach', '@php'] as $directive) {
            $this->assertStringNotContainsString($directive, $contents, sprintf('unrendered Blade directive %s leaked into the installed rules', $directive));
        }
    }

    /** @test */
    public function installed_rules_keep_the_guidance_body(): void
    {
        $this->artisan('sp-laravel-api:agent', ['--rules' => true])->assertExitCode(0);

        $contents = (string) File::get($this->rulesPath);
        $this->assertStringContainsString('sp-laravel-api', $contents);
        $this->assertStringContainsString('RecordTableType', $contents);
    }
}
