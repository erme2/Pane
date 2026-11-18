<?php

namespace App\Services;

use App\Exceptions\AppInstall\AppInstallException;
use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\Maps\MapNotFoundException;
use App\Exceptions\Maps\SystemMapProtectedException;
use App\Models\Maps\MapSource;
use App\Models\Maps\MapTable;
use App\Support\Maps\MapProtection;
use App\Support\Maps\MapRules;
use App\Support\Maps\TablePrefixes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Installs an app's data folder (docs/app-install.md): its pending migrations
 * under APP_TABLE_PREFIX, then its maps. Everything that can be checked is
 * checked before the first write, so a bad prefix, migration file or maps file
 * changes nothing. The only tables written are the ones the migration files
 * create, the prefixed schema_migrations table and Pane's own maps tables.
 */
class AppInstaller
{
    public const string SOURCE = 'install';

    private const string AUTHOR = 'app:install';

    public function __construct(private readonly MapStore $maps) {}

    /**
     * Migrations in file order, each with whether it already ran.
     *
     * @return list<array{version: string, file: string, ran: bool}>
     *
     * @throws AppInstallException
     */
    public function status(string $folder): array
    {
        $ran = $this->ranVersions($this->prefix());

        return array_map(
            fn (array $migration): array => [
                'version' => $migration['version'],
                'file' => $migration['file'],
                'ran' => in_array($migration['version'], $ran, true),
            ],
            $this->migrations($folder),
        );
    }

    /**
     * @return array{migrations: list<string>, maps: array{created: list<string>, updated: list<string>, unchanged: list<string>}}
     *
     * @throws AppInstallException
     */
    public function install(string $folder): array
    {
        $prefix = $this->prefix();
        $migrations = $this->migrations($folder);
        $tables = $this->loadMaps($folder, $prefix);

        $ran = [];
        $already = $this->ranVersions($prefix);
        foreach ($migrations as $migration) {
            if (in_array($migration['version'], $already, true)) {
                continue;
            }
            $this->run($prefix, $migration);
            $ran[] = $migration['file'];
        }

        return ['migrations' => $ran, 'maps' => $this->saveMaps($tables)];
    }

    /** @throws AppInstallException */
    private function prefix(): string
    {
        $prefix = (string) config('database.app_table_prefix', '');
        if (preg_match('/\A[a-z0-9_]*_\z/', $prefix) !== 1 || $prefix === '_') {
            throw new AppInstallException('APP_TABLE_PREFIX is required and must be lowercase letters, digits and underscores, ending with _ (for example sweegly_).');
        }
        try {
            TablePrefixes::assertNoOverlap((string) config('database.table_prefix', ''), $prefix);
        } catch (RuntimeException $exception) {
            throw new AppInstallException($exception->getMessage());
        }

        return $prefix;
    }

    /** @return list<string> */
    private function ranVersions(string $prefix): array
    {
        $table = $prefix.'schema_migrations';
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->pluck('version')->map(fn ($version): string => (string) $version)->all();
    }

    /**
     * @return list<array{version: string, file: string, sql: string}>
     *
     * @throws AppInstallException
     */
    private function migrations(string $folder): array
    {
        if (! is_dir($folder)) {
            throw new AppInstallException("The data folder \"$folder\" does not exist.");
        }
        $files = glob(rtrim($folder, '/').'/migrations/*.sql') ?: [];
        sort($files);
        if ($files === []) {
            throw new AppInstallException("The data folder \"$folder\" has no migrations/*.sql files.");
        }

        $migrations = [];
        foreach ($files as $path) {
            $file = basename($path);
            $version = explode('_', $file, 2)[0];
            if (preg_match('/\A\d+\z/', $version) !== 1) {
                throw new AppInstallException("The migration \"$file\" must start with a numeric version, like 20261117100001_create_lists.sql.");
            }
            $content = (string) file_get_contents($path);
            if (preg_match('/^--\s*migrate:up\b[^\n]*\n(.*?)(?=^--\s*migrate:down\b|\z)/ms', $content, $match) !== 1 || trim($match[1]) === '') {
                throw new AppInstallException("The migration \"$file\" has no SQL after its \"-- migrate:up\" line.");
            }
            $migrations[] = ['version' => $version, 'file' => $file, 'sql' => $match[1]];
        }
        if (count(array_unique(array_column($migrations, 'version'))) !== count($migrations)) {
            throw new AppInstallException('Two migrations share the same version.');
        }

        return $migrations;
    }

