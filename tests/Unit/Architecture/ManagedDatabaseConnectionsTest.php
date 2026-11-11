<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Managed-database connections must go through ManagedDatabaseConnector, which
 * enforces the egress policy. This fails when app code opens a PDO connection
 * or builds a database connection at runtime anywhere else.
 */
class ManagedDatabaseConnectionsTest extends TestCase
{
    private const array ALLOWED = [
        'app/Support/ManagedDatabase/PdoFactory.php',
    ];

    private const array FORBIDDEN = [
        '/\bnew\s+\\\\?PDO\s*\(/' => 'new PDO()',
        // Written as DB\:\: so the suite-contract test doesn't read this file as using the facade.
        '/\bDB\:\:(connectUsing|build)\s*\(/' => 'the DB facade connectUsing()/build()',
        "/config\\(\\s*\\[\\s*['\"]database\\.connections\\./" => "config(['database.connections.…'])",
        "/Config::set\\(\\s*['\"]database\\.connections\\./" => "Config::set('database.connections.…')",
    ];

    public function test_only_the_connector_opens_managed_database_connections(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN as $pattern => $label) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = "$relative uses $label; connect through App\\Services\\ManagedDatabaseConnector instead";
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
