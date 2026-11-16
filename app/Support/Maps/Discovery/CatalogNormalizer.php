<?php

namespace App\Support\Maps\Discovery;

/**
 * Turns the columns Laravel's schema builder reports (CatalogReader) into a
 * driver-neutral shape, and decides whether a mapped field still fits the real
 * column. The type table comes from the catalog normalizer of PR #151, extended
 * with the SQLite type names.
 *
 * @phpstan-type DiscoveredColumn array{sql_type: string, type: string, nullable: bool}
 */
class CatalogNormalizer
{
    /** Identifiers Pane can quote and show unambiguously (`table.column`). */
    public const string IDENTIFIER_PATTERN = '/\A[\p{L}\p{N}_$]{1,64}\z/u';

    public const string UNSUPPORTED = 'unsupported';

    private const array TYPES = [
        'tinyint' => 'integer', 'smallint' => 'integer', 'mediumint' => 'integer', 'int' => 'integer',
        'integer' => 'integer', 'bigint' => 'integer', 'year' => 'integer',
        'decimal' => 'decimal', 'numeric' => 'decimal',
        'float' => 'float', 'double' => 'float', 'real' => 'float',
        'char' => 'string', 'varchar' => 'string', 'enum' => 'string',
        'tinytext' => 'text', 'text' => 'text', 'mediumtext' => 'text', 'longtext' => 'text', 'clob' => 'text',
        'date' => 'date', 'datetime' => 'datetime', 'timestamp' => 'datetime', 'time' => 'time',
        'json' => 'json', 'uuid' => 'uuid', 'boolean' => 'boolean', 'bool' => 'boolean',
    ];

    /**
     * The map field types (MapRules::FIELD_TYPES) each discovered type can
     * serve. A pair missing here is reported as a changed field.
     */
    private const array COMPATIBLE = [
        'integer' => ['number', 'boolean'],
        'decimal' => ['number'],
        'float' => ['number'],
        'boolean' => ['boolean', 'number'],
        'string' => ['string', 'text', 'password'],
        'text' => ['text', 'string', 'password', 'json', 'array'],
        'date' => ['date', 'string'],
        'datetime' => ['date', 'string'],
        'time' => ['string'],
        'json' => ['json', 'array'],
        'uuid' => ['string'],
    ];

    /**
     * @param  array<string, mixed>  $column  one row of Schema::getColumns()
     * @return DiscoveredColumn
     */
    public function column(array $column): array
    {
        $sqlType = strtolower((string) ($column['type'] ?? ''));
        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        if ($typeName === '') {
            // SQLite reports the declared type only, for example `varchar(255)`.
            $typeName = (string) preg_replace('/\(.*\z/', '', $sqlType);
        }

        $type = self::TYPES[trim($typeName)] ?? self::UNSUPPORTED;
        if ($sqlType === 'bit(1)' || $sqlType === 'tinyint(1)') {
            $type = 'boolean';
        }

        return [
            'sql_type' => $sqlType,
            'type' => $type,
            'nullable' => (bool) ($column['nullable'] ?? false),
        ];
    }

    /**
     * Why a mapped field no longer fits its column: `type` when the column's
     * type can't serve the map type, `nullable` when the map accepts null but
     * the column doesn't. Empty when it still fits.
     *
     * @param  DiscoveredColumn  $column
     * @return list<string>
     */
    public function differences(string $mapType, bool $mapNullable, array $column): array
    {
        $reasons = [];
        if (! in_array($mapType, self::COMPATIBLE[$column['type']] ?? [], true)) {
            $reasons[] = 'type';
        }
        if ($mapNullable && ! $column['nullable']) {
            $reasons[] = 'nullable';
        }

        return $reasons;
    }
}
