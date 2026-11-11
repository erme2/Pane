<?php

namespace App\Support\ManagedDatabase;

use PDO;
use Throwable;

/**
 * Checks the privileges of a managed database's account from `SHOW GRANTS`.
 *
 * Pane needs SELECT, INSERT, UPDATE and DELETE on its own database (catalog
 * reads through information_schema come with those). Anything that reaches
 * other databases, changes structure (DDL), or administers the server, the
 * filesystem or other accounts is reported as excessive and rejected.
 * Raw grant lines are never returned: MariaDB can include a password hash.
 */
final class PrivilegeInspector
{
    /** @var list<string> */
    public const array REQUIRED = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /** Harmless extras on Pane's own database. @var list<string> */
    private const array ALLOWED_EXTRA = ['SHOW VIEW', 'USAGE'];

    /**
     * @return array{valid: bool, missing: list<string>, excessive: list<string>}
     */
    public function inspect(PDO $pdo, string $database): array
    {
        try {
            $statement = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
            $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            return ['valid' => false, 'missing' => self::REQUIRED, 'excessive' => ['grants could not be read']];
        }

        $lines = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row[0] ?? null)) {
                $lines[] = $row[0];
            }
        }

        try {
            $statement = $pdo->query('SHOW DATABASES');
            $visible = $statement === false ? [] : array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            $visible = [];
        }

        return $this->evaluate($lines, $database, $visible);
    }

    /**
     * @param  list<string>  $grantLines
     * @param  list<string>  $visibleDatabases  from SHOW DATABASES, to judge `_` wildcards
     * @return array{valid: bool, missing: list<string>, excessive: list<string>}
     */
    public function evaluate(array $grantLines, string $database, array $visibleDatabases = []): array
    {
        $onDatabase = [];
        $excessive = [];

        foreach ($grantLines as $line) {
            if (preg_match('/^\s*GRANT\s+PROXY\b/i', $line)) {
                $excessive[] = 'PROXY';

                continue;
            }

            // Drop anything after TO: account names and, on MariaDB, IDENTIFIED BY PASSWORD hashes.
            if (! preg_match('/^\s*GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is', $line, $match)) {
                // Role grants ("GRANT `role` TO …") and PROXY grants hide what the account can do.
                $excessive[] = 'role or unrecognised grant';

                continue;
            }

            $privileges = $this->privileges($match[1]);
            $scope = $this->scope(trim($match[2]), $database, $visibleDatabases);
            $withGrantOption = (bool) preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $line);

            if ($withGrantOption) {
                $excessive[] = "GRANT OPTION on {$scope['label']}";
            }

            if ($scope['kind'] === 'global') {
                foreach ($privileges as $privilege) {
                    if ($privilege !== 'USAGE') {
                        $excessive[] = "$privilege on *.*";
                    }
                }

                continue;
            }

            if ($scope['kind'] !== 'own_database') {
                foreach ($privileges as $privilege) {
                    if ($privilege !== 'USAGE') {
                        $excessive[] = "$privilege on {$scope['label']}";
                    }
                }

                continue;
            }

            foreach ($privileges as $privilege) {
                if (in_array($privilege, self::REQUIRED, true)) {
                    $onDatabase[$privilege] = true;
                } elseif (! in_array($privilege, self::ALLOWED_EXTRA, true)) {
                    $excessive[] = "$privilege on {$scope['label']}";
                }
            }
        }

        $missing = array_values(array_filter(self::REQUIRED, fn (string $privilege): bool => ! isset($onDatabase[$privilege])));
        $excessive = array_values(array_unique($excessive));

        return ['valid' => $missing === [] && $excessive === [], 'missing' => $missing, 'excessive' => $excessive];
    }

    /**
     * @return list<string>
     */
    private function privileges(string $list): array
    {
        $list = strtoupper(preg_replace('/\([^)]*\)/', '', $list) ?? $list);
        if (preg_match('/^\s*ALL(\s+PRIVILEGES)?\s*$/', $list)) {
            return ['ALL PRIVILEGES'];
        }

        return array_values(array_filter(array_map(
            fn (string $privilege): string => preg_replace('/\s+/', ' ', trim($privilege)) ?? '',
            explode(',', $list),
        )));
    }

    /**
     * Column- and table-level grants on Pane's own database count as its own
     * database. An unescaped `_` in a granted database name is a one-character
     * wildcard (hosting panels often grant `user_db`.* like that): it counts
     * as Pane's own database only when it matches that name and no other
     * database the account can see. `%` can match any database, so it never does.
     *
     * @param  list<string>  $visibleDatabases
     * @return array{kind: 'global'|'own_database'|'other', label: string}
     */
    private function scope(string $on, string $database, array $visibleDatabases): array
    {
        $on = preg_replace('/^(TABLE|FUNCTION|PROCEDURE|PACKAGE( BODY)?)\s+/i', '', $on) ?? $on;

        if ($on === '*.*' || $on === '*') {
            return ['kind' => 'global', 'label' => '*.*'];
        }

        if (! preg_match('/^(`(?:[^`]|``)+`|[^.`]+)\.(.+)$/', $on, $parts)) {
            return ['kind' => 'other', 'label' => $on];
        }

        $raw = $parts[1];
        $quoted = str_starts_with($raw, '`');
        $name = $quoted ? str_replace('``', '`', substr($raw, 1, -1)) : $raw;
        $hasWildcard = (bool) preg_match('/(?<!\\\\)[_%]/', $name);
        $unescaped = str_replace(['\\_', '\\%'], ['_', '%'], $name);

        if (! $hasWildcard && $unescaped === $database) {
            return ['kind' => 'own_database', 'label' => "`$database`"];
        }

        if ($hasWildcard && ! preg_match('/(?<!\\\\)%/', $name)) {
            $pattern = '/^'.implode('', array_map(
                fn (string $part): string => $part === '_' ? '.' : preg_quote(str_replace(['\\_', '\\%'], ['_', '%'], $part), '/'),
                preg_split('/((?<!\\\\)_)/', $name, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [],
            )).'$/su';
            $others = array_filter($visibleDatabases, fn (string $db): bool => $db !== $database && preg_match($pattern, $db) === 1);

            if (preg_match($pattern, $database) === 1 && $others === []) {
                return ['kind' => 'own_database', 'label' => "`$database`"];
            }
        }

        return ['kind' => 'other', 'label' => "`$unescaped`".($hasWildcard ? ' (wildcard)' : '')];
    }
}
