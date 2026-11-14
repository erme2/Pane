<?php

namespace Tests\Feature\Maps\Integration;

use Illuminate\Support\Facades\DB;
use Tests\Support\RealManagedDatabase;
use Tests\TestCase;

/**
 * The Pane#164 table move on a real MySQL or MariaDB server (CI runs both),
 * where it uses one RENAME TABLE statement. Skipped unless PANE_IT_DB_HOST is
 * set; see docs/testing.md.
 */
class RealPrefixUnprefixedTablesTest extends TestCase
{
    private RealManagedDatabase $server;

    protected function setUp(): void
    {
        parent::setUp();

        if (! RealManagedDatabase::configured()) {
            $this->markTestSkipped('Set PANE_IT_DB_HOST (and PANE_IT_DB_ENGINE, _PORT, _ROOT_PASSWORD) to run against a real database.');
        }

        $this->server = new RealManagedDatabase;
        config(['database.connections.unprefixed_real' => [
            'driver' => $this->server->engine,
            'host' => $this->server->host,
            'port' => $this->server->port,
            'database' => $this->server->database,
            'username' => 'root',
            'password' => (string) getenv('PANE_IT_DB_ROOT_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]]);
    }

    protected function tearDown(): void
    {
        DB::purge('unprefixed_real');
        if (isset($this->server)) {
            $this->server->cleanup();
        }

        parent::tearDown();
    }

    public function test_tables_move_in_one_statement_keeping_data_and_foreign_keys(): void
    {
        $db = DB::connection('unprefixed_real');
        $db->statement('CREATE TABLE organizations (organization_id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->statement('CREATE TABLE organization_memberships (membership_id INT PRIMARY KEY, organization_id INT NOT NULL,
            CONSTRAINT memberships_org_fk FOREIGN KEY (organization_id) REFERENCES organizations (organization_id)) ENGINE=InnoDB');
        $db->statement('CREATE TABLE map_tables (table_id INT PRIMARY KEY, name VARCHAR(64), sql_name VARCHAR(255)) ENGINE=InnoDB');
        $db->statement('CREATE TABLE migrations (id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->table('organizations')->insert(['organization_id' => 3]);
        $db->table('organization_memberships')->insert(['membership_id' => 1, 'organization_id' => 3]);
        $db->table('map_tables')->insert(['table_id' => 1, 'name' => 'tables', 'sql_name' => 'map_tables']);

        $migration = require database_path('migrations/2026_11_13_090000_prefix_unprefixed_pane_tables.php');
        $moved = $migration->prefixTables($db, 'pane_');

        $this->assertSame(['organizations', 'organization_memberships', 'map_tables'], array_keys($moved));
        $schema = $db->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('organizations'));
        $this->assertTrue($schema->hasTable('migrations'));
        $this->assertSame(3, (int) $db->table('pane_organization_memberships')->value('organization_id'));
        $this->assertSame('pane_map_tables', $db->table('pane_map_tables')->value('sql_name'));
        $this->assertSame('pane_organizations', $db->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $this->server->database)
            ->where('CONSTRAINT_NAME', 'memberships_org_fk')
            ->value('REFERENCED_TABLE_NAME'));
        $this->assertSame([], $migration->prefixTables($db, 'pane_'));
    }
}
