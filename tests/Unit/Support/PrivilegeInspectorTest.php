<?php

namespace Tests\Unit\Support;

use App\Support\ManagedDatabase\PrivilegeInspector;
use PHPUnit\Framework\TestCase;

class PrivilegeInspectorTest extends TestCase
{
    private function evaluate(array $lines, array $visible = ['information_schema', 'shop']): array
    {
        return (new PrivilegeInspector)->evaluate($lines, 'shop', $visible);
    }

    public function test_exact_crud_on_its_own_database_is_valid(): void
    {
        $report = $this->evaluate([
            'GRANT USAGE ON *.* TO `app`@`%` IDENTIFIED BY PASSWORD \'*ABCDEF\'',
            'GRANT SELECT, INSERT, UPDATE, DELETE, SHOW VIEW ON `shop`.* TO `app`@`%`',
        ]);

        $this->assertSame(['valid' => true, 'missing' => [], 'excessive' => []], $report);
    }

    public function test_missing_privileges_are_reported(): void
    {
        $report = $this->evaluate(['GRANT SELECT ON `shop`.* TO `app`@`%`']);

        $this->assertFalse($report['valid']);
        $this->assertSame(['INSERT', 'UPDATE', 'DELETE'], $report['missing']);
    }

    public function test_ddl_admin_file_grant_and_other_database_privileges_are_excessive(): void
    {
        $report = $this->evaluate([
            'GRANT FILE, PROCESS ON *.* TO `app`@`%`',
            'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER ON `shop`.* TO `app`@`%` WITH GRANT OPTION',
            'GRANT SELECT ON `other`.* TO `app`@`%`',
        ]);

        $this->assertFalse($report['valid']);
        foreach (['FILE on *.*', 'PROCESS on *.*', 'CREATE on `shop`', 'DROP on `shop`', 'ALTER on `shop`', 'GRANT OPTION on `shop`', 'SELECT on `other`'] as $expected) {
            $this->assertContains($expected, $report['excessive']);
        }
    }

    public function test_all_privileges_and_roles_are_rejected(): void
    {
        $this->assertContains('ALL PRIVILEGES on `shop`', $this->evaluate(['GRANT ALL PRIVILEGES ON `shop`.* TO `app`@`%`'])['excessive']);
        $this->assertContains('role or unrecognised grant', $this->evaluate(['GRANT `admin_role` TO `app`@`%`'])['excessive']);
        $this->assertContains('PROXY', $this->evaluate(["GRANT PROXY ON ''@'' TO `app`@`%`"])['excessive']);
    }

    public function test_underscore_wildcards_count_only_when_they_match_nothing_else(): void
    {
        $grant = 'GRANT SELECT, INSERT, UPDATE, DELETE ON `u1_shop`.* TO `app`@`%`';

        $this->assertTrue((new PrivilegeInspector)->evaluate([$grant], 'u1_shop', ['u1_shop'])['valid']);
        $this->assertFalse((new PrivilegeInspector)->evaluate([$grant], 'u1_shop', ['u1_shop', 'u1xshop'])['valid']);
        $this->assertTrue((new PrivilegeInspector)->evaluate(['GRANT SELECT, INSERT, UPDATE, DELETE ON `u1\\_shop`.* TO `app`@`%`'], 'u1_shop', ['u1_shop', 'u1xshop'])['valid']);
        $this->assertFalse((new PrivilegeInspector)->evaluate(['GRANT SELECT, INSERT, UPDATE, DELETE ON `u1%`.* TO `app`@`%`'], 'u1_shop', ['u1_shop'])['valid']);
    }

    public function test_table_and_column_grants_on_its_own_database_count(): void
    {
        $report = $this->evaluate([
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO `app`@`%`',
            'GRANT SELECT (`name`), UPDATE (`name`) ON `shop`.`lists` TO `app`@`%`',
        ]);

        $this->assertTrue($report['valid']);
    }

    public function test_table_grants_do_not_satisfy_the_database_wide_requirement(): void
    {
        $report = $this->evaluate(['GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.`only_one_table` TO `app`@`%`']);

        $this->assertFalse($report['valid']);
        $this->assertSame(['SELECT', 'INSERT', 'UPDATE', 'DELETE'], $report['missing']);
    }

    public function test_table_grants_with_other_privileges_are_excessive(): void
    {
        $report = $this->evaluate([
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO `app`@`%`',
            'GRANT ALTER ON `shop`.`lists` TO `app`@`%`',
        ]);

        $this->assertFalse($report['valid']);
        $this->assertSame(['ALTER on `shop`'], $report['excessive']);
    }

    public function test_reports_never_contain_password_hashes(): void
    {
        $report = $this->evaluate(['GRANT SUPER ON *.* TO `app`@`%` IDENTIFIED BY PASSWORD \'*SECRETHASH\'']);

        $this->assertStringNotContainsString('SECRETHASH', json_encode($report));
    }
}