    /** @param array{version: string, file: string, sql: string} $migration */
    private function run(string $prefix, array $migration): void
    {
        $table = $prefix.'schema_migrations';
        if (! Schema::hasTable($table)) {
            // Same layout as dbmate's, so either tool can continue after the other.
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->string('version', 128)->primary();
            });
        }

        try {
            DB::unprepared(str_replace('{{prefix}}', $prefix, $migration['sql']));
        } catch (Throwable $exception) {
            throw new AppInstallException("The migration \"{$migration['file']}\" failed: {$exception->getMessage()}", 0, $exception);
        }
        DB::table($table)->insert(['version' => $migration['version']]);
    }

    /**
     * Reads and checks every maps file before anything is written.
     *
     * @return list<array<string, mixed>> normalized table maps
     *
     * @throws AppInstallException
     */
    private function loadMaps(string $folder, string $prefix): array
    {
        $files = glob(rtrim($folder, '/').'/maps/*.json') ?: [];
        sort($files);

        $tables = [];
        $names = [];
        foreach ($files as $path) {
            $file = basename($path);
            $json = json_decode((string) file_get_contents($path), true);
            if (! is_array($json) || ! isset($json['tables']) || ! is_array($json['tables']) || ! array_is_list($json['tables'])) {
                throw new AppInstallException("The maps file \"$file\" must be a JSON object with a \"tables\" list.");
            }
            if (($json['source'] ?? self::SOURCE) !== self::SOURCE) {
                throw new AppInstallException("The maps file \"$file\" can only map the \"".self::SOURCE.'" source.');
            }
            foreach ($json['tables'] as $i => $map) {
                try {
                    $table = MapRules::normalizeTable(is_array($map) ? $map : []);
                } catch (InvalidMapException $exception) {
                    throw new AppInstallException("The maps file \"$file\", table $i: {$exception->getMessage()}", 0, $exception);
                }
                $sqlName = $table['sql_name'];
                if ($sqlName !== null && MapProtection::isSystemTable($sqlName)) {
                    throw new AppInstallException("The maps file \"$file\" maps the system table \"$sqlName\", which can't be changed.");
                }
                if ($sqlName !== null && ! str_starts_with($sqlName, $prefix)) {
                    throw new AppInstallException("The maps file \"$file\" maps \"$sqlName\", which is outside APP_TABLE_PREFIX \"$prefix\".");
                }
                $existing = MapTable::query()
                    ->whereIn('map_source_id', MapSource::query()->where('name', self::SOURCE)->select('map_source_id'))
                    ->where('name', $table['name'])
                    ->first();
                if ($existing !== null && $existing->isSystem()) {
                    throw new AppInstallException("The maps file \"$file\" reuses the name \"{$table['name']}\" of a system map, which can't be changed.");
                }
                if (isset($names[$table['name']])) {
                    throw new AppInstallException("The map name \"{$table['name']}\" is used twice.");
                }
                $names[$table['name']] = true;
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Creates or updates each map; a map that already matches is left alone, so
     * a second run adds no version.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>}
     */
    private function saveMaps(array $tables): array
    {
        $result = ['created' => [], 'updated' => [], 'unchanged' => []];
        if ($tables === []) {
            return $result;
        }

        if (MapSource::query()->where('name', self::SOURCE)->doesntExist()) {
            $this->maps->saveSource(['name' => self::SOURCE, 'kind' => 'install', 'description' => "The install's own database"], self::AUTHOR);
        }

        foreach ($tables as $table) {
            $name = (string) $table['name'];
            try {
                $current = $this->maps->tableMap(self::SOURCE, $name);
            } catch (MapNotFoundException) {
                $current = null;
            }
            if ($current !== null && $current == ['source' => self::SOURCE, ...$table]) {
                $result['unchanged'][] = $name;

                continue;
            }
            try {
                $this->maps->saveTable(self::SOURCE, $table, self::AUTHOR);
            } catch (SystemMapProtectedException $exception) {
                throw new AppInstallException($exception->getMessage(), 0, $exception);
            }
            $result[$current === null ? 'created' : 'updated'][] = $name;
        }

        return $result;
    }
}
