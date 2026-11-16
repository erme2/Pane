<?php

namespace App\Console\Commands;

use App\Services\MapDiscovery;
use Illuminate\Console\Command;

/**
 * Prints the maps discovery report (Pane#155) for deploys and debugging: the
 * same report as GET /organizations/{organization_id}/maps/discovery. It never
 * changes maps or tables, and always exits 0 when the report could be built.
 */
class DiscoverMaps extends Command
{
    protected $signature = 'pane:maps:discover {--json : Print the report as JSON}';

    protected $description = "Report how the install's own app tables differ from the maps (unmapped, missing, changed).";

    public function handle(MapDiscovery $discovery): int
    {
        $report = $discovery->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($report['app_table_prefix'] === '') {
            $this->warn('APP_TABLE_PREFIX is empty: the install has no app tables to discover.');

            return self::SUCCESS;
        }

        $this->line("App table prefix: {$report['app_table_prefix']}");

        $this->section('Unmapped tables', array_map(
            fn (array $table): string => sprintf('%s (%d fields)', $table['sql_table'], count($table['fields'])),
            $report['unmapped_tables'],
        ));
        $this->section('Unmapped fields', array_map(
            fn (array $field): string => sprintf('%s.%s %s', $field['sql_table'], $field['sql_field'], $field['sql_type']),
            $report['unmapped_fields'],
        ));
        $this->section('Missing', array_map(
            fn (array $part): string => $part['field'] === null
                ? sprintf('%s.%s: table %s', $part['source'], $part['table'], $part['sql_table'])
                : sprintf('%s.%s.%s: column %s.%s', $part['source'], $part['table'], $part['field'], $part['sql_table'], $part['sql_field']),
            $report['missing'],
        ));
        $this->section('Changed', array_map(
            fn (array $field): string => sprintf(
                '%s.%s.%s: map %s%s, column %s %s (%s)',
                $field['source'], $field['table'], $field['field'],
                $field['map_type'], $field['map_nullable'] ? ' nullable' : '',
                $field['sql_type'], $field['nullable'] ? 'null' : 'not null',
                implode(', ', $field['reasons']),
            ),
            $report['changed'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $lines
     */
    private function section(string $title, array $lines): void
    {
        $this->line(sprintf('%s: %d', $title, count($lines)));
        foreach ($lines as $line) {
            $this->line('  '.$line);
        }
    }
}
