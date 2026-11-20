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
 * Read and edit roles on the members rule (Pane#195): list_members.role says
 * whether a member only reads or also writes.
 */
class MemberRolesTest extends TestCase
{
    private User $owner;

    private User $reader;

    private User $editor;

    private User $outsider;

    private int $listId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('rol_lists', function ($table): void {
            $table->increments('list_id');
            $table->string('title');
        });
        Schema::create('rol_list_members', function ($table): void {
            $table->increments('list_member_id');
            $table->unsignedInteger('list_id');
            $table->string('user_uuid');
            $table->string('role')->default('read');
        });
        Schema::create('rol_list_items', function ($table): void {
            $table->increments('list_item_id');
            $table->unsignedInteger('list_id');
            $table->string('label');
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $rule = fn (): array => ['members' => [
            'table' => 'list_members', 'key' => 'list_id', 'members_key' => 'list_id', 'members_user' => 'user_uuid',
            'role' => ['field' => 'role', 'read' => 'read', 'edit' => 'edit'],
        ]];
        $store->saveTable('install', [
            'name' => 'lists', 'sql_name' => 'rol_lists', 'access' => $rule(),
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string'],
            ],
        ], 'test');
        $store->saveTable('install', [
            'name' => 'list_members', 'sql_name' => 'rol_list_members', 'access' => $rule(),
            'fields' => [
                ['name' => 'list_member_id', 'type' => 'number', 'primary' => true],
                ['name' => 'list_id', 'type' => 'number'],
                ['name' => 'user_uuid', 'type' => 'string'],
                ['name' => 'role', 'type' => 'string'],
            ],
        ], 'test');
        $store->saveTable('install', [
            'name' => 'list_items', 'sql_name' => 'rol_list_items', 'access' => $rule(),
            'fields' => [
                ['name' => 'list_item_id', 'type' => 'number', 'primary' => true],
                ['name' => 'list_id', 'type' => 'number'],
                ['name' => 'label', 'type' => 'string'],
            ],
        ], 'test');

        $this->owner = $this->makePaneUser(2);
        $this->reader = $this->makePaneUser(2);
        $this->editor = $this->makePaneUser(2);
        $this->outsider = $this->makePaneUser(2);

        $this->listId = DB::table('rol_lists')->insertGetId(['title' => 'Groceries']);
        foreach ([[$this->owner, 'edit'], [$this->reader, 'read'], [$this->editor, 'edit']] as [$user, $role]) {
            DB::table('rol_list_members')->insert(['list_id' => $this->listId, 'user_uuid' => $user->uuid, 'role' => $role]);
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->clearAppMaps();
        User::query()->whereIn('user_id', [$this->owner->user_id, $this->reader->user_id, $this->editor->user_id, $this->outsider->user_id])->delete();

        parent::tearDown();
    }

    public function test_a_read_member_reads_but_cannot_write(): void
    {
        $itemId = DB::table('rol_list_items')->insertGetId(['list_id' => $this->listId, 'label' => 'Milk']);
        $this->actingAs($this->reader);

        $this->getJson('/crud/lists')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/crud/lists/{$this->listId}")->assertOk();
        $this->getJson("/crud/list_items/$itemId")->assertOk();
        $this->getJson('/crud/list_members')->assertOk()->assertJsonCount(3, 'data');

        $this->withCsrfToken()->putJson("/crud/lists/{$this->listId}", ['title' => 'Mine'])->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/lists/{$this->listId}")->assertForbidden();
        $this->withCsrfToken()->postJson('/crud/list_items', ['list_id' => $this->listId, 'label' => 'Eggs'])->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/list_items/$itemId", ['list_id' => $this->listId, 'label' => 'Beer'])->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/list_items/$itemId")->assertForbidden();
        $this->withCsrfToken()->postJson('/crud/list_members', ['list_id' => $this->listId, 'user_uuid' => $this->outsider->uuid, 'role' => 'edit'])->assertForbidden();

        $this->assertSame('Groceries', DB::table('rol_lists')->value('title'));
        $this->assertSame('Milk', DB::table('rol_list_items')->value('label'));
        $this->assertSame(3, DB::table('rol_list_members')->count());
    }

    public function test_an_edit_member_reads_and_writes(): void
    {
        $this->actingAs($this->editor);

        $this->getJson("/crud/lists/{$this->listId}")->assertOk();
        $this->withCsrfToken()->putJson("/crud/lists/{$this->listId}", ['title' => 'Weekly'])->assertOk();
        $this->withCsrfToken()->postJson('/crud/list_items', ['list_id' => $this->listId, 'label' => 'Eggs'])
            ->assertStatus(Response::HTTP_CREATED);
        $itemId = (int) DB::table('rol_list_items')->value('list_item_id');
        $this->withCsrfToken()->deleteJson("/crud/list_items/$itemId")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame('Weekly', DB::table('rol_lists')->value('title'));
    }

    public function test_an_edit_member_can_share_the_list_read_only(): void
    {
        $this->actingAs($this->editor);

        $this->withCsrfToken()->postJson('/crud/list_members', ['list_id' => $this->listId, 'user_uuid' => $this->outsider->uuid, 'role' => 'read'])
            ->assertStatus(Response::HTTP_CREATED);
        $this->actingAs($this->outsider);
        $this->getJson("/crud/lists/{$this->listId}")->assertOk();
        $this->withCsrfToken()->putJson("/crud/lists/{$this->listId}", ['title' => 'Hacked'])->assertForbidden();
    }

    public function test_a_read_member_cannot_promote_themselves(): void
    {
        $memberId = (int) DB::table('rol_list_members')->where('user_uuid', $this->reader->uuid)->value('list_member_id');
        $this->actingAs($this->reader);

        $this->withCsrfToken()->putJson("/crud/list_members/$memberId", ['list_id' => $this->listId, 'user_uuid' => $this->reader->uuid, 'role' => 'edit'])->assertForbidden();
        $this->assertSame('read', DB::table('rol_list_members')->where('list_member_id', $memberId)->value('role'));
    }

    public function test_a_non_member_has_no_access(): void
    {
        $this->actingAs($this->outsider);

        $this->getJson('/crud/lists')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/crud/lists/{$this->listId}")->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/lists/{$this->listId}", ['title' => 'Mine'])->assertForbidden();
    }

    public function test_an_unknown_role_value_gives_no_access(): void
    {
        DB::table('rol_list_members')->where('user_uuid', $this->reader->uuid)->update(['role' => 'owner']);
        $this->actingAs($this->reader);

        $this->getJson('/crud/lists')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/crud/lists/{$this->listId}")->assertForbidden();
    }

    public function test_the_creator_of_a_list_gets_edit_rights(): void
    {
        $this->actingAs($this->outsider);

        $this->withCsrfToken()->postJson('/crud/lists', ['title' => 'Mine'])->assertStatus(Response::HTTP_CREATED);
        $newId = (int) DB::table('rol_lists')->where('title', 'Mine')->value('list_id');
        $this->assertSame('edit', DB::table('rol_list_members')->where('list_id', $newId)->value('role'));
        $this->withCsrfToken()->putJson("/crud/lists/$newId", ['title' => 'Still mine'])->assertOk();
    }

    public function test_a_malformed_role_is_rejected_when_saved(): void
    {
        foreach ([['field' => 'role', 'read' => 'x', 'edit' => 'x'], ['field' => 'role', 'read' => 'read'], 'edit'] as $role) {
            try {
                app(MapStore::class)->saveTable('install', [
                    'name' => 'bad_lists', 'sql_name' => 'rol_lists',
                    'access' => ['members' => ['table' => 'list_members', 'key' => 'list_id', 'members_key' => 'list_id', 'members_user' => 'user_uuid', 'role' => $role]],
                    'fields' => [['name' => 'list_id', 'type' => 'number', 'primary' => true]],
                ], 'test');
                $this->fail('A malformed role should be rejected.');
            } catch (InvalidMapException $e) {
                $this->assertArrayHasKey('access.members.role', $e->errors);
            }
        }
    }

    private function dropTables(): void
    {
        foreach (['rol_list_items', 'rol_list_members', 'rol_lists'] as $table) {
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
