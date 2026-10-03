<?php

declare(strict_types=1);

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Types\RecordTablePublic;
use Sopheak\Core\Types\RecordTableTriggerType;
use Sopheak\Core\Http\Controllers\CoreRecordController;
use Illuminate\Support\Facades\Route;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;

class BeforeReadQueryHooks
{
    public static function viaQuerySet(Request $request, string $table, array $context): Request
    {
        $request->query->set('team', 'eq.red');

        return $request;
    }

    public static function viaMerge(Request $request, string $table, array $context): Request
    {
        $request->merge(['team' => 'eq.red']);

        return $request;
    }

    /** The record-hooks guide's pattern: a `search` parameter rewritten into an `or` group. */
    public static function searchToOr(Request $request, string $table, array $context): Request
    {
        $request->query->remove('search');
        $request->query->set('or', '(title.eq.BLUE,team.eq.green)');

        return $request;
    }

    public static function removesTeam(Request $request, string $table, array $context): Request
    {
        $request->query->remove('team');

        return $request;
    }

    public static function setsUnrelated(Request $request, string $table, array $context): Request
    {
        $request->query->set('zzz', '1');

        return $request;
    }

    public static function returnsADuplicate(Request $request, string $table, array $context): Request
    {
        return $request->duplicate(['team' => 'eq.red'] + $request->query->all());
    }

    /** @var list<string|null> */
    public static array $queryStrings = [];

    public static function recordsQueryString(Request $request, string $table, array $context): void
    {
        self::$queryStrings[] = $request->server->get('QUERY_STRING');
    }

    public static function selectIntoBody(Request $request, string $table, array $context): Request
    {
        $request->merge(['select' => 'id,title']);

        return $request;
    }
}

/**
 * QueryBuilderFiltersUtils reads filters from the raw QUERY_STRING (so dotted
 * keys survive), so a beforeRead hook's $request->query->set() / remove() /
 * merge() never reached the filters over HTTP, and a `select` merged into a
 * JSON request body made the select parser receive null (500).
 *
 * @internal
 */
class BeforeReadQueryChangesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('team')->nullable();
            $t->timestamps();
        });
        Schema::create('comments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('note_id');
            $t->string('body');
        });
        DB::table('notes')->insert([
            ['id' => 1, 'title' => 'RED', 'team' => 'red'],
            ['id' => 2, 'title' => 'BLUE', 'team' => 'blue'],
            ['id' => 3, 'title' => 'RED2', 'team' => 'red'],
        ]);
        DB::table('comments')->insert([
            ['note_id' => 1, 'body' => 'hello'],
            ['note_id' => 2, 'body' => 'hello'],
        ]);
    }

    private function registerHook(?string $tableHook, ?string $globalHook = null): void
    {
        $public = new RecordTablePublic(read: true, write: true);
        Config::set('record.global_triggers', null === $globalHook ? [] : ['beforeRead' => new RecordTableTriggerType(BeforeReadQueryHooks::class, $globalHook)]);
        Config::set('record.tables', [
            'notes' => new RecordTableType(
                table: 'notes',
                public: $public,
                relationships: ['comments' => new RecordHasManyType(table: 'comments', foreignKey: 'note_id')],
                beforeRead: null === $tableHook ? null : new RecordTableTriggerType(BeforeReadQueryHooks::class, $tableHook),
                afterRead: new RecordTableTriggerType(BeforeReadQueryHooks::class, 'recordsQueryString'),
            ),
            'comments' => new RecordTableType(table: 'comments', public: $public),
        ]);
        SchemaRegistryUtils::refresh();
    }

    /** @return list<string> */
    private function titles(string $uri, bool $json = true): array
    {
        $response = $json ? $this->getJson($uri) : $this->get($uri, ['Accept' => 'application/json']);
        $response->assertOk();

        $titles = array_column((array) $response->json('data'), 'title');
        sort($titles);

        return $titles;
    }

    public function test_a_query_set_in_a_before_read_hook_filters_the_list(): void
    {
        $this->registerHook('viaQuerySet');

        $this->assertSame(['RED', 'RED2'], $this->titles('/api/notes'));
    }

    public function test_a_merge_in_a_before_read_hook_filters_the_list(): void
    {
        $this->registerHook('viaMerge');

        $this->assertSame(['RED', 'RED2'], $this->titles('/api/notes', json: false));
    }

    public function test_a_removed_and_rewritten_parameter_takes_effect(): void
    {
        $this->registerHook('searchToOr');

        $this->assertSame(['BLUE'], $this->titles('/api/notes?search=x'));
    }

    public function test_a_dotted_relationship_filter_survives_the_rewrite(): void
    {
        $this->registerHook('viaQuerySet');

        $this->assertSame(['RED'], $this->titles('/api/notes?comments.body=eq.hello'));
    }

    public function test_a_select_merged_into_a_json_body_does_not_break_the_read(): void
    {
        $this->registerHook('selectIntoBody');

        $this->getJson('/api/notes')->assertOk();
    }

    public function test_a_global_before_read_hook_filters_too(): void
    {
        $this->registerHook(null, 'viaQuerySet');

        $this->assertSame(['RED', 'RED2'], $this->titles('/api/notes'));
    }

    public function test_a_parameter_a_hook_removes_stops_filtering(): void
    {
        $this->registerHook('removesTeam');

        $this->assertSame(['BLUE', 'RED', 'RED2'], $this->titles('/api/notes?team=eq.blue'));
    }

    public function test_a_hook_that_returns_a_new_request_filters_too(): void
    {
        $this->registerHook('returnsADuplicate');

        $this->assertSame(['RED', 'RED2'], $this->titles('/api/notes'));
    }

    public function test_a_hook_that_changes_nothing_leaves_the_query_string_alone(): void
    {
        BeforeReadQueryHooks::$queryStrings = [];
        $this->registerHook('setsUnrelated');
        $this->titles('/api/notes?comments.body=eq.hello&team=eq.red');

        BeforeReadQueryHooks::$queryStrings = [];
        $this->registerHook(null);
        $this->titles('/api/notes?comments.body=eq.hello&team=eq.red');

        $this->assertSame(['comments.body=eq.hello&team=eq.red'], BeforeReadQueryHooks::$queryStrings);
    }

    public function test_only_the_hooks_own_changes_reach_the_filters(): void
    {
        $this->registerHook('setsUnrelated');
        // Like a middleware that took `team` out of the query bag before the hooks ran:
        // the hook did not remove it, so it filters as it does without the hook.
        Route::get('/custom/notes-without-team-in-bag', function (Request $request) {
            $request->query->remove('team');

            return app(CoreRecordController::class)->listRecords($request, 'notes');
        });

        $titles = array_column((array) $this->getJson('/custom/notes-without-team-in-bag?team=eq.red')->assertOk()->json('data'), 'title');
        sort($titles);

        $this->assertSame(['RED', 'RED2'], $titles);
    }
}
