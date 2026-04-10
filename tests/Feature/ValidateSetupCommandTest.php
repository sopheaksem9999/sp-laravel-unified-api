<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Sopheak\Core\Tests\TestCase;

class ValidateSetupCommandTest extends TestCase
{
    public function test_it_runs_validate_command_without_exceptions(): void
    {
        // The command will check configuration files, directories, DB, etc.
        // It might return 1 if there are missing tables (since this is a test env),
        // but it should output the validation headers correctly.
        $this->artisan('sp-laravel-api:validate')
            ->expectsOutputToContain('Validating SP Laravel API Setup...')
            ->expectsOutputToContain('Checking Configuration Files...')
            ->expectsOutputToContain('Checking Database Connection...')
            ->expectsOutputToContain('Checking SchemaRegistryUtils...')
            ->assertExitCode(1); // Usually fails in test environment because real config files aren't published
    }

    public function test_it_runs_validate_command_with_verbose_flag(): void
    {
        $this->artisan('sp-laravel-api:validate', ['--verbose' => true])
            ->expectsOutputToContain('Validating SP Laravel API Setup...')
            ->assertExitCode(1);
    }
}
