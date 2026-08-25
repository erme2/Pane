<?php

namespace Tests\Feature\Database;

use PHPUnit\Framework\TestCase;

class MigrationTriggerNameTest extends TestCase
{
    public function test_audit_trigger_names_use_connection_prefixed_physical_table_name(): void
    {
        $root = dirname(__DIR__, 3);
        $migration = file_get_contents($root.'/database/migrations/2026_07_27_090000_create_audit_events_table.php');

        self::assertIsString($migration);
        self::assertStringContainsString('DB::connection()->getTablePrefix().$tableName', $migration);
        self::assertStringContainsString('$physicalTableName = $this->physicalTableName($tableName);', $migration);
        self::assertStringContainsString('$table = $this->quoteIdentifier($physicalTableName);', $migration);
        self::assertStringContainsString('$this->triggerName($physicalTableName, $suffix)', $migration);
        self::assertLessThanOrEqual(64, strlen('pane_pane_audit_events_prevent_update'));
        self::assertLessThanOrEqual(64, strlen('pane_pane_audit_events_prevent_delete'));
    }
}
