<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

class MigrationIndexNameTest extends TestCase
{
    public function test_composite_index_names_fit_mysql_identifier_limit_with_table_prefix(): void
    {
        $root = dirname(__DIR__, 3);
        $migrationPaths = [
            'database/migrations/2026_07_26_170000_create_organization_tenancy_tables.php',
            'database/migrations/2026_07_30_090000_create_organization_invitations_table.php',
            'database/migrations/2026_08_06_090000_create_managed_credential_secrets_table.php',
        ];
        $indexNames = [
            'org_members_org_user_unique',
            'org_invites_org_email_status_index',
            'managed_secrets_org_purpose_status_index',
        ];

        foreach ($migrationPaths as $path) {
            $migration = file_get_contents($root.'/'.$path);
            self::assertIsString($migration);

            foreach ($indexNames as $indexName) {
                if (str_contains($migration, $indexName)) {
                    self::assertLessThanOrEqual(
                        64,
                        strlen('pane_'.$indexName),
                        $indexName.' must fit MySQL identifier limits with the configured table prefix.'
                    );
                }
            }
        }

        self::assertStringContainsString(
            "'org_members_org_user_unique'",
            file_get_contents($root.'/'.$migrationPaths[0])
        );
        self::assertStringContainsString(
            "'org_invites_org_email_status_index'",
            file_get_contents($root.'/'.$migrationPaths[1])
        );
        self::assertStringContainsString(
            "'managed_secrets_org_purpose_status_index'",
            file_get_contents($root.'/'.$migrationPaths[2])
        );
    }
}
