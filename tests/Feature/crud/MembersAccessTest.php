<?php

namespace Tests\Feature\crud;

use App\Exceptions\Maps\InvalidMapException;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\MapStore;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The members-table rule on the CRUD route (Pane#185), over Sweegly-shaped
 * tables: lists through list_members.list_id, list_members through itself and
 * list_items through list_members.list_id.
 */
class MembersAccessTest extends TestCase
{
    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('mbr_lists', function ($table): void {
            $table->increments('list_id');
            $table->string('title');
        });
        Schema::create('mbr_list_members', function ($table): void {
            $table->increments('list_member_id');
            $table->unsignedInteger('list_id');
            $table->string('user_uuid');
        });
        Schema::create('mbr_list_items', function ($table): void {
            $table->increments('list_item_id');
            $table->unsignedInteger('list_id');
            $table->string('label');
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $rule = fn (): array => ['members' => ['table' => 'list_members', 'key' => 'list_id', 'members_key' => 'list_id', 'members_user' => 'user_uuid']];
        $store->saveTable('install', [
            'name' => 'lists', 'sql_name' => 'mbr_lists', 'access' => $rule(),
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => 40]]],
            ],
        ], 'test');
        $store->saveTable('install', [
            'name' => 'list_members', 'sql_name' => 'mbr_list_members', 'access' => $rule(),
            'fields' => [
                ['name' => 'list_member_id', 'type' => 'number', 'primary' => true],
                ['name' => 'list_id', 'type' => 'number'],
                ['name' => 'user_uuid', 'type' => 'string'],
            ],
        ], 'test');
        $store->saveTable('install', [
            'name' => 'list_items', 'sql_name' => 'mbr_list_items', 'access' => $rule(),
            'fields' => [
                ['name' => 'list_item_id', 'type' => 'number', 'primary' => true],
                ['name' => 'list_id', 'type' => 'number'],
                ['name' => 'label', 'type' => 'string'],
            ],
        ], 'test');

        $this->owner = $this->makePaneUser(2);
        $this->other = $this->makePaneUser(2);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->clearAppMaps();
        User::query()->whereIn('user_id', [$this->owner->user_id, $this->other->user_id])->delete();

        parent::tearDown();
    }

    public function test_a_user_starts_a_list_and_becomes_its_member(): void
    {
        $this->actingAs($this->owner);

        $response = $this->withCsrfToken()->postJson('/crud/lists', ['title' => 'Groceries']);

        $response->assertStatus(Response::HTTP_CREATED);
        $listId = (int) DB::table('mbr_lists')->value('list_id');
        $this->assertDatabaseHas('mbr_list_members', ['list_id' => $listId, 'user_uuid' => $this->owner->uuid]);
        $this->getJson("/crud/lists/$listId")->assertOk()->assertJsonPath('data.0.title', 'Groceries');
    }

    public function test_a_member_reads_updates_and_deletes_their_rows(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $this->actingAs($this->owner);

        $this->withCsrfToken()->putJson("/crud/lists/$listId", ['title' => 'Weekly'])->assertOk();
        $this->assertSame('Weekly', DB::table('mbr_lists')->where('list_id', $listId)->value('title'));

        $this->withCsrfToken()->postJson('/crud/list_items', ['list_id' => $listId, 'label' => 'Milk'])
            ->assertStatus(Response::HTTP_CREATED);
        $itemId = (int) DB::table('mbr_list_items')->value('list_item_id');
        $this->getJson("/crud/list_items/$itemId")->assertOk();
        $this->withCsrfToken()->deleteJson("/crud/list_items/$itemId")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame(0, DB::table('mbr_list_items')->count());
    }

    public function test_a_non_member_sees_and_changes_nothing(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $itemId = DB::table('mbr_list_items')->insertGetId(['list_id' => $listId, 'label' => 'Milk']);
        $this->actingAs($this->other);

        $this->getJson('/crud/lists')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/crud/list_items')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/crud/lists/$listId")->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/lists/$listId", ['title' => 'Mine now'])->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/lists/$listId")->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/list_items/$itemId")->assertForbidden();
        $this->withCsrfToken()->postJson('/crud/list_items', ['list_id' => $listId, 'label' => 'Eggs'])->assertForbidden();

        $this->assertSame('Groceries', DB::table('mbr_lists')->where('list_id', $listId)->value('title'));
        $this->assertSame(1, DB::table('mbr_list_items')->count());
    }

    public function test_alternate_subject_spellings_get_the_same_scope(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        DB::table('mbr_list_items')->insert(['list_id' => $listId, 'label' => 'Milk']);
        $this->actingAs($this->other);

        $this->getJson('/crud/listItems?sort=list_item_id')->assertOk()->assertJsonCount(0, 'data');
        $this->withCsrfToken()->postJson('/crud/listItems', ['list_id' => $listId, 'label' => 'Eggs'])->assertForbidden();
        $this->assertSame(1, DB::table('mbr_list_items')->count());
    }

    public function test_a_user_cannot_add_themselves_to_someone_elses_list(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $this->actingAs($this->other);

        $this->withCsrfToken()
            ->postJson('/crud/list_members', ['list_id' => $listId, 'user_uuid' => $this->other->uuid])
            ->assertForbidden();

        $this->assertSame(1, DB::table('mbr_list_members')->count());
    }

    public function test_a_member_can_add_another_user_to_their_list(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $this->actingAs($this->owner);

        $this->withCsrfToken()
            ->postJson('/crud/list_members', ['list_id' => $listId, 'user_uuid' => $this->other->uuid])
            ->assertStatus(Response::HTTP_CREATED);

        $this->actingAs($this->other);
        $this->getJson("/crud/lists/$listId")->assertOk();
    }

    public function test_a_member_cannot_move_an_item_into_a_list_they_are_not_in(): void
    {
        $mine = $this->listFor($this->owner, 'Mine');
        $theirs = $this->listFor($this->other, 'Theirs');
        $itemId = DB::table('mbr_list_items')->insertGetId(['list_id' => $mine, 'label' => 'Milk']);
        $this->actingAs($this->owner);

        $this->withCsrfToken()->putJson("/crud/list_items/$itemId", ['list_id' => $theirs, 'label' => 'Milk'])->assertForbidden();

        $this->assertSame($mine, (int) DB::table('mbr_list_items')->where('list_item_id', $itemId)->value('list_id'));
    }

    public function test_writes_are_still_validated_by_the_map(): void
    {
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/lists', ['title' => str_repeat('x', 41)])
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame(0, DB::table('mbr_lists')->count());
    }

    public function test_an_administrator_keeps_access_to_every_row(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $this->actingAs($this->makePaneUser(1));

        $this->getJson("/crud/lists/$listId")->assertOk();
        $this->withCsrfToken()->putJson("/crud/lists/$listId", ['title' => 'Admin edit'])->assertOk();
    }

    public function test_system_subjects_stay_closed_to_members(): void
    {
        $this->actingAs($this->owner);

        $this->getJson('/crud/users')->assertForbidden();
        $this->getJson('/crud/tables')->assertForbidden();
    }

    public function test_a_rule_whose_members_table_is_not_mapped_fails_closed(): void
    {
        MapTable::query()->where('name', 'list_members')->get()->each(function (MapTable $table): void {
            MapVersion::query()->where('subject_type', 'table')->where('subject_id', $table->map_table_id)->delete();
            $table->fields()->get()->each(fn (MapField $field) => $field->delete());
            $table->delete();
        });
        $this->actingAs($this->owner);

        $this->getJson('/crud/lists')->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function test_a_rule_naming_an_unknown_key_field_is_rejected_when_saved(): void
    {
        $this->expectException(InvalidMapException::class);

        app(MapStore::class)->saveTable('install', [
            'name' => 'broken', 'sql_name' => 'mbr_broken',
            'access' => ['members' => ['table' => 'list_members', 'key' => 'nope', 'members_key' => 'list_id', 'members_user' => 'user_uuid']],
            'fields' => [['name' => 'broken_id', 'type' => 'number', 'primary' => true]],
        ], 'test');
    }

    private function listFor(User $user, string $title): int
    {
        $listId = DB::table('mbr_lists')->insertGetId(['title' => $title]);
        DB::table('mbr_list_members')->insert(['list_id' => $listId, 'user_uuid' => $user->uuid]);

        return $listId;
    }

    private function dropTables(): void
    {
        foreach (['mbr_list_items', 'mbr_list_members', 'mbr_lists'] as $table) {
            Schema::dropIfExists($table);
        }
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
