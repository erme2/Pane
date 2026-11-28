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
 * Reports and blocks (Pane#245): the reports rule, the owner rule with `member_of`, the
 * `hide_blocked` read filter, and the text-free rejected-text error shape.
 */
class ReportsAndBlocksTest extends TestCase
{
    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('rb_members', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('list_id');
            $table->string('membership_id');
        });
        Schema::create('rb_items', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('list_id');
            $table->string('text');
            $table->string('added_by')->nullable();
        });
        Schema::create('rb_reports', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('list_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('message_id')->nullable();
            $table->string('reporter')->nullable();
            $table->string('reason');
            $table->string('status')->nullable();
        });
        Schema::create('rb_blocks', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('list_id');
            $table->string('blocker');
            $table->string('blocked');
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $store->saveTable('install', ['name' => 'members', 'sql_name' => 'rb_members', 'fields' => [
            ['name' => 'id', 'type' => 'number', 'primary' => true],
            ['name' => 'list_id', 'type' => 'number'],
            ['name' => 'membership_id', 'type' => 'string'],
        ]], 'test');
        $store->saveTable('install', ['name' => 'items', 'sql_name' => 'rb_items', 'fields' => [
            ['name' => 'id', 'type' => 'number', 'primary' => true],
            ['name' => 'list_id', 'type' => 'number'],
            ['name' => 'text', 'type' => 'string', 'validations' => [['rule' => 'screened']]],
            ['name' => 'added_by', 'type' => 'string', 'nullable' => true],
        ]], 'test');
        $store->saveTable('install', ['name' => 'reports', 'sql_name' => 'rb_reports', 'fields' => [
            ['name' => 'id', 'type' => 'number', 'primary' => true],
            ['name' => 'list_id', 'type' => 'number'],
            ['name' => 'item_id', 'type' => 'number', 'nullable' => true],
            ['name' => 'message_id', 'type' => 'number', 'nullable' => true],
            ['name' => 'reporter', 'type' => 'string'],
            ['name' => 'reason', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => 40]]],
            ['name' => 'status', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => 10]]],
        ]], 'test');
        $store->saveTable('install', ['name' => 'blocks', 'sql_name' => 'rb_blocks', 'fields' => [
            ['name' => 'id', 'type' => 'number', 'primary' => true],
            ['name' => 'list_id', 'type' => 'number'],
            ['name' => 'blocker', 'type' => 'string'],
            ['name' => 'blocked', 'type' => 'string'],
        ]], 'test');

        $members = ['table' => 'members', 'members_key' => 'list_id', 'members_user' => 'membership_id'];
        config(['pane_access.tables' => [
            'members' => ['members' => $members + ['key' => 'list_id']],
            'items' => ['members' => $members + [
                'key' => 'list_id',
                'hide_blocked' => ['author' => 'added_by', 'table' => 'blocks', 'list' => 'list_id', 'blocker' => 'blocker', 'blocked' => 'blocked'],
            ]],
            'reports' => ['reports' => [
                'reporter' => 'reporter', 'list' => 'list_id', 'status' => 'status',
                'reason' => 'reason', 'reasons' => ['Spam', 'Other'],
                'targets' => ['item_id', 'message_id'], 'members' => $members,
            ]],
            'blocks' => ['owner' => ['key' => 'blocker', 'member_of' => $members + ['list' => 'list_id', 'other' => 'blocked']]],
        ]]);

        $this->alice = $this->makePaneUser(2);
        $this->bob = $this->makePaneUser(2);
        $this->carol = $this->makePaneUser(2);
        DB::table('rb_members')->insert([
            ['list_id' => 1, 'membership_id' => $this->alice->uuid],
            ['list_id' => 1, 'membership_id' => $this->bob->uuid],
            ['list_id' => 2, 'membership_id' => $this->carol->uuid],
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->clearAppMaps();
        User::query()->whereIn('user_id', [$this->alice->user_id, $this->bob->user_id, $this->carol->user_id])->delete();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function report(array $override = []): array
    {
        return array_merge(['list_id' => 1, 'item_id' => 10, 'reason' => 'Spam'], $override);
    }

    public function test_a_member_files_a_report_and_pane_sets_the_reporter_and_status(): void
    {
        $this->actingAs($this->alice);

        $this->withCsrfToken()->postJson('/crud/reports', $this->report(['reporter' => $this->bob->uuid, 'status' => 'closed']))
            ->assertCreated();

        $row = DB::table('rb_reports')->first();
        $this->assertSame($this->alice->uuid, $row->reporter);
        $this->assertSame('open', $row->status);
        $this->assertSame('Spam', $row->reason);
    }

    public function test_a_report_needs_exactly_one_target(): void
    {
        $this->actingAs($this->alice);

        $this->withCsrfToken()->postJson('/crud/reports', ['list_id' => 1, 'reason' => 'Spam'])->assertUnprocessable();
        $this->withCsrfToken()->postJson('/crud/reports', $this->report(['message_id' => 5]))->assertUnprocessable();
        $this->assertSame(0, DB::table('rb_reports')->count());
    }

    public function test_the_reason_must_be_one_of_the_allowed_ones(): void
    {
        $this->actingAs($this->alice);

        $this->withCsrfToken()->postJson('/crud/reports', $this->report(['reason' => 'Boring']))->assertUnprocessable();
        $this->assertSame(0, DB::table('rb_reports')->count());
    }

    public function test_the_reporter_must_belong_to_the_list(): void
    {
        $this->actingAs($this->alice);

        $this->withCsrfToken()->postJson('/crud/reports', $this->report(['list_id' => 2]))->assertForbidden();
        $this->assertSame(0, DB::table('rb_reports')->count());
    }

    public function test_a_reporter_cannot_read_update_or_delete_reports(): void
    {
        DB::table('rb_reports')->insert(['list_id' => 1, 'item_id' => 10, 'reporter' => $this->alice->uuid, 'reason' => 'Spam', 'status' => 'open']);
        $this->actingAs($this->alice);

        $this->getJson('/crud/reports')->assertForbidden();
        $this->getJson('/crud/reports/1')->assertForbidden();
        $this->withCsrfToken()->putJson('/crud/reports/1', ['status' => 'closed'])->assertForbidden();
        $this->withCsrfToken()->deleteJson('/crud/reports/1')->assertForbidden();
        $this->assertSame('open', DB::table('rb_reports')->value('status'));
        $this->assertSame(1, DB::table('rb_reports')->count());
    }

    public function test_an_administrator_reads_reports_and_closes_them_but_changes_nothing_else(): void
    {
        DB::table('rb_reports')->insert(['list_id' => 1, 'item_id' => 10, 'reporter' => $this->alice->uuid, 'reason' => 'Spam', 'status' => 'open']);
        $this->authenticateAsAdministrator();

        $this->getJson('/crud/reports')->assertOk()->assertJsonCount(1, 'data');

        $full = ['list_id' => 1, 'item_id' => 10, 'reporter' => $this->alice->uuid, 'reason' => 'Spam', 'status' => 'closed'];
        $this->withCsrfToken()->putJson('/crud/reports/1', $full)->assertOk();
        $this->assertSame('closed', DB::table('rb_reports')->value('status'));

        // every other value sent is ignored: the stored one stays
        $this->withCsrfToken()->putJson('/crud/reports/1', ['reason' => 'Other', 'reporter' => 'x', 'list_id' => 2, 'item_id' => 11, 'status' => 'open'])->assertOk();
        $row = DB::table('rb_reports')->first();
        $this->assertSame(['Spam', $this->alice->uuid, 1, 10, 'open'], [$row->reason, $row->reporter, $row->list_id, $row->item_id, $row->status]);
        $this->withCsrfToken()->putJson('/crud/reports/1', array_merge($full, ['status' => 'bogus']))->assertUnprocessable();
        $this->withCsrfToken()->deleteJson('/crud/reports/1')->assertForbidden();
        $this->assertSame('Spam', DB::table('rb_reports')->value('reason'));
        $this->assertSame(1, DB::table('rb_reports')->count());
    }

    public function test_a_member_blocks_another_member_and_can_undo_it(): void
    {
        $this->actingAs($this->alice);

        $this->withCsrfToken()->postJson('/crud/blocks', ['list_id' => 1, 'blocker' => $this->alice->uuid, 'blocked' => $this->bob->uuid])
            ->assertCreated();
        $this->getJson('/crud/blocks')->assertOk()->assertJsonCount(1, 'data');

        $this->withCsrfToken()->deleteJson('/crud/blocks/1')->assertNoContent();
        $this->assertSame(0, DB::table('rb_blocks')->count());
    }

    public function test_a_block_is_private_to_its_blocker(): void
    {
        DB::table('rb_blocks')->insert(['list_id' => 1, 'blocker' => $this->alice->uuid, 'blocked' => $this->bob->uuid]);
        $this->actingAs($this->bob);

        $this->getJson('/crud/blocks')->assertOk()->assertJsonCount(0, 'data');
        $this->withCsrfToken()->deleteJson('/crud/blocks/1')->assertForbidden();
        $this->assertSame(1, DB::table('rb_blocks')->count());
    }

    public function test_a_block_must_be_yours_for_a_list_you_belong_to_and_name_someone_else(): void
    {
        $this->actingAs($this->alice);

        $as = fn (array $row) => $this->withCsrfToken()->postJson('/crud/blocks', $row);
        $as(['list_id' => 2, 'blocker' => $this->alice->uuid, 'blocked' => $this->carol->uuid])->assertForbidden();
        $as(['list_id' => 1, 'blocker' => $this->alice->uuid, 'blocked' => $this->alice->uuid])->assertUnprocessable();
        $as(['list_id' => 1])->assertStatus(400);
        $this->assertSame(0, DB::table('rb_blocks')->count());
    }

    public function test_the_blocker_is_set_from_the_session_whatever_the_client_sent(): void
    {
        $this->actingAs($this->alice);

        $this->withCsrfToken()->postJson('/crud/blocks', ['list_id' => 1, 'blocked' => $this->bob->uuid])->assertCreated();
        $this->withCsrfToken()->postJson('/crud/blocks', ['list_id' => 1, 'blocker' => $this->bob->uuid, 'blocked' => $this->carol->uuid])->assertCreated();

        $this->assertSame(2, DB::table('rb_blocks')->where('blocker', $this->alice->uuid)->count());
        $this->assertSame(0, DB::table('rb_blocks')->where('blocker', $this->bob->uuid)->count());
    }

    public function test_a_block_cannot_be_rewritten_into_one_that_breaks_the_rules(): void
    {
        DB::table('rb_blocks')->insert(['list_id' => 1, 'blocker' => $this->alice->uuid, 'blocked' => $this->bob->uuid]);
        $this->actingAs($this->alice);

        $put = fn (array $row) => $this->withCsrfToken()->putJson('/crud/blocks/1', $row);
        $put(['list_id' => 2, 'blocker' => $this->alice->uuid, 'blocked' => $this->alice->uuid])->assertForbidden();
        $put(['list_id' => 2, 'blocker' => $this->alice->uuid, 'blocked' => $this->bob->uuid])->assertForbidden();
        $put(['list_id' => 1, 'blocker' => $this->alice->uuid, 'blocked' => $this->alice->uuid])->assertUnprocessable();

        $row = DB::table('rb_blocks')->first();
        $this->assertSame([1, $this->alice->uuid, $this->bob->uuid], [(int) $row->list_id, $row->blocker, $row->blocked]);
    }

    public function test_a_block_hides_the_blocked_members_rows_for_the_blocker_only(): void
    {
        DB::table('rb_items')->insert([
            ['list_id' => 1, 'text' => 'mine', 'added_by' => $this->alice->uuid],
            ['list_id' => 1, 'text' => 'bobs', 'added_by' => $this->bob->uuid],
            ['list_id' => 1, 'text' => 'unknown', 'added_by' => null],
        ]);
        DB::table('rb_blocks')->insert(['list_id' => 1, 'blocker' => $this->alice->uuid, 'blocked' => $this->bob->uuid]);

        $this->actingAs($this->alice);
        $this->getJson('/crud/items')->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($this->bob);
        $this->getJson('/crud/items')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_unblocking_shows_the_rows_again(): void
    {
        DB::table('rb_items')->insert(['list_id' => 1, 'text' => 'bobs', 'added_by' => $this->bob->uuid]);
        DB::table('rb_blocks')->insert(['list_id' => 1, 'blocker' => $this->alice->uuid, 'blocked' => $this->bob->uuid]);
        $this->actingAs($this->alice);

        $this->getJson('/crud/items')->assertOk()->assertJsonCount(0, 'data');
        $this->withCsrfToken()->deleteJson('/crud/blocks/1')->assertNoContent();
        $this->getJson('/crud/items')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_rejected_text_answers_422_with_a_code_and_the_field_without_the_text(): void
    {
        $this->actingAs($this->alice);

        $response = $this->withCsrfToken()->postJson('/crud/items', ['list_id' => 1, 'text' => 'you absolute f u c k']);

        $response->assertUnprocessable()
            ->assertJsonPath('data.code', 'text_rejected')
            ->assertJsonPath('data.field', 'text')
            ->assertJsonPath('data.message', 'This text is not allowed.');
        $this->assertStringNotContainsString('fuck', strtolower($response->getContent()));
    }

    private function dropTables(): void
    {
        foreach (['rb_members', 'rb_items', 'rb_reports', 'rb_blocks'] as $table) {
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
