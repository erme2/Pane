<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves Pane's tables under DB_TABLE_PREFIX on installs created without one
 * (Pane#164): each table `x` becomes `{prefix}x`, and the legacy map rows that
 * name a moved table follow. Dated before the maps tables so those are created
 * under the prefix. A fresh install has nothing to move, so this does nothing.
 * Laravel's own tables (`migrations`, ...) never take the prefix. Back up the
 * database before deploying it to an install with unprefixed tables.
 *
 * Self-contained on purpose: the table list is the set that existed when this
 * was written, so later code changes can't alter what it renames.
 */
return new class extends Migration
{
    public const array TABLES = [
        'organizations', 'organization_memberships', 'organization_invitations', 'applications',
        'managed_credential_secrets', 'managed_connections', 'audit_events', 'messages',
        'admin_invitations', 'installation_locks', 'setting_defaults', 'setting_overrides',
        'maps_sources', 'maps_tables', 'maps_fields', 'maps_versions',
        'map_tables', 'map_fields', 'map_field_types', 'map_field_validations', 'map_validation_types',
        'map_users', 'map_user_types',
    ];

    public function up(): void
    {
        $this->prefixTables(DB::connection(), (string) config('database.table_prefix', 'pane_'));
    }

    public function down(): void
    {
        // Moving tables back out of the prefix would break the install: the
        // code always looks for them under DB_TABLE_PREFIX.
    }

    /**
     * @return array<string, string> the tables moved, old name => new name
     */
    public function prefixTables(Connection $db, string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $schema = $db->getSchemaBuilder();
        $moves = [];
        foreach (self::TABLES as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            if ($schema->hasTable($prefix.$table)) {
                throw new RuntimeException("Both \"$table\" and \"$prefix$table\" exist; move the data by hand, then deploy again.");
            }
            $moves[$table] = $prefix.$table;
        }

        if ($moves === []) {
            return [];
        }

        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            // One statement, so the move is all or nothing.
            $db->statement('RENAME TABLE '.implode(', ', array_map(
                fn (string $old, string $new): string => "`$old` TO `$new`",
                array_keys($moves),
                $moves,
            )));
        } else {
            foreach ($moves as $old => $new) {
                $schema->rename($old, $new);
            }
        }

        $legacyTables = $prefix.'map_tables';
        if ($schema->hasTable($legacyTables)) {
            foreach ($moves as $old => $new) {
                $db->table($legacyTables)->where('sql_name', $old)->update(['sql_name' => $new]);
            }
        }

        return $moves;
    }
};
