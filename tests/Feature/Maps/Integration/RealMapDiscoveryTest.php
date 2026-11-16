<?php

namespace Tests\Feature\Maps\Integration;

use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Services\MapDiscovery;
use App\Services\MapStore;
use App\Support\Maps\Discovery\CatalogReader;
use Illuminate\Support\Facades\DB;
use Tests\Support\RealManagedDatabase;
use Tests\TestCase;

/**
 * Maps discovery (Pane#155) on a real MySQL or MariaDB server (CI runs both):
 * only the current database's app tables are read, never Pane's system tables,
 * the server's system schemas or another database. Skipped unless
 * PANE_IT_DB_HOST is set; see docs/testing.md.
 */
class RealMapDiscoveryTest extends TestCase
{
    private RealManagedDatabase $server;

    protected function setUp(): void
    {
        parent::setUp();

        if (! RealManagedDatabase::configured()) {
            $this->markTestSkipped('Set PANE_IT_DB_HOST (and PANE_IT_DB_ENGINE, _PORT, _ROOT_PASSWORD) to run against a real database.');
        }

        config(['database.table_prefix' => 'pane_', 'database.app_table_prefix' => 'sweegly_']);
        $this->server = new RealManagedDatabase;
        config(['database.connections.discovery_real' => [
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
        $this->removeAppMaps();
    }

    protected function tearDown(): void
    {
        DB::purge('discovery_real');
        if (isset($this->server)) {
            $this->removeAppMaps();
            $this->server->cleanup();
        }

        parent::tearDown();
    }

    public function test_discovery_reads_only_the_install_app_tables(): void
    {
        $db = DB::connection('discovery_real');
        $db->statement('CREATE TABLE sweegly_lists (list_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(120) NOT NULL, position INT NOT NULL, pinned TINYINT(1) NOT NULL, notes TEXT NULL) ENGINE=InnoDB');
        $db->statement('CREATE TABLE sweegly_items (item_id INT PRIMARY KEY, label VARCHAR(80) NULL) ENGINE=InnoDB');
        $db->statement('CREATE VIEW sweegly_list_titles AS SELECT title FROM sweegly_lists');
        $db->statement('CREATE TABLE pane_secrets (id INT PRIMARY KEY) ENGINE=InnoDB');
        $other = $this->server->createDatabase('it_other_'.bin2hex(random_bytes(3)));
        $this->server->root()->exec("CREATE TABLE `$other`.sweegly_elsewhere (id INT PRIMARY KEY) ENGINE=InnoDB");

        app(MapStore::class)->saveSource(['name' => 'install', 'kind' => 'install', 'description' => "The install's own database"], 'test');
        app(MapStore::class)->saveTable('install', [
            'name' => 'lists',
            'sql_name' => 'sweegly_lists',
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'number'],
                ['name' => 'pinned', 'type' => 'boolean'],
                ['name' => 'archived', 'type' => 'boolean'],
            ],
        ], 'test');

        $tables = app(CatalogReader::class)->read('discovery_real');
        $this->assertSame(['sweegly_items', 'sweegly_lists'], array_keys($tables));
        $this->assertSame('boolean', $tables['sweegly_lists']['pinned']['type']);
        $this->assertSame('varchar(120)', $tables['sweegly_lists']['title']['sql_type']);
        $this->assertTrue($tables['sweegly_items']['label']['nullable']);

        $report = app(MapDiscovery::class)->report('discovery_real');
        $this->assertSame(['sweegly_items'], array_column($report['unmapped_tables'], 'sql_table'));
        $this->assertSame(['position', 'notes'], array_column($report['unmapped_fields'], 'sql_field'));
        $this->assertSame(['archived'], array_column($report['missing'], 'field'));
        $this->assertSame(['title'], array_column($report['changed'], 'field'));
        $this->assertSame(['type'], $report['changed'][0]['reasons']);

        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['pane_secrets', 'sweegly_elsewhere', 'sweegly_list_titles', 'information_schema'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $encoded);
        }
    }

    private function removeAppMaps(): void
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
