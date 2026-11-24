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
 * The creator part of the members rule (Pane#227): Pane sets the creator column
 * of a new parent row from the signed-in user, never from the client.
 */
class MemberCreatorTest extends TestCase
{
    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('mcr_lists', function ($table): void {
            $table->increments('list_id');
            $table->string('title');
            $table->string('created_by');
        });
        Schema::create('mcr_list_members', function ($table): void {
            $table->increments('list_member_id');
            $table->unsignedInteger('list_id');
            $table->string('user_uuid');
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $rule = ['members' => ['table' => 'list_members', 'key' => 'list_id', 'members_key' => 'list_id', 'members_user' => 'user_uuid']];
        $store->saveTable('install', [
            'name' => 'lists', 'sql_name' => 'mcr_lists', 'access' => ['members' => $rule['members'] + ['creator' => 'created_by']],
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'created_by', 'type' => 'string'],
            ],
        ], 'test');
        $store->saveTable('install', [
            'name' => 'list_members', 'sql_name' => 'mcr_list_members', 'access' => $rule,
            'fields' => [
                ['name' => 'list_member_id', 'type' => 'number', 'primary' => true],
                ['name' => 'list_id', 'type' => 'number'],
                ['name' => 'user_uuid', 'type' => 'string'],
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

    public function test_a_name_only_create_gets_the_signed_in_creator_and_member_row(): void
    {
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/lists', ['title' => 'Groceries'])->assertStatus(Response::HTTP_CREATED);

        $listId = (int) DB::table('mcr_lists')->value('list_id');
        $this->assertSame($this->owner->uuid, DB::table('mcr_lists')->value('created_by'));
        $this->assertDatabaseHas('mcr_list_members', ['list_id' => $listId, 'user_uuid' => $this->owner->uuid]);
    }

    public function test_a_creator_sent_by_the_client_is_ignored_on_create_and_update(): void
    {
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/lists', ['title' => 'Groceries', 'created_by' => $this->other->uuid])->assertStatus(Response::HTTP_CREATED);
        $listId = (int) DB::table('mcr_lists')->value('list_id');
        $this->assertSame($this->owner->uuid, DB::table('mcr_lists')->value('created_by'));

        $this->withCsrfToken()->putJson("/crud/lists/$listId", ['title' => 'Weekly', 'created_by' => $this->other->uuid])->assertOk();
        $this->assertSame($this->owner->uuid, DB::table('mcr_lists')->where('list_id', $listId)->value('created_by'));
        $this->assertSame('Weekly', DB::table('mcr_lists')->where('list_id', $listId)->value('title'));
    }

    public function test_a_create_without_a_signed_in_user_is_refused(): void
    {
        $this->withCsrfToken()->postJson('/crud/lists', ['title' => 'Groceries'])->assertUnauthorized();

        $this->assertSame(0, DB::table('mcr_lists')->count());
    }

    public function test_a_creator_that_is_not_a_field_of_the_table_is_refused(): void
    {
        $this->expectException(InvalidMapException::class);

        app(MapStore::class)->saveTable('install', [
            'name' => 'bad', 'sql_name' => 'mcr_lists',
            'access' => ['members' => ['table' => 'list_members', 'key' => 'list_id', 'members_key' => 'list_id', 'members_user' => 'user_uuid', 'creator' => 'nope']],
            'fields' => [['name' => 'list_id', 'type' => 'number', 'primary' => true]],
        ], 'test');
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

    private function dropTables(): void
    {
        Schema::dropIfExists('mcr_list_members');
        Schema::dropIfExists('mcr_lists');
    }
}
