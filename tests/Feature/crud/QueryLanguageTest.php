<?php

namespace Tests\Feature\crud;

use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\MapStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The client query language on the CRUD list route (Pane#223): filter operators, sort on at most 2
 * fields, paging limits and count, over an owner-scoped table so the access rule is exercised too.
 */
class QueryLanguageTest extends TestCase
{
    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('ql_items', function ($table): void {
            $table->increments('item_id');
            $table->string('membership_id');
            $table->string('title');
            $table->integer('position');
            $table->string('secret')->nullable();
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $store->saveTable('install', [
            'name' => 'items', 'sql_name' => 'ql_items',
            'access' => ['owner' => ['key' => 'membership_id']],
            'fields' => [
                ['name' => 'item_id', 'type' => 'number', 'primary' => true],
                ['name' => 'membership_id', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string', 'sortable' => true],
                ['name' => 'position', 'type' => 'number', 'sortable' => true],
                ['name' => 'secret', 'type' => 'password', 'sortable' => true],
            ],
        ], 'test');

        $this->owner = $this->makePaneUser(2);
        $this->other = $this->makePaneUser(2);

        foreach ([['Apples', 1], ['Bread', 2], ['Cheese', 2], ['100% juice', 3], ['Dates', 4]] as [$title, $position]) {
            $this->itemFor($this->owner, $title, $position);
        }
        $this->itemFor($this->other, 'Hidden', 2);
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
     * @return list<string>
     */
    private function titles(string $query): array
    {
        return $this->getJson('/crud/items?'.$query)->assertOk()->collect('data')->pluck('title')->all();
    }

    public function test_equality_works_with_and_without_the_operator(): void
    {
        $this->assertSame(['Bread', 'Cheese'], $this->titles('filter[position]=2&sort=title'));
        $this->assertSame(['Bread', 'Cheese'], $this->titles('filter[position][eq]=2&sort=title'));
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('operators')]
    public function test_each_filter_operator(string $query, array $expected): void
    {
        $this->assertSame($expected, $this->titles($query.'&sort=title'));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function operators(): array
    {
        return [
            'ne' => ['filter[position][ne]=2', ['100% juice', 'Apples', 'Dates']],
            'gt' => ['filter[position][gt]=2', ['100% juice', 'Dates']],
            'gte' => ['filter[position][gte]=3', ['100% juice', 'Dates']],
            'lt' => ['filter[position][lt]=2', ['Apples']],
            'lte' => ['filter[position][lte]=2', ['Apples', 'Bread', 'Cheese']],
            'in' => ['filter[title][in]=Apples,Dates,Hidden', ['Apples', 'Dates']],
            'contains' => ['filter[title][contains]=ea', ['Bread']],
            'contains is a literal match' => ['filter[title][contains]=%25', ['100% juice']],
            'contains does not treat _ as a wildcard' => ['filter[title][contains]=_', []],
            'two operators on one field' => ['filter[position][gt]=1&filter[position][lt]=4', ['100% juice', 'Bread', 'Cheese']],
            'filters combine with AND' => ['filter[position][gte]=2&filter[title][contains]=e', ['100% juice', 'Bread', 'Cheese', 'Dates']],
        ];
    }

    public function test_filters_never_reach_another_users_rows(): void
    {
        $this->assertSame([], $this->titles('filter[title]=Hidden'));
        $this->assertSame([], $this->titles('filter[title][in]=Hidden'));
        $this->assertSame([], $this->titles('filter[title][contains]=Hidd'));
        $this->getJson('/crud/items?count=only&filter[position]=2')->assertOk()->assertJsonPath('pagination.total', 2);
    }

    public function test_sort_on_two_fields_and_descending(): void
    {
        $this->assertSame(['Dates', '100% juice', 'Cheese', 'Bread', 'Apples'], $this->titles('sort=-position,-title'));
        $this->assertSame(['Bread', 'Cheese', 'Apples'], $this->titles('sort=-position,title&filter[position][lte]=2'));
        $this->assertSame(['Cheese', 'Bread', 'Apples'], $this->titles('sort=title&order=desc&filter[position][lte]=2'));
    }

    public function test_sort_is_limited_to_two_declared_fields(): void
    {
        $this->getJson('/crud/items?sort=title,position,item_id')->assertStatus(400);
        $this->getJson('/crud/items?sort=membership_id')->assertStatus(400);
        $this->getJson('/crud/items?sort=secret')->assertStatus(400);
        $this->getJson('/crud/items?sort=title,nope')->assertStatus(400);
    }

    public function test_ties_are_broken_by_the_key_so_pages_do_not_overlap(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $page) {
            $ids = [...$ids, ...$this->getJson("/crud/items?sort=position&limit=2&page=$page")->assertOk()->collect('data')->pluck('item_id')->all()];
        }

        $this->assertCount(5, $ids);
        $this->assertCount(5, array_unique($ids));
    }

    public function test_paging_defaults_and_limits(): void
    {
        $this->getJson('/crud/items')->assertOk()->assertJsonPath('pagination.limit', 25)->assertJsonPath('pagination.total', 5);
        $this->getJson('/crud/items?limit=2&page=3')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.pages', 3);
        $this->getJson('/crud/items?limit=100')->assertOk();
        $this->getJson('/crud/items?limit=101')->assertStatus(400);
        $this->getJson('/crud/items?limit=0')->assertStatus(400);
        $this->getJson('/crud/items?page=0')->assertStatus(400);
    }

    public function test_count_only_returns_the_filtered_total_and_no_rows(): void
    {
        $this->getJson('/crud/items?count=only')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 5);
        $this->getJson('/crud/items?count=only&filter[position][gte]=2')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 4);
        $this->getJson('/crud/items?count=sum')->assertStatus(400);
    }

    #[DataProvider('refusedQueries')]
    public function test_unknown_unreadable_and_malicious_queries_are_refused(string $query): void
    {
        $this->getJson('/crud/items?'.$query)->assertStatus(400);
        $this->assertSame(6, DB::table('ql_items')->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedQueries(): array
    {
        return [
            'unknown field' => ['filter[nope]=1'],
            'unknown field with operator' => ['filter[nope][gt]=1'],
            'password field' => ['filter[secret]=x'],
            'password field with operator' => ['filter[secret][contains]=x'],
            'unknown operator' => ['filter[title][regexp]=a'],
            'operator that is SQL' => ['filter[title][%3D1%20OR%201%3D1]=a'],
            'field that is SQL' => ['filter[title%20OR%201%3D1]=a'],
            'nested value' => ['filter[title][eq][x]=a'],
            'filter that is not a list' => ['filter=title'],
            'sort that is SQL' => ['sort=title%3Bdrop%20table%20ql_items'],
            'order that is SQL' => ['order=asc%3Bdrop%20table%20ql_items'],
        ];
    }

    public function test_search_matches_text_columns_case_insensitively_and_literally(): void
    {
        $this->assertSame(['Bread'], $this->titles('q=BREA'));
        $this->assertSame(['100% juice'], $this->titles('q=%25'));
        $this->assertSame([], $this->titles('q=_'));
        $this->assertSame([], $this->titles('q=nothing-like-this'));
        $this->assertSame(['Dates', 'Apples'], $this->titles('q=s&sort=-title&filter[position][in]=1,4'));
    }

    public function test_search_never_matches_a_password_column(): void
    {
        DB::table('ql_items')->where('title', 'Dates')->update(['secret' => 'hunter2']);

        $this->assertSame([], $this->titles('q=hunter2'));
    }

    public function test_search_combines_with_filters_paging_and_the_total(): void
    {
        $this->assertSame(['Cheese', 'Dates'], $this->titles('q=s&sort=title&filter[position][gte]=2'));

        $first = $this->getJson('/crud/items?q=s&sort=title&limit=2&page=1')->assertOk()
            ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.pages', 2);
        $second = $this->getJson('/crud/items?q=s&sort=title&limit=2&page=2')->assertOk()->assertJsonPath('pagination.total', 3);
        $this->assertSame(['Apples', 'Cheese'], $first->collect('data')->pluck('title')->all());
        $this->assertSame(['Dates'], $second->collect('data')->pluck('title')->all());
        $this->getJson('/crud/items?q=s&count=only')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 3);
    }

    public function test_an_empty_search_is_no_search(): void
    {
        $this->assertCount(5, $this->titles('q='));
    }

    public function test_search_never_reaches_another_users_rows(): void
    {
        $this->assertSame([], $this->titles('q=Hidd'));
        $this->getJson('/crud/items?count=only&q=Hidden')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_search_length_limit_and_shape(): void
    {
        $this->getJson('/crud/items?q='.str_repeat('s', 200))->assertOk();
        $this->getJson('/crud/items?q='.str_repeat('s', 201))->assertStatus(400);
        $this->getJson('/crud/items?q[]=a')->assertStatus(400);
    }

    public function test_injection_in_values_is_only_ever_data(): void
    {
        $this->assertSame([], $this->titles('filter[title]='.urlencode("' OR '1'='1")));
        $this->assertSame([], $this->titles('filter[title][in]='.urlencode("x') OR ('1'='1")));
        $this->assertSame(6, DB::table('ql_items')->count());
    }

    private function itemFor(User $user, string $title, int $position): int
    {
        return DB::table('ql_items')->insertGetId(['membership_id' => $user->uuid, 'title' => $title, 'position' => $position]);
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('ql_items');
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
