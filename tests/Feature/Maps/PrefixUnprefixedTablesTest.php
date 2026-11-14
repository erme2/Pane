<?php

namespace Tests\Feature\Maps;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PrefixUnprefixedTablesTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // A separate in-memory database shaped like an install created with an
        // empty DB_TABLE_PREFIX, so the shared test database is never renamed.
        config(['database.connections.unprefixed' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->db = DB::connection('unprefixed');
        $schema = $this->db->getSchemaBuilder();
        foreach (['organizations', 'messages', 'migrations'] as $table) {
            $schema->create($table, fn (Blueprint $t) => $t->id());
        }
        $schema->create('map_tables', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('sql_name')->nullable();
        });
        $this->db->table('map_tables')->insert([
            ['name' => 'tables', 'sql_name' => 'map_tables'],
            ['name' => 'users', 'sql_name' => 'users'],
        ]);
        $this->db->table('organizations')->insert(['id' => 7]);
    }

    protected function tearDown(): void
    {
        DB::purge('unprefixed');

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_13_090000_prefix_unprefixed_pane_tables.php');
    }

    public function test_unprefixed_pane_tables_move_under_the_prefix_with_their_data(): void
    {
        $moved = $this->migration()->prefixTables($this->db, 'pane_');

        $this->assertSame(
            ['organizations' => 'pane_organizations', 'messages' => 'pane_messages', 'map_tables' => 'pane_map_tables'],
            $moved,
        );
        $schema = $this->db->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('organizations'));
        $this->assertSame(7, (int) $this->db->table('pane_organizations')->value('id'));
        $this->assertTrue($schema->hasTable('migrations'), "Laravel's migrations table keeps its name");
        $this->assertFalse($schema->hasTable('pane_migrations'));
    }

    public function test_legacy_map_rows_follow_the_moved_tables_only(): void
    {
        $this->migration()->prefixTables($this->db, 'pane_');

        $this->assertSame(
            ['tables' => 'pane_map_tables', 'users' => 'users'],
            $this->db->table('pane_map_tables')->pluck('sql_name', 'name')->all(),
        );
    }

    public function test_running_it_again_or_on_a_prefixed_install_moves_nothing(): void
    {
        $migration = $this->migration();
        $migration->prefixTables($this->db, 'pane_');

        $this->assertSame([], $migration->prefixTables($this->db, 'pane_'));
    }

    public function test_an_empty_prefix_moves_nothing(): void
    {
        $this->assertSame([], $this->migration()->prefixTables($this->db, ''));
        $this->assertTrue($this->db->getSchemaBuilder()->hasTable('organizations'));
    }

    public function test_it_refuses_when_both_names_exist(): void
    {
        $this->db->getSchemaBuilder()->create('pane_messages', fn (Blueprint $t) => $t->id());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"messages" and "pane_messages"');

        try {
            $this->migration()->prefixTables($this->db, 'pane_');
        } finally {
            $this->assertTrue($this->db->getSchemaBuilder()->hasTable('organizations'), 'nothing was moved');
        }
    }
}
