<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use ReflectionMethod;
use Sopheak\Core\Console\SyncRecordColumnsCommand;
use Sopheak\Core\Enums\RecordColumnTypeEnum as T;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordColumnType;
use Sopheak\Core\Types\RecordTableType;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SyncRecordColumnsEnumGenerationTest extends TestCase
{
    private string $dir;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/sp-enum-' . bin2hex(random_bytes(4));
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);
        parent::tearDown();
    }

    private function command(): SyncRecordColumnsCommand
    {
        $command = new SyncRecordColumnsCommand();
        $command->setLaravel($this->app);

        $definition = $command->getDefinition();
        $input = new ArrayInput(['--enum-path' => $this->dir, '--enum-namespace' => 'SpEnumTest' . bin2hex(random_bytes(3))], $definition);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $this->output));

        return $command;
    }

    /** @param array<int, string> $values */
    private function ensure(SyncRecordColumnsCommand $command, string $table, string $column, array $values, ?string $existing = null): string
    {
        return (new ReflectionMethod($command, 'ensureEnumClass'))->invoke($command, $table, $column, $values, $existing);
    }

    public function test_it_generates_a_backed_enum_that_loads_and_feeds_the_column(): void
    {
        $command = $this->command();
        $class = $this->ensure($command, 'bookings', 'status', ['requested', 'in-progress', 'client_canceled', '2fa', "it's"]);

        $this->assertStringEndsWith('\\BookingStatus', $class);
        $file = $this->dir . '/BookingStatus.php';
        $this->assertFileExists($file);
        require $file;

        $this->assertSame(['REQUESTED', 'IN_PROGRESS', 'CLIENT_CANCELED', 'V_2FA', 'IT_S'], array_map(static fn($c) => $c->name, $class::cases()));
        $this->assertSame(['requested', 'in-progress', 'client_canceled', '2fa', "it's"], array_map(static fn($c) => $c->value, $class::cases()));

        $table = new RecordTableType(table: 'bookings', columns: ['status' => new RecordColumnType(type: T::ENUM, enum: $class)]);
        $this->assertSame(['requested', 'in-progress', 'client_canceled', '2fa', "it's"], $table->columns['status']['enum']);
        $this->assertSame($class, $table->columns['status']['enumClass']);
    }

    public function test_an_existing_enum_file_is_never_overwritten_and_a_drift_is_reported(): void
    {
        $command = $this->command();
        $class = $this->ensure($command, 'orders', 'state', ['open', 'paid']);
        $file = $this->dir . '/OrderState.php';
        file_put_contents($file, str_replace('}', "\n    public function label(): string { return ucfirst(\$this->value); }\n}", (string) file_get_contents($file)));
        $edited = file_get_contents($file);

        $this->ensure($command, 'orders', 'state', ['open', 'paid']);
        $this->assertStringNotContainsString('differs', $this->output->fetch());

        $this->ensure($command, 'orders', 'state', ['open', 'paid', 'refunded']);
        $this->assertSame($edited, file_get_contents($file));
        $this->assertStringContainsString('differs', $this->output->fetch());
        $this->assertStringEndsWith('\\OrderState', $class);
    }

    public function test_duplicate_and_empty_case_names_stay_valid(): void
    {
        $class = $this->ensure($this->command(), 'items', 'kind', ['a-b', 'a_b', '']);
        require $this->dir . '/ItemKind.php';

        $this->assertSame(['A_B', 'A_B_2', 'EMPTY'], array_map(static fn($c) => $c->name, $class::cases()));
    }

    public function test_the_config_writes_the_enum_class_and_imports_it(): void
    {
        $command = $this->command();
        $class = $this->ensure($command, 'bookings', 'status', ['requested', 'accepted']);
        require $this->dir . '/BookingStatus.php';

        $export = new ReflectionMethod($command, 'exportColumnsAsShortArray');
        $code = $export->invoke($command, ['status' => ['type' => 'USER-DEFINED', 'udt_name' => 'booking_status', 'nullable' => false, 'enum' => ['requested', 'accepted'], 'enumClass' => $class]], '');
        $this->assertStringContainsString('enum: BookingStatus::class', $code);

        $imports = (new ReflectionMethod($command, 'ensureColumnTypeImports'))->invoke($command, "<?php\n\nuse Sopheak\\Core\\Types\\RecordTableType;\n\nreturn 1;\n");
        $this->assertStringContainsString('use ' . $class . ';', $imports);
    }

    public function test_without_a_class_the_values_stay_a_list(): void
    {
        $code = (new ReflectionMethod(SyncRecordColumnsCommand::class, 'exportColumnsAsShortArray'))->invoke($this->command(), ['status' => ['type' => 'varchar', 'enum' => ['a', 'b']]], '');

        $this->assertStringContainsString("enum: ['a', 'b']", $code);
        $this->assertTrue((new SyncRecordColumnsCommand())->getDefinition()->hasOption('no-enums'));
    }

    public function test_a_non_enum_class_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecordColumnType(type: T::ENUM, enum: self::class);
    }

    public function test_it_reuses_an_existing_imported_enum_with_matching_values_and_avoids_duplicate_imports(): void
    {
        $command = $this->command();
        $generatedClass = $this->ensure($command, 'users', 'type', ['client', 'service']);
        require $this->dir . '/UserType.php';

        $configFile = $this->dir . '/users_table_config.php';
        file_put_contents($configFile, "<?php\n\nuse {$generatedClass};\nuse Sopheak\\Core\\Types\\RecordTableType;\n\nreturn 1;\n");

        $command2 = $this->command();
        $reusedClass = (new ReflectionMethod($command2, 'ensureEnumClass'))->invoke(
            $command2,
            'users',
            'type',
            ['client', 'service'],
            null,
            [$configFile]
        );

        $this->assertSame($generatedClass, $reusedClass);

        $export = new ReflectionMethod($command2, 'exportColumnsAsShortArray');
        $export->invoke($command2, ['type' => ['type' => 'varchar', 'nullable' => false, 'enum' => ['client', 'service'], 'enumClass' => $reusedClass]], '');

        $imports = (new ReflectionMethod($command2, 'ensureColumnTypeImports'))->invoke($command2, (string) file_get_contents($configFile));
        $this->assertSame(1, substr_count($imports, 'UserType;'));
    }
}
