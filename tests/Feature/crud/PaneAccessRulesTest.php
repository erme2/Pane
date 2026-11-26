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
 * Pane filters rows from the signed-in session with its own rules (Pane#232):
 * config/pane_access.php, not the map. A table with no rule is refused.
 */
class PaneAccessRulesTest extends TestCase
{
    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('par_catalog', function ($table): void {
            $table->increments('catalog_id');
            $table->string('title');
        });
        Schema::create('par_notes', function ($table): void {
            $table->increments('note_id');
            $table->string('user_uuid');
            $table->string('body');
        });
        Schema::create('par_unruled', function ($table): void {
            $table->increments('unruled_id');
            $table->string('label');
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $store->saveTable('install', [
            'name' => 'catalog', 'sql_name' => 'par_catalog',
            'fields' => [
                ['name' => 'catalog_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => 40]]],
            ],
        ], 'test');
        $this->saveNotes();
        $this->saveUnruled();
        config(['pane_access.tables' => [
            'catalog' => ['read' => true],
            'notes' => ['owner' => ['key' => 'user_uuid']],
        ]]);

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

    public function test_a_table_with_no_rule_is_refused_for_reads_and_writes(): void
    {
        DB::table('par_unruled')->insert(['label' => 'x']);
        $this->actingAs($this->owner);

        $this->getJson('/crud/unruled')->assertForbidden();
        $this->getJson('/crud/unruled/1')->assertForbidden();
        $this->withCsrfToken()->postJson('/crud/unruled', ['label' => 'y'])->assertForbidden();
        $this->withCsrfToken()->deleteJson('/crud/unruled/1')->assertForbidden();
        $this->assertSame(1, DB::table('par_unruled')->count());
    }

    public function test_an_administrator_still_reaches_a_table_with_no_rule(): void
    {
        DB::table('par_unruled')->insert(['label' => 'x']);
        $this->authenticateAsAdministrator();

        $this->getJson('/crud/unruled')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_read_rule_lets_a_user_read_but_not_write(): void
    {
        DB::table('par_catalog')->insert(['title' => 'Milk']);
        $this->actingAs($this->owner);

        $this->getJson('/crud/catalog')->assertOk()->assertJsonCount(1, 'data');
        $this->withCsrfToken()->postJson('/crud/catalog', ['title' => 'Eggs'])->assertForbidden();
        $this->withCsrfToken()->putJson('/crud/catalog/1', ['title' => 'Eggs'])->assertForbidden();
        $this->withCsrfToken()->deleteJson('/crud/catalog/1')->assertForbidden();
        $this->assertSame('Milk', DB::table('par_catalog')->value('title'));
    }

    public function test_a_pane_owner_rule_hides_other_users_rows(): void
    {
        DB::table('par_notes')->insert([
            ['user_uuid' => $this->owner->uuid, 'body' => 'mine'],
            ['user_uuid' => $this->other->uuid, 'body' => 'theirs'],
        ]);
        $this->actingAs($this->owner);

        $this->getJson('/crud/notes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'mine');
        $this->getJson('/crud/notes/2')->assertForbidden();
        $this->withCsrfToken()->deleteJson('/crud/notes/2')->assertForbidden();
        $this->assertSame(2, DB::table('par_notes')->count());
    }

    public function test_a_pane_rule_wins_over_the_maps_access_rule(): void
    {
        // The map's rule would match no row; Pane's rule for the table is the one that counts.
        $this->saveNotes(['owner' => ['key' => 'body']]);
        DB::table('par_notes')->insert(['user_uuid' => $this->owner->uuid, 'body' => 'mine']);
        $this->actingAs($this->owner);

        $this->getJson('/crud/notes')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_map_rule_still_counts_while_pane_has_none_for_the_table(): void
    {
        $this->saveUnruled(['owner' => ['key' => 'label']]);
        DB::table('par_unruled')->insert([['label' => $this->owner->uuid], ['label' => 'other']]);
        $this->actingAs($this->owner);

        $this->getJson('/crud/unruled')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sweegly_rules_ship_in_the_config_file(): void
    {
        $tables = (require base_path('config/pane_access.php'))['tables'];

        foreach (['categories', 'products', 'chains', 'shops', 'offers'] as $shared) {
            $this->assertSame(['read' => true], $tables[$shared]);
        }
        foreach (['lists', 'list_members', 'list_items'] as $members) {
            $this->assertSame('list_members', $tables[$members]['members']['table']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $access
     */
    private function saveNotes(?array $access = null): void
    {
        app(MapStore::class)->saveTable('install', array_filter([
            'name' => 'notes', 'sql_name' => 'par_notes', 'access' => $access,
            'fields' => [
                ['name' => 'note_id', 'type' => 'number', 'primary' => true],
                ['name' => 'user_uuid', 'type' => 'string'],
                ['name' => 'body', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => 40]]],
            ],
        ]), 'test');
    }

    /**
     * @param  array<string, mixed>|null  $access
     */
    private function saveUnruled(?array $access = null): void
    {
        app(MapStore::class)->saveTable('install', array_filter([
            'name' => 'unruled', 'sql_name' => 'par_unruled', 'access' => $access,
            'fields' => [
                ['name' => 'unruled_id', 'type' => 'number', 'primary' => true],
                ['name' => 'label', 'type' => 'string'],
            ],
        ]), 'test');
    }

    private function dropTables(): void
    {
        foreach (['par_catalog', 'par_notes', 'par_unruled'] as $table) {
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
