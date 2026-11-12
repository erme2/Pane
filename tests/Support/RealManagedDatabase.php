<?php

namespace Tests\Support;

use PDO;

/**
 * A real MySQL or MariaDB server for the managed-database integration tests,
 * configured by PANE_IT_DB_ENGINE (mysql|mariadb), PANE_IT_DB_HOST,
 * PANE_IT_DB_PORT and PANE_IT_DB_ROOT_PASSWORD. Tests skip when it's unset.
 *
 * Each instance creates its own database and accounts and drops them in
 * cleanup(), so runs don't interfere.
 */
final class RealManagedDatabase
{
    public readonly string $engine;

    public readonly string $host;

    public readonly int $port;

    public readonly string $database;

    private PDO $root;

    /** @var list<string> */
    private array $databases = [];

    /** @var list<string> */
    private array $accounts = [];

    /** @var list<string> */
    private array $roles = [];

    public static function configured(): bool
    {
        return is_string(getenv('PANE_IT_DB_HOST')) && getenv('PANE_IT_DB_HOST') !== '';
    }

    public function __construct()
    {
        $this->engine = (string) (getenv('PANE_IT_DB_ENGINE') ?: 'mysql');
        $this->host = (string) getenv('PANE_IT_DB_HOST');
        $this->port = (int) (getenv('PANE_IT_DB_PORT') ?: 3306);
        $this->root = new PDO(
            "mysql:host={$this->host};port={$this->port}",
            'root',
            (string) getenv('PANE_IT_DB_ROOT_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->database = $this->createDatabase('it_'.bin2hex(random_bytes(4)));
        $this->root->exec("CREATE TABLE `{$this->database}`.items (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB");
        $this->root->exec("INSERT INTO `{$this->database}`.items VALUES (1, 0)");
    }

    public function root(): PDO
    {
        return $this->root;
    }

    public function createDatabase(string $name): string
    {
        $this->root->exec("CREATE DATABASE `$name`");
        $this->databases[] = $name;

        return $name;
    }

    /**
     * Creates an account with the given GRANT statements; `{db}` and
     * `{account}` are replaced. Returns [username, password].
     *
     * @param  list<string>  $grants
     * @return array{0: string, 1: string}
     */
    public function account(array $grants): array
    {
        $user = 'u'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(12));
        $account = "'$user'@'%'";
        $this->root->exec("CREATE USER $account IDENTIFIED BY '$password'");
        $this->accounts[] = $account;

        foreach ($grants as $grant) {
            $this->root->exec(str_replace(['{db}', '{account}'], [$this->database, $account], $grant));
        }

        return [$user, $password];
    }

    /** Grants a role to the account (and makes it the default role on MySQL). */
    public function grantRole(string $user, string $privilegesSql): void
    {
        $role = 'r'.bin2hex(random_bytes(5));
        $this->root->exec("CREATE ROLE `$role`");
        $this->roles[] = $role;
        $this->root->exec(str_replace(['{db}', '{role}'], [$this->database, "`$role`"], $privilegesSql));
        $this->root->exec("GRANT `$role` TO '$user'@'%'");
    }

    public function cleanup(): void
    {
        foreach ($this->accounts as $account) {
            $this->root->exec("DROP USER IF EXISTS $account");
        }
        foreach ($this->roles as $role) {
            $this->root->exec("DROP ROLE IF EXISTS `$role`");
        }
        foreach ($this->databases as $database) {
            $this->root->exec("DROP DATABASE IF EXISTS `$database`");
        }
    }
}
