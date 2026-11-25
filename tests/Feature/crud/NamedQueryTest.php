<?php

namespace Tests\Feature\crud;

use App\Exceptions\Maps\InvalidMapException;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\MapReader;
use App\Services\MapStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Named queries of a table map (Pane#225): typed and defaulted parameters, the owner rule on every
 * run, and values that look like SQL staying plain data. The table is owner-scoped so the access
 * rule is exercised on each call.
 */
class NamedQueryTest extends TestCase
{
    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('nq_items', function ($table): void {
            $table->increments('item_id');
            $table->string('membership_id');
            $table->string('title');
            $table->integer('position');
            $table->boolean('done')->default(false);
            $table->date('due')->nullable();
            $table->string('secret')->nullable();
        });

        $this->clearAppMaps();
        $this->saveItemsMap($this->queries());

        $this->owner = $this->makePaneUser(2);
        $this->other = $this->makePaneUser(2);

        $this->itemFor($this->owner, 'Apples', 1, false, '2026-12-01');
        $this->itemFor($this->owner, 'Bread', 2, true, '2026-12-02');
        $this->itemFor($this->owner, 'Cheese', 3, false, '2026-12-03');
        $this->itemFor($this->owner, '100% juice', 4, false, null);
        $this->itemFor($this->other, 'Hidden', 2, false, '2026-12-02');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->clearAppMaps();
        User::query()->whereIn('user_id', [$this->owner->user_id, $this->other->user_id])->delete();

        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queries(): array
    {
        return [
            [
                'name' => 'open_from',
                'description' => 'Items not done, from a position on',
                'params' => [
                    ['name' => 'position', 'type' => 'integer'],
                    ['name' => 'done', 'type' => 'boolean', 'default' => false],
                ],
                'filter' => [
                    ['field' => 'position', 'op' => 'gte', 'param' => 'position'],
                    ['field' => 'done', 'param' => 'done'],
                ],
                'sort' => '-position',
            ],
            [
                'name' => 'due_on',
                'params' => [['name' => 'day', 'type' => 'date']],
                'filter' => [['field' => 'due', 'param' => 'day']],
            ],
            [
                'name' => 'titled',
                'params' => [['name' => 'text', 'type' => 'string', 'default' => 'e']],
                'filter' => [['field' => 'title', 'op' => 'contains', 'param' => 'text'], ['field' => 'position', 'op' => 'lt', 'value' => 4]],
                'sort' => 'title',
            ],
            [
                'name' => 'by_id',
                'params' => [['name' => 'id', 'type' => 'record']],
                'filter' => [['field' => 'item_id', 'param' => 'id']],
            ],
            ['name' => 'everything', 'sort' => 'title'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $queries
     */
    private function saveItemsMap(array $queries): void
    {
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $store->saveTable('install', [
            'name' => 'items', 'sql_name' => 'nq_items',
            'access' => ['owner' => ['key' => 'membership_id']],
            'queries' => $queries,
            'fields' => [
                ['name' => 'item_id', 'type' => 'number', 'primary' => true],
                ['name' => 'membership_id', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string', 'sortable' => true],
                ['name' => 'position', 'type' => 'number', 'sortable' => true],
                ['name' => 'done', 'type' => 'boolean'],
                ['name' => 'due', 'type' => 'date'],
                ['name' => 'secret', 'type' => 'password'],
            ],
        ], 'test');
    }

    /**
     * @return list<string>
     */
    private function titles(string $url): array
    {
        return $this->getJson($url)->assertOk()->collect('data')->pluck('title')->all();
    }

    public function test_a_query_runs_by_name_with_typed_parameters_and_its_own_sort(): void
    {
        $this->assertSame(['100% juice', 'Cheese'], $this->titles('/crud/items/queries/open_from?params[position]=3'));
    }

    public function test_a_default_fills_a_missing_parameter_and_a_value_replaces_it(): void
    {
        $this->assertSame(['100% juice', 'Cheese', 'Apples'], $this->titles('/crud/items/queries/open_from?params[position]=1'));
        $this->assertSame(['Bread'], $this->titles('/crud/items/queries/open_from?params[position]=1&params[done]=true'));
    }

    public function test_literal_values_and_defaults_work_in_the_query_itself(): void
    {
        $this->assertSame(['Apples', 'Bread'], $this->titles('/crud/items/queries/titled?params[text]=a'));
        $this->assertSame(['Apples', 'Bread', 'Cheese'], $this->titles('/crud/items/queries/titled'));
    }

    public function test_a_query_without_parameters_and_the_callers_paging(): void
    {
        $this->assertSame(['100% juice', 'Apples', 'Bread', 'Cheese'], $this->titles('/crud/items/queries/everything'));
        $this->getJson('/crud/items/queries/everything?limit=2&page=2')->assertOk()->assertJsonPath('data.0.title', 'Bread')->assertJsonPath('pagination.total', 4);
        $this->getJson('/crud/items/queries/everything?count=only')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 4);
    }

    public function test_the_owner_rule_applies_on_every_run(): void
    {
        $this->assertSame(['Bread'], $this->titles('/crud/items/queries/due_on?params[day]=2026-12-02'));
        $this->assertSame([], $this->titles('/crud/items/queries/titled?params[text]=Hidden'));
        $this->assertSame([], $this->titles('/crud/items/queries/by_id?params[id]='.DB::table('nq_items')->where('title', 'Hidden')->value('item_id')));

        $this->actingAs($this->other);
        $this->assertSame(['Hidden'], $this->titles('/crud/items/queries/due_on?params[day]=2026-12-02'));
    }

    public function test_the_caller_cannot_widen_a_query_with_its_own_filter_or_sort(): void
    {
        $this->assertSame(['100% juice', 'Cheese'], $this->titles('/crud/items/queries/open_from?params[position]=3&filter[position]=1&filter[title]=Apples&sort=title&join=membership_id'));
    }

    #[DataProvider('refusedParameters')]
    public function test_missing_unknown_and_mistyped_parameters_are_refused(string $url): void
    {
        $this->getJson($url)->assertStatus(400);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedParameters(): array
    {
        return [
            'required parameter missing' => ['/crud/items/queries/open_from'],
            'unknown parameter' => ['/crud/items/queries/open_from?params[position]=1&params[nope]=1'],
            'integer that is text' => ['/crud/items/queries/open_from?params[position]=abc'],
            'integer that is a decimal' => ['/crud/items/queries/open_from?params[position]=1.5'],
            'boolean that is a number' => ['/crud/items/queries/open_from?params[position]=1&params[done]=2'],
            'date that does not exist' => ['/crud/items/queries/due_on?params[day]=2026-02-30'],
            'date with a time' => ['/crud/items/queries/due_on?params[day]=2026-12-02%2010:00'],
            'record id that is SQL' => ['/crud/items/queries/by_id?params[id]=1%20OR%201%3D1'],
            'parameters that are not a map' => ['/crud/items/queries/open_from?params=1'],
            'parameter that is a list' => ['/crud/items/queries/open_from?params[position][]=1'],
            'string that is too long' => ['/crud/items/queries/titled?params[text]=x'.'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'],
        ];
    }

    public function test_an_unknown_query_or_table_is_not_found(): void
    {
        $this->getJson('/crud/items/queries/nope')->assertStatus(404);
        $this->getJson('/crud/nothing/queries/everything')->assertStatus(404);
    }

    public function test_injection_in_values_is_only_ever_data(): void
    {
        $this->assertSame([], $this->titles('/crud/items/queries/titled?params[text]='.urlencode("' OR '1'='1")));
        $this->assertSame([], $this->titles('/crud/items/queries/titled?params[text]='.urlencode('x%; DROP TABLE nq_items; --')));
        $this->assertSame(5, DB::table('nq_items')->count());
    }

    public function test_the_table_map_lists_each_query_with_its_parameters_only(): void
    {
        $map = app(MapStore::class)->tableMap('install', 'items');
        $this->assertCount(5, $map['queries']);

        $query = app(MapReader::class)->table('install', 'items')['queries'][0];
        $this->assertSame(['name', 'description', 'params'], array_keys($query));
        $this->assertSame('open_from', $query['name']);
        $this->assertSame('integer', $query['params'][0]['type']);
    }

    public function test_saving_the_map_again_versions_the_queries(): void
    {
        $this->saveItemsMap([['name' => 'everything']]);

        $latest = app(MapStore::class)->tableHistory('install', 'items')->first();
        $this->assertSame(['name' => 'everything', 'description' => null, 'params' => [], 'filter' => [], 'sort' => null, 'join' => null], $latest->snapshot['queries'][0]);
        $this->assertArrayHasKey('queries', $latest->diff['table']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidQueries')]
    public function test_invalid_queries_are_refused_when_the_map_is_saved(array $query, string $errorKey): void
    {
        try {
            $this->saveItemsMap([$query]);
            $this->fail('The map should be refused.');
        } catch (InvalidMapException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidQueries(): array
    {
        $param = ['name' => 'p', 'type' => 'string'];

        return [
            'bad name' => [['name' => 'Bad Name'], 'queries.0.name'],
            'unknown key' => [['name' => 'q', 'sql' => 'select 1'], 'queries.0'],
            'unknown parameter type' => [['name' => 'q', 'params' => [['name' => 'p', 'type' => 'float']]], 'queries.0.params.0.type'],
            'default of the wrong type' => [['name' => 'q', 'params' => [['name' => 'p', 'type' => 'integer', 'default' => 'x']]], 'queries.0.params.0.default'],
            'duplicate parameter' => [['name' => 'q', 'params' => [$param, $param]], 'queries.0.params.1.name'],
            'filter on an unmapped field' => [['name' => 'q', 'filter' => [['field' => 'nope', 'value' => 1]]], 'queries.0.filter.0.field'],
            'filter operator that is SQL' => [['name' => 'q', 'filter' => [['field' => 'title', 'op' => '= 1 OR 1', 'value' => 1]]], 'queries.0.filter.0.op'],
            'filter on an undeclared parameter' => [['name' => 'q', 'filter' => [['field' => 'title', 'param' => 'p']]], 'queries.0.filter.0.param'],
            'filter with a value and a parameter' => [['name' => 'q', 'params' => [$param], 'filter' => [['field' => 'title', 'value' => 1, 'param' => 'p']]], 'queries.0.filter.0'],
            'filter with neither' => [['name' => 'q', 'filter' => [['field' => 'title']]], 'queries.0.filter.0'],
            'the same field and operator twice' => [['name' => 'q', 'filter' => [['field' => 'title', 'value' => 1], ['field' => 'title', 'value' => 2]]], 'queries.0.filter.1'],
            'sort on three fields' => [['name' => 'q', 'sort' => 'title,position,done'], 'queries.0.sort'],
            'sort that is SQL' => [['name' => 'q', 'sort' => 'title;drop table x'], 'queries.0.sort'],
            'join on an unmapped field' => [['name' => 'q', 'join' => 'nope'], 'queries.0.join'],
            'queries that are not a list' => [['x' => 1], 'queries.0.name'],
        ];
    }

    private function itemFor(User $user, string $title, int $position, bool $done, ?string $due): int
    {
        return DB::table('nq_items')->insertGetId([
            'membership_id' => $user->uuid, 'title' => $title, 'position' => $position, 'done' => $done, 'due' => $due,
        ]);
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('nq_items');
    }

    private function clearAppMaps(): void
    {
        foreach (MapTable::query()->get() as $table) {
            if (! $table->isSystem()) {
                MapVersion::query()->where('subject_type', 'table')->where('subject_id', $table->map_table_id)->delete();
                $table->fields()->get()->each(fn (MapField $field) => $field->delete());
                $table->delete();
            }
        }
    }
}
