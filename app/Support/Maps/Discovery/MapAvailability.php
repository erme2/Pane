<?php

namespace App\Support\Maps\Discovery;

use App\Exceptions\Maps\MapUnavailableException;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;

/**
 * Whether a table map still matches the real table: a mapped table or field
 * that no longer exists makes the map unavailable until it is fixed. The
 * discovery report lists these as `missing`; the read and CRUD routes call
 * assertAvailable() before serving a table.
 *
 * @phpstan-import-type DiscoveredColumn from CatalogNormalizer
 *
 * @phpstan-type MissingPart array{table: string, field: string|null, sql_table: string, sql_field: string|null}
 */
class MapAvailability
{
    public function __construct(private readonly CatalogReader $reader) {}

    public static function sqlTable(MapTable $table): string
    {
        return $table->sql_name ?? $table->name;
    }

    public static function sqlField(MapField $field): string
    {
        return $field->sql_name ?? $field->name;
    }

    /**
     * @throws MapUnavailableException naming the first missing part
     */
    public function assertAvailable(MapTable $table, ?string $connection = null): void
    {
        $missing = $this->missing($table, $this->reader->readTable(self::sqlTable($table), $connection));

        if ($missing !== []) {
            throw new MapUnavailableException($table->source->name, $table->name, $missing[0]['field']);
        }
    }

    /**
     * The parts of the map the real table lacks: the whole table (field null)
     * when $columns is null, otherwise each mapped field without a column.
     *
     * @param  array<string, DiscoveredColumn>|null  $columns
     * @return list<MissingPart>
     */
    public function missing(MapTable $table, ?array $columns): array
    {
        $sqlTable = self::sqlTable($table);

        if ($columns === null) {
            return [['table' => $table->name, 'field' => null, 'sql_table' => $sqlTable, 'sql_field' => null]];
        }

        $missing = [];
        foreach ($table->fields as $field) {
            if ($field->computed !== null) {
                // A computed field is evaluated from other fields and needs no column of its own.
                continue;
            }

            $sqlField = self::sqlField($field);
            if (! array_key_exists($sqlField, $columns)) {
                $missing[] = ['table' => $table->name, 'field' => $field->name, 'sql_table' => $sqlTable, 'sql_field' => $sqlField];
            }
        }

        return $missing;
    }
}
