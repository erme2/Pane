<?php

namespace Tests\Feature\PaneAdmin;

use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\PersonalDataCleanupException;
use App\Models\Maps\MapField;
use App\Models\Maps\MapSource;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\MapStore;
use App\Services\PersonalDataCleaner;
use App\Support\Maps\MapRules;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The generic personal-data cleanup (sweegly#49), tested on a fixture map: lists the
 * person owns, lists they only belong to, price reports and preferences.
 */
class PersonalDataCleanupTest extends TestCase
{
    private const string ME = '11111111-1111-4111-8111-111111111111';

    private const string OTHER = '22222222-2222-4222-8222-222222222222';

    private const string NONE = '00000000-0000-0000-0000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropFixtures();
        $this->createFixtureTables();
        $this->saveFixtureMaps();
        config()->set('services.posthog.private_key', null);
        config()->set('services.posthog.host', null);
    }

    protected function tearDown(): void
    {
        $this->dropFixtures();

        parent::tearDown();
    }

    public function test_it_cleans_every_table_by_its_rule_and_keeps_other_peoples_rows(): void
    {
        $this->seedRows();

        $result = $this->cleaner()->clean($this->user());

        $this->assertSame('skipped', $result['posthog']);

        // list_members: the person's rows are deleted.
        $this->assertSame([self::OTHER], DB::table('fixt_list_members')->pluck('membership_id')->unique()->values()->all());
        // lists: the one they created is soft deleted, blanked and detached; the shared one stays.
        $owned = DB::table('fixt_lists')->where('id', 1)->first();
        $this->assertNotNull($owned->deleted_at);
        $this->assertSame('', $owned->name);
        $this->assertSame(self::NONE, $owned->created_by_membership_id);
        $kept = DB::table('fixt_lists')->where('id', 2)->first();
        $this->assertNull($kept->deleted_at);
        $this->assertSame('Their list', $kept->name);
        $this->assertSame(self::OTHER, $kept->created_by_membership_id);
        // list_items: the soft deleted list's items go with it, the other list's items stay.
        $this->assertNotNull(DB::table('fixt_list_items')->where('id', 1)->value('deleted_at'));
        $this->assertNull(DB::table('fixt_list_items')->where('id', 2)->value('deleted_at'));
        // price_reports: kept, anonymous, photo gone, time cut to the day.
        $report = DB::table('fixt_price_reports')->where('id', 1)->first();
        $this->assertSame(self::NONE, $report->reporter_membership_id);
        $this->assertNull($report->photo_path);
        $this->assertSame('2026-11-20 00:00:00', $report->reported_at);
        $this->assertSame(249, (int) ($report->price * 100));
        $this->assertSame(self::OTHER, DB::table('fixt_price_reports')->where('id', 2)->value('reporter_membership_id'));
        $this->assertSame('2026-11-21 14:30:00', DB::table('fixt_price_reports')->where('id', 2)->value('reported_at'));
        // preferences: deleted.
        $this->assertSame(1, DB::table('fixt_preferences')->count());
        // Nothing left that points at the person.
        foreach (['fixt_lists' => 'created_by_membership_id', 'fixt_list_members' => 'membership_id', 'fixt_price_reports' => 'reporter_membership_id', 'fixt_preferences' => 'membership_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, self::ME)->count(), $table);
        }
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seedRows();
        $this->cleaner()->clean($this->user());
        $snapshot = $this->snapshot();

        $this->cleaner()->clean($this->user());

        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_a_failure_in_the_middle_rolls_back_and_a_retry_finishes(): void
    {
        $this->seedRows();
        $before = $this->snapshot();
        // price_reports is cleaned after lists and list_members: break its column name.
        MapField::query()
            ->whereIn('map_table_id', MapTable::query()->where('name', 'price_reports')->select('map_table_id'))
            ->where('name', 'photo_path')
            ->update(['sql_name' => 'missing_column']);

        try {
            $this->cleaner()->clean($this->user());
            $this->fail('The cleanup should have failed.');
        } catch (PersonalDataCleanupException $exception) {
            $this->assertStringNotContainsString(self::ME, $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());

        MapField::query()
            ->whereIn('map_table_id', MapTable::query()->where('name', 'price_reports')->select('map_table_id'))
            ->where('name', 'photo_path')
            ->update(['sql_name' => null]);
        $this->cleaner()->clean($this->user());

        $this->assertSame(0, DB::table('fixt_price_reports')->where('reporter_membership_id', self::ME)->count());
        $this->assertSame(0, DB::table('fixt_list_members')->where('membership_id', self::ME)->count());
    }

    public function test_it_deletes_the_posthog_person_when_configured(): void
    {
        config()->set('services.posthog.private_key', 'phx_secret');
        config()->set('services.posthog.host', 'https://eu.posthog.test/');
        Http::fake(['eu.posthog.test/*' => Http::response(null, 200)]);

        $result = $this->cleaner()->clean($this->user());

        $this->assertSame('deleted', $result['posthog']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.posthog.test/api/projects/@current/persons/bulk_delete/'
            && $request->hasHeader('Authorization', 'Bearer phx_secret')
            && $request['distinct_ids'] === [self::ME]
            && $request['delete_events'] === true
            && $request['delete_recordings'] === true);
    }

    public function test_a_posthog_failure_is_reported_without_the_key_and_can_be_retried(): void
    {
        config()->set('services.posthog.private_key', 'phx_secret');
        config()->set('services.posthog.host', 'https://eu.posthog.test');
        Http::fake(['eu.posthog.test/*' => Http::sequence()
            ->push('bad phx_secret', 500)
            ->push(null, 200)]);

        try {
            $this->cleaner()->clean($this->user());
            $this->fail('The cleanup should have failed.');
        } catch (PersonalDataCleanupException $exception) {
            $this->assertStringNotContainsString('phx_secret', $exception->getMessage());
        }

        $this->assertSame('deleted', $this->cleaner()->clean($this->user())['posthog']);
    }

    public function test_the_map_rule_is_checked_when_a_map_is_saved(): void
    {
        $fields = [
            ['name' => 'id', 'type' => 'number', 'primary' => true],
            ['name' => 'owner_id', 'type' => 'string'],
            ['name' => 'note', 'type' => 'string'],
            ['name' => 'count', 'type' => 'number'],
            ['name' => 'seen_at', 'type' => 'date', 'nullable' => true],
        ];
        $bad = [
            'not an object' => 'delete',
            'unknown key' => ['owner' => 'owner_id', 'action' => 'delete', 'extra' => 1],
            'owner is not a field' => ['owner' => 'nope', 'action' => 'delete'],
            'unknown action' => ['owner' => 'owner_id', 'action' => 'shred'],
            'soft delete needs deleted_at' => ['owner' => 'owner_id', 'action' => 'soft_delete'],
            'deleted_at without soft delete' => ['owner' => 'owner_id', 'action' => 'anonymise', 'deleted_at' => 'seen_at'],
            'cannot clear a number' => ['owner' => 'owner_id', 'action' => 'anonymise', 'clear' => ['count']],
            'cannot clear the owner' => ['owner' => 'owner_id', 'action' => 'anonymise', 'clear' => ['owner_id']],
            'cannot coarsen a string' => ['owner' => 'owner_id', 'action' => 'anonymise', 'coarsen' => ['note']],
            'nothing to clear when deleting' => ['owner' => 'owner_id', 'action' => 'delete', 'clear' => ['note']],
            'bad cascade' => ['owner' => 'owner_id', 'action' => 'soft_delete', 'deleted_at' => 'seen_at', 'cascade' => [['table' => 'x']]],
        ];

        foreach ($bad as $label => $rule) {
            try {
                MapRules::normalizeTable(['name' => 'things', 'fields' => $fields, 'personal_data' => $rule]);
                $this->fail("$label should be refused.");
            } catch (InvalidMapException $exception) {
                $this->assertNotEmpty($exception->errors, $label);
            }
        }

        $good = MapRules::normalizeTable(['name' => 'things', 'fields' => $fields, 'personal_data' => [
            'owner' => 'owner_id', 'action' => 'soft_delete', 'deleted_at' => 'seen_at', 'clear' => ['note'], 'coarsen' => ['seen_at'],
            'cascade' => [['table' => 'children', 'field' => 'thing_id', 'deleted_at' => 'deleted_at']],
        ]]);
        $this->assertSame('soft_delete', $good['personal_data']['action']);
        $this->assertNull(MapRules::normalizeTable(['name' => 'things', 'fields' => $fields])['personal_data']);
    }

    public function test_the_rule_is_part_of_the_map_history(): void
    {
        $diff = MapVersion::query()->where('subject_type', MapVersion::SUBJECT_TABLE)->get();

        $this->assertNotEmpty($diff);
        $this->assertSame('delete', MapTable::query()->where('name', 'list_members')->firstOrFail()->personal_data['action']);
    }

    private function cleaner(): PersonalDataCleaner
    {
        return app(PersonalDataCleaner::class);
    }

    private function user(): User
    {
        $user = new User;
        $user->uuid = self::ME;

        return $user;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $all = [];
        foreach (['fixt_lists', 'fixt_list_members', 'fixt_list_items', 'fixt_price_reports', 'fixt_preferences'] as $table) {
            $all[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $all;
    }

    private function seedRows(): void
    {
        DB::table('fixt_lists')->insert([
            ['id' => 1, 'name' => 'My secret list', 'created_by_membership_id' => self::ME, 'deleted_at' => null],
            ['id' => 2, 'name' => 'Their list', 'created_by_membership_id' => self::OTHER, 'deleted_at' => null],
        ]);
        DB::table('fixt_list_members')->insert([
            ['id' => 1, 'list_id' => 1, 'membership_id' => self::ME],
            ['id' => 2, 'list_id' => 1, 'membership_id' => self::OTHER],
            ['id' => 3, 'list_id' => 2, 'membership_id' => self::OTHER],
            ['id' => 4, 'list_id' => 2, 'membership_id' => self::ME],
        ]);
        DB::table('fixt_list_items')->insert([
            ['id' => 1, 'list_id' => 1, 'text' => 'my medicine', 'deleted_at' => null],
            ['id' => 2, 'list_id' => 2, 'text' => 'milk', 'deleted_at' => null],
        ]);
        DB::table('fixt_price_reports')->insert([
            ['id' => 1, 'reporter_membership_id' => self::ME, 'price' => 2.49, 'photo_path' => 'photos/a.jpg', 'reported_at' => '2026-11-20 09:15:42'],
            ['id' => 2, 'reporter_membership_id' => self::OTHER, 'price' => 1.99, 'photo_path' => 'photos/b.jpg', 'reported_at' => '2026-11-21 14:30:00'],
        ]);
        DB::table('fixt_preferences')->insert([
            ['id' => 1, 'membership_id' => self::ME, 'item_text' => 'oat milk'],
            ['id' => 2, 'membership_id' => self::OTHER, 'item_text' => 'tea'],
        ]);
    }

    private function createFixtureTables(): void
    {
        Schema::create('fixt_lists', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('created_by_membership_id', 36);
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('fixt_list_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('list_id');
            $table->string('membership_id', 36);
        });
        Schema::create('fixt_list_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('list_id');
            $table->string('text')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('fixt_price_reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('reporter_membership_id', 36);
            $table->decimal('price', 10, 2);
            $table->string('photo_path')->nullable();
            $table->dateTime('reported_at');
        });
        Schema::create('fixt_preferences', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('membership_id', 36);
            $table->string('item_text');
        });
    }

    private function saveFixtureMaps(): void
    {
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install', 'description' => 'fixture'], 'test');

        $id = ['name' => 'id', 'type' => 'number', 'primary' => true];
        $maps = [
            ['name' => 'lists', 'sql_name' => 'fixt_lists', 'fields' => [
                $id,
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'created_by_membership_id', 'type' => 'string'],
                ['name' => 'deleted_at', 'type' => 'date', 'nullable' => true],
            ], 'personal_data' => [
                'owner' => 'created_by_membership_id', 'action' => 'soft_delete', 'deleted_at' => 'deleted_at', 'clear' => ['name'],
                'cascade' => [['table' => 'list_items', 'field' => 'list_id', 'deleted_at' => 'deleted_at']],
            ]],
            ['name' => 'list_members', 'sql_name' => 'fixt_list_members', 'fields' => [
                $id,
                ['name' => 'list_id', 'type' => 'number'],
                ['name' => 'membership_id', 'type' => 'string'],
            ], 'personal_data' => ['owner' => 'membership_id', 'action' => 'delete']],
            ['name' => 'list_items', 'sql_name' => 'fixt_list_items', 'fields' => [
                $id,
                ['name' => 'list_id', 'type' => 'number'],
                ['name' => 'text', 'type' => 'string', 'nullable' => true],
                ['name' => 'deleted_at', 'type' => 'date', 'nullable' => true],
            ]],
            ['name' => 'price_reports', 'sql_name' => 'fixt_price_reports', 'fields' => [
                $id,
                ['name' => 'reporter_membership_id', 'type' => 'string'],
                ['name' => 'price', 'type' => 'number'],
                ['name' => 'photo_path', 'type' => 'string', 'nullable' => true],
                ['name' => 'reported_at', 'type' => 'date'],
            ], 'personal_data' => [
                'owner' => 'reporter_membership_id', 'action' => 'anonymise', 'clear' => ['photo_path'], 'coarsen' => ['reported_at'],
            ]],
            ['name' => 'preferences', 'sql_name' => 'fixt_preferences', 'fields' => [
                $id,
                ['name' => 'membership_id', 'type' => 'string'],
                ['name' => 'item_text', 'type' => 'string'],
            ], 'personal_data' => ['owner' => 'membership_id', 'action' => 'delete']],
        ];
        foreach ($maps as $map) {
            $store->saveTable('install', $map, 'test');
        }
    }

    private function dropFixtures(): void
    {
        foreach (['fixt_lists', 'fixt_list_members', 'fixt_list_items', 'fixt_price_reports', 'fixt_preferences'] as $table) {
            Schema::dropIfExists($table);
        }
        $source = MapSource::query()->where('name', 'install')->first();
        if ($source !== null && MapTable::query()->where('map_source_id', $source->map_source_id)->whereIn('name', ['lists', 'list_members', 'list_items', 'price_reports', 'preferences'])->exists()) {
            $ids = MapTable::query()->where('map_source_id', $source->map_source_id)
                ->whereIn('name', ['lists', 'list_members', 'list_items', 'price_reports', 'preferences'])
                ->pluck('map_table_id');
            MapVersion::query()->where('subject_type', MapVersion::SUBJECT_TABLE)->whereIn('subject_id', $ids)->delete();
            MapField::query()->whereIn('map_table_id', $ids)->delete();
            MapTable::query()->whereIn('map_table_id', $ids)->delete();
        }
    }
}
