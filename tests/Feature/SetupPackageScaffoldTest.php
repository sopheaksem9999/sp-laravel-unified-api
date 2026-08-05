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
            // Guarded: the sibling uuid test requires the same generated class,
            // and the suite runs in random order, so whichever lands second
            // would fatal on a redeclare. Both materialize identical source.
            if (!class_exists('App\\Record\\Validators\\UserValidator', false)) {
                require $validatorPath;
            }

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

    /**
     * The scaffolded validators must accept a uuid primary key.
     *
     * `HasControllerHelpers::normalizeValidatorIdForCallable` reflects the
     * validator's second parameter and coerces the record id to match it:
     *
     *     'int' => ctype_digit($id) ? (int) $id : $id,
     *
     * A uuid is not all digits, so it is deliberately passed through as a
     * string — the helper cannot invent an integer. If the validator then
     * declares `?int $id`, PHP's own type check rejects it and every update
     * and delete on a uuid-keyed table dies with
     * "Argument #2 ($id) must be of type ?int, string given".
     *
     * The package supports uuid primary keys (`record.id_type`), and every
     * client-reference column is a string, so the scaffold cannot assume the
     * key is numeric. This pins both halves: the signature must accept a
     * string, and `deleteUser`'s own rule must not demand an integer.
     */
    /** @test */
    public function the_scaffolded_validators_accept_a_uuid_primary_key(): void
    {
        $class = 'App\\Record\\Validators\\UserValidator';
        $validatorPath = null;

        // The sibling test in this file requires the same generated class, and
        // the suite runs in random order, so whichever lands first wins. Both
        // materialize identical source from the same scaffold method.
        if (!class_exists($class, false)) {
            $command = new SetupPackageCommand();
            $reflection = new ReflectionClass($command);
            $validatorSource = $reflection->getMethod('defaultUserValidatorClass')->invoke($command);

            $validatorPath = sys_get_temp_dir() . '/sp_scaffold_uuid_validator_' . uniqid('', true) . '.php';
            file_put_contents($validatorPath, $validatorSource);
            require $validatorPath;
        }

        try {
            $uuid = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

            $updateRequest = Request::create('/', 'PUT', ['name' => 'Jane Doe']);

            try {
                $validator = call_user_func([$class, 'updateUser'], $updateRequest, $uuid);
            } catch (Throwable $throwable) {
                $this->fail(
                    'The scaffolded updateUser rejects a uuid primary key: ' . $throwable->getMessage()
                );
            }

            $this->assertFalse($validator->fails());

            try {
                $deleteValidator = call_user_func([$class, 'deleteUser'], Request::create('/', 'DELETE'), $uuid);
            } catch (Throwable $throwable) {
                $this->fail(
                    'The scaffolded deleteUser rejects a uuid primary key: ' . $throwable->getMessage()
                );
            }

            $this->assertFalse(
                $deleteValidator->fails(),
                'deleteUser must not validate the primary key as an integer — the package supports uuid keys'
            );

            // An integer key must keep working; this is not a swap of one
            // hardcoded assumption for another.
            $intValidator = call_user_func([$class, 'deleteUser'], Request::create('/', 'DELETE'), '42');
            $this->assertFalse($intValidator->fails());
        } finally {
            if ($validatorPath !== null) {
                @unlink($validatorPath);
            }
        }
    }
}
