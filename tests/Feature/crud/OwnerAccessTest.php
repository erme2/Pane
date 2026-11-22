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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The owner rule on the CRUD route (Pane#218), over a Sweegly-shaped
 * user_item_preferences table keyed on the owner's uuid.
 */
class OwnerAccessTest extends TestCase
{
    private User $owner;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('own_preferences', function ($table): void {
            $table->increments('preference_id');
            $table->string('membership_id');
            $table->string('note');
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $store->saveTable('install', [
            'name' => 'preferences', 'sql_name' => 'own_preferences',
            'access' => ['owner' => ['key' => 'membership_id']],
            'fields' => [
                ['name' => 'preference_id', 'type' => 'number', 'primary' => true],
                ['name' => 'membership_id', 'type' => 'string'],
                ['name' => 'note', 'type' => 'string'],
            ],
        ], 'test');

        $this->owner = $this->makePaneUser(2);
        $this->other = $this->makePaneUser(2);
        $this->admin = $this->makePaneUser(1);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->clearAppMaps();
        User::query()->whereIn('user_id', [$this->owner->user_id, $this->other->user_id, $this->admin->user_id])->delete();

        parent::tearDown();
    }

    public function test_a_user_creates_reads_updates_and_deletes_their_own_rows(): void
    {
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/preferences', ['membership_id' => $this->owner->uuid, 'note' => 'a'])
            ->assertStatus(Response::HTTP_CREATED);
        $id = (int) DB::table('own_preferences')->value('preference_id');

        $this->getJson("/crud/preferences/$id")->assertOk()->assertJsonPath('data.0.note', 'a');
        $this->withCsrfToken()->putJson("/crud/preferences/$id", ['membership_id' => $this->owner->uuid, 'note' => 'b'])->assertOk();
        $this->assertSame('b', DB::table('own_preferences')->where('preference_id', $id)->value('note'));
        $this->withCsrfToken()->deleteJson("/crud/preferences/$id")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame(0, DB::table('own_preferences')->count());
    }

    public function test_a_user_sees_and_changes_only_their_own_rows(): void
    {
        $mine = $this->preferenceFor($this->owner, 'mine');
        $theirs = $this->preferenceFor($this->other, 'theirs');
        $this->actingAs($this->owner);

        $this->getJson('/crud/preferences')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.note', 'mine');
        $this->getJson("/crud/preferences/$theirs")->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/preferences/$theirs", ['membership_id' => $this->owner->uuid, 'note' => 'taken'])->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/preferences/$theirs")->assertForbidden();

        $this->assertSame('theirs', DB::table('own_preferences')->where('preference_id', $theirs)->value('note'));
        $this->assertSame($this->other->uuid, DB::table('own_preferences')->where('preference_id', $theirs)->value('membership_id'));
        $this->assertSame('mine', DB::table('own_preferences')->where('preference_id', $mine)->value('note'));
    }

    public function test_a_user_cannot_write_a_row_for_someone_else(): void
    {
        $mine = $this->preferenceFor($this->owner, 'mine');
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/preferences', ['membership_id' => $this->other->uuid, 'note' => 'x'])->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/preferences/$mine", ['membership_id' => $this->other->uuid, 'note' => 'x'])->assertForbidden();

        $this->assertSame(1, DB::table('own_preferences')->count());
        $this->assertSame($this->owner->uuid, DB::table('own_preferences')->where('preference_id', $mine)->value('membership_id'));
    }

    public function test_an_administrator_gets_no_access_to_another_users_rows(): void
    {
        $theirs = $this->preferenceFor($this->other, 'theirs');
        $this->preferenceFor($this->admin, 'admin own');
        $this->actingAs($this->admin);

        $this->getJson('/crud/preferences')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.note', 'admin own');
        $this->getJson("/crud/preferences/$theirs")->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/preferences/$theirs", ['membership_id' => $this->admin->uuid, 'note' => 'taken'])->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/preferences/$theirs")->assertForbidden();
        $this->withCsrfToken()->postJson('/crud/preferences', ['membership_id' => $this->other->uuid, 'note' => 'x'])->assertForbidden();

        $this->assertSame('theirs', DB::table('own_preferences')->where('preference_id', $theirs)->value('note'));
    }

    public function test_a_filter_cannot_reach_another_users_rows(): void
    {
        $this->preferenceFor($this->other, 'theirs');
        $this->actingAs($this->owner);

        $this->getJson('/crud/preferences?filter[membership_id]='.$this->other->uuid)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_owner_rule_naming_an_unknown_key_field_is_rejected_when_saved(): void
    {
        $this->expectException(InvalidMapException::class);

        app(MapStore::class)->saveTable('install', [
            'name' => 'broken', 'sql_name' => 'own_broken',
            'access' => ['owner' => ['key' => 'nope']],
            'fields' => [['name' => 'broken_id', 'type' => 'number', 'primary' => true]],
        ], 'test');
    }

    /**
     * @param  array<string, mixed>  $access
     */
    #[DataProvider('badOwnerRules')]
    public function test_a_malformed_owner_rule_is_rejected_when_saved(array $access): void
    {
        $this->expectException(InvalidMapException::class);

        app(MapStore::class)->saveTable('install', [
            'name' => 'broken', 'sql_name' => 'own_broken', 'access' => $access,
            'fields' => [['name' => 'broken_id', 'type' => 'number', 'primary' => true]],
        ], 'test');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badOwnerRules(): array
    {
        return [
            'extra part' => [['owner' => ['key' => 'broken_id', 'extra' => 'x']]],
            'not an object' => [['owner' => 'broken_id']],
            'next to a members rule' => [['owner' => ['key' => 'broken_id'], 'members' => ['table' => 'a', 'key' => 'b', 'members_key' => 'c', 'members_user' => 'd']]],
        ];
    }

    private function preferenceFor(User $user, string $note): int
    {
        return DB::table('own_preferences')->insertGetId(['membership_id' => $user->uuid, 'note' => $note]);
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('own_preferences');
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
