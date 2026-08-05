<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Http\Request;
use ReflectionClass;
use Sopheak\Core\Console\SetupPackageCommand;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTableType;
use Throwable;

/**
 * Task 5 fix-round-1, High 6: `sp-laravel-api:setup` used to scaffold
 * config/records/tables/users.php with three Closure validators, while
 * config/sp-record.php ships with 'autoloaded' => true -- which evaluates
 * every file in that directory while the config file itself is merged. That
 * combination broke `php artisan config:cache` on a brand-new install, out of
 * the box, and the failure is invisible until someone actually runs
 * config:cache in production. This test materializes the exact PHP source
 * SetupPackageCommand generates (via its private scaffold methods, not a
 * reimplementation) and pushes it through the same var_export()/require
 * round-trip RecordConfigLoader and `config:cache` perform, so a regression
 * back to a Closure-based scaffold fails here instead of at deploy time.
 */
class SetupPackageScaffoldTest extends TestCase
{
    /** @test */
    public function the_scaffolded_users_table_survives_the_config_cache_round_trip(): void
    {
        $command = new SetupPackageCommand();
        $reflection = new ReflectionClass($command);

        $validatorSource = $reflection->getMethod('defaultUserValidatorClass')->invoke($command);
        $tableSource = $reflection->getMethod('defaultUsersTableConfig')->invoke($command);

        $validatorPath = sys_get_temp_dir() . '/sp_scaffold_user_validator_' . uniqid('', true) . '.php';
        $tablePath = sys_get_temp_dir() . '/sp_scaffold_users_table_' . uniqid('', true) . '.php';

        file_put_contents($validatorPath, $validatorSource);
        file_put_contents($tablePath, $tableSource);

        try {
            require $validatorPath;
            $table = require $tablePath;

            $this->assertInstanceOf(RecordTableType::class, $table);

            // What php artisan config:cache actually does: var_export() the config
            // tree, write it to a file, then require it back in to verify it. A
            // Closure validator var_export()s as the non-functional
            // `\Closure::__set_state(array())` and throws on this eval -- exactly
            // the "Your configuration files are not serializable." failure. This
            // must NOT throw for the shipped scaffold.
            $exported = var_export(['users' => $table], true);

            try {
                $restored = eval('return ' . $exported . ';');
            } catch (Throwable $throwable) {
                $this->fail(
                    'The scaffolded users table config is not config:cache-safe: '
                    . $throwable->getMessage()
                );
            }

            $this->assertInstanceOf(RecordTableType::class, $restored['users']);
            $this->assertSame('users', $restored['users']->table);

            foreach (['createValidator', 'updateValidator', 'deleteValidator'] as $property) {
                $this->assertIsArray(
                    $restored['users']->{$property},
                    sprintf('%s must be a [Class, method] array, not a Closure, to survive config:cache', $property)
                );
                $this->assertSame(
                    'App\\Record\\Validators\\UserValidator',
                    $restored['users']->{$property}[0]
                );
            }

            // The referenced methods must actually be callable the way the package
            // calls them (call_user_func([$class, $method], ...), which throws for
            // a non-static method) -- proving `public static` was preserved, not
            // just that the array shape survived.
            [$class, $method] = $restored['users']->createValidator;
            $request = Request::create('/', 'POST', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'password' => 'password123',
            ]);
            $validator = call_user_func([$class, $method], $request, null);
            $this->assertFalse($validator->fails());
        } finally {
            @unlink($validatorPath);
            @unlink($tablePath);
        }
    }
}
