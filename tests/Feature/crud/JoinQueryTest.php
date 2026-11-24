<?php

namespace Tests\Feature\crud;

use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\MapStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One join level in the client query language (Pane#224): a row plus the row it references, each
 * table read through its own access rule.
 */
class JoinQueryTest extends TestCase
{
    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('jn_lists', function ($table): void {
            $table->increments('list_id');
            $table->string('membership_id');
            $table->string('name');
            $table->string('secret')->nullable();
        });
        Schema::create('jn_items', function ($table): void {
            $table->increments('item_id');
            $table->string('membership_id');
            $table->string('title');
            $table->integer('list_id')->nullable();
            $table->integer('note_id')->nullable();
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $store->saveTable('install', [
            'name' => 'jn_lists', 'sql_name' => 'jn_lists',
            'access' => ['owner' => ['key' => 'membership_id']],
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'membership_id', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string', 'sortable' => true],
                ['name' => 'secret', 'type' => 'password', 'sortable' => true],
            ],
        ], 'test');
        $store->saveTable('install', [
            'name' => 'jn_items', 'sql_name' => 'jn_items',
            'access' => ['owner' => ['key' => 'membership_id']],
            'fields' => [
                ['name' => 'item_id', 'type' => 'number', 'primary' => true],
                ['name' => 'membership_id', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string', 'sortable' => true],
                ['name' => 'list_id', 'type' => 'number', 'nullable' => true,
                    'validations' => [['rule' => 'exists', 'value' => 'jn_lists,list_id']]],
                ['name' => 'note_id', 'type' => 'number', 'nullable' => true,
                    'validations' => [['rule' => 'exists', 'value' => 'users,user_id']]],
            ],
        ], 'test');

        $this->owner = $this->makePaneUser(2);
        $this->other = $this->makePaneUser(2);

        $mine = $this->list($this->owner, 'Groceries');
        $work = $this->list($this->owner, 'Work');
        $theirs = $this->list($this->other, 'Theirs');
        $this->item($this->owner, 'Milk', $mine);
        $this->item($this->owner, 'Report', $work);
        $this->item($this->owner, 'Loose', null);
        $this->item($this->owner, 'Trespass', $theirs);
        $this->item($this->other, 'Hidden', $theirs);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->clearAppMaps();
        User::query()->whereIn('user_id', [$this->owner->user_id, $this->other->user_id])->delete();

        parent::tearDown();
    }

    public function test_a_row_comes_with_the_row_it_references(): void
    {
        $rows = $this->getJson('/crud/jn_items?join=list_id&sort=title')->assertOk()->assertJsonPath('pagination.total', 4)->json('data');

        $this->assertSame(['Loose', 'Milk', 'Report', 'Trespass'], array_column($rows, 'title'));
        $this->assertNull($rows[0]['joined']['list_id']);
        $this->assertSame('Groceries', $rows[1]['joined']['list_id']['name']);
        $this->assertSame('Work', $rows[2]['joined']['list_id']['name']);
    }

    public function test_a_list_without_join_has_no_joined_key(): void
    {
        $this->getJson('/crud/jn_items')->assertOk()->assertJsonMissingPath('data.0.joined');
    }

    public function test_a_joined_row_the_policy_hides_looks_like_a_missing_one(): void
    {
        $rows = $this->getJson('/crud/jn_items?join=list_id&filter[title]=Trespass')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['joined']['list_id']);
        $this->assertStringNotContainsString('Theirs', json_encode($rows));
        $this->getJson('/crud/jn_items?join=list_id&filter[list_id.name]=Theirs')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_a_join_adds_no_rows_the_policy_hides(): void
    {
        $this->getJson('/crud/jn_items')->assertOk()->assertJsonPath('pagination.total', 4);
        $this->getJson('/crud/jn_items?join=list_id')->assertOk()->assertJsonPath('pagination.total', 4)->assertJsonCount(4, 'data');
        $this->getJson('/crud/jn_items?join=list_id&count=only')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 4);
        $this->assertStringNotContainsString('Hidden', $this->getJson('/crud/jn_items?join=list_id')->getContent());
    }

    public function test_filter_and_sort_on_a_joined_field(): void
    {
        $this->assertSame(['Report'], $this->titles('join=list_id&filter[list_id.name]=Work'));
        $this->assertSame(['Milk', 'Report'], $this->titles('join=list_id&filter[list_id.name][in]=Groceries,Work&sort=list_id.name'));
        $this->assertSame(['Report', 'Milk'], $this->titles('join=list_id&filter[list_id.name][in]=Groceries,Work&sort=-list_id.name'));
        $this->assertSame(['Milk'], $this->titles('join=list_id&filter[list_id.name][contains]=roc&filter[title]=Milk'));
    }

    public function test_a_joined_password_is_masked_and_is_not_a_filter_or_a_sort_key(): void
    {
        DB::table('jn_lists')->where('name', 'Groceries')->update(['secret' => 'hash']);

        $row = $this->getJson('/crud/jn_items?join=list_id&filter[title]=Milk')->assertOk()->json('data.0');
        $this->assertSame('********', $row['joined']['list_id']['secret']);
        $this->assertStringNotContainsString('hash', json_encode($row));
        $this->getJson('/crud/jn_items?join=list_id&filter[list_id.secret]=hash')->assertStatus(400);
        $this->getJson('/crud/jn_items?join=list_id&sort=list_id.secret')->assertStatus(400);
    }

    public function test_only_a_field_that_references_a_mapped_table_can_be_joined(): void
    {
        $this->getJson('/crud/jn_items?join=title')->assertStatus(400);
        $this->getJson('/crud/jn_items?join=nope')->assertStatus(400);
        // A reference to a table that is not a mapped install table (users) is refused.
        $this->getJson('/crud/jn_items?join=note_id')->assertStatus(400);
        // One level only: a joined field cannot be joined again, and a join is a plain field name.
        $this->getJson('/crud/jn_items?join=list_id.name')->assertStatus(400);
        $this->getJson('/crud/jn_items?join[]=list_id')->assertStatus(400);
        $this->getJson('/crud/jn_items?join=list_id,list_id')->assertStatus(400);
    }

    public function test_a_filter_on_a_table_that_is_not_joined_is_refused(): void
    {
        $this->getJson('/crud/jn_items?filter[list_id.name]=Work')->assertStatus(400);
    }

    public function test_a_join_on_a_single_row_read_is_ignored(): void
    {
        $id = DB::table('jn_items')->where('title', 'Milk')->value('item_id');

        $this->getJson('/crud/jn_items/'.$id.'?join=list_id')->assertOk()->assertJsonPath('data.0.title', 'Milk');
    }

    /**
     * @return list<string>
     */
    private function titles(string $query): array
    {
        return $this->getJson('/crud/jn_items?'.$query)->assertOk()->collect('data')->pluck('title')->all();
    }

    private function list(User $user, string $name): int
    {
        return DB::table('jn_lists')->insertGetId(['membership_id' => $user->uuid, 'name' => $name]);
    }

    private function item(User $user, string $title, ?int $list): int
    {
        return DB::table('jn_items')->insertGetId(['membership_id' => $user->uuid, 'title' => $title, 'list_id' => $list]);
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('jn_items');
        Schema::dropIfExists('jn_lists');
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
