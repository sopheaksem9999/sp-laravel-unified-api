<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Tools\Request;
use Sopheak\Core\Ai\RecordTool;
use Sopheak\Core\Ai\RecordTools;
use Sopheak\Core\Tests\Concerns\UsesLaravelAi;
use Sopheak\Core\Tests\Feature\McpHookSpy;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

/**
 * @internal
 */
class RecordToolHooksTest extends TestCase
{
    use RefreshDatabase;
    use UsesLaravelAi;

    protected function setUp(): void
    {
        $this->requireLaravelAi();
        parent::setUp();
        McpHookSpy::reset();

        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('body')->nullable();
            $t->timestamps();
        });
        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                public: new RecordTablePublic(read: true, write: true),
                createValidator: McpHookSpy::requireBody(...),
            ),
        ]);
        SchemaRegistryUtils::refresh();
    }

    public function test_a_table_validator_refusal_reaches_the_model_and_nothing_is_written(): void
    {
        /** @var RecordTool $tool */
        $tool = RecordTools::for(['notes'])->only(['create'])->withoutApproval()->toArray()[0];

        $answer = json_decode($tool->handle(new Request(['payload' => '{"title":"no body"}'])), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Validation failed', $answer['error']['message']);
        $this->assertStringContainsString('body', $answer['error']['message']);
        $this->assertSame(0, DB::table('notes')->count());
    }
}
