<?php

namespace App\Services;

use App\Exceptions\Maps\MapNotFoundException;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Support\PaneTable;
use Illuminate\Support\Facades\DB;

/**
 * Reads the maps for clients (Pane#178, contract Pane#169): the bootstrap
 * Latte builds its shell from and one table map. Only mapped tables and fields
 * ever appear. Organization administrators see every table map, including the
 * system maps (read-only); standard members get no table entries and no menu
 * items that point at tables until per-table access policies open them.
 */
class MapReader
{
    private const PUBLIC_VALIDATION_RULES = ['max', 'min', 'email'];

    public function __construct(private readonly AppSettings $settings) {}

    /** The strong ETag of a read answer; the edit routes' If-Match is checked against it. */
    public static function etag(string $type, int $version, mixed $attributes): string
    {
        return '"'.hash('sha256', $type.$version.json_encode($attributes, JSON_THROW_ON_ERROR)).'"';
    }

    /**
     * @return array{version: int, menu: list<array<string, mixed>>, functions: list<array<string, mixed>>, settings: list<array<string, mixed>>, tables: list<array<string, mixed>>}
     */
    public function bootstrap(bool $administrator): array
    {
        $tables = $administrator ? $this->tableSummaries() : [];
        $functions = $this->functions();
        $enabled = array_column(array_filter($functions, fn (array $function): bool => $function['enabled']), 'key');
        $mapped = array_map(fn (array $table): string => $table['source'].'/'.$table['table'], $tables);

        $rows = DB::table(PaneTable::name(PaneTable::MAPS_MENU))->orderBy('position')->orderBy('map_menu_id')->get();
        $visible = $rows->filter(fn (object $row): bool => $row->target_type === 'function'
            ? in_array($row->target_function, $enabled, true)
            : in_array($row->target_source.'/'.$row->target_table, $mapped, true));
        $keys = $visible->pluck('key')->all();

        $menu = $visible
            // One level of nesting: an item whose parent isn't shown is left out too.
            ->filter(fn (object $row): bool => $row->parent_key === null || in_array($row->parent_key, $keys, true))
            ->map(fn (object $row): array => [
                'key' => $row->key,
                'label' => $row->label,
                'icon' => $row->icon,
                'target' => $row->target_type === 'function'
                    ? ['type' => 'function', 'function' => $row->target_function]
                    : ['type' => 'table', 'source' => $row->target_source, 'table' => $row->target_table],
                'parent' => $row->parent_key,
                'position' => (int) $row->position,
            ])
            ->values()
            ->all();

        return [
            'version' => (int) $rows->max('version'),
            'menu' => $menu,
            'functions' => $functions,
            'settings' => $this->settings->publicSettings(),
            'tables' => $tables,
        ];
    }

    /**
     * @return array{source: string, table: string, description: string|null, system: bool, version: int, ui: array<mixed>|null, fields: list<array<string, mixed>>}
     *
     * @throws MapNotFoundException
     */
    public function table(string $source, string $table): array
    {
        $map = MapTable::query()
            ->whereHas('source', fn ($query) => $query->where('name', $source))
            ->where('name', $table)
            ->first();
        if ($map === null) {
            throw new MapNotFoundException("The table \"$source/$table\" is not mapped.");
        }

        return [
            ...$this->summary($source, $map),
            'fields' => $map->fields()->get()->map(fn (MapField $field): array => [
                'name' => $field->name,
                'type' => $field->type,
                'description' => $field->description,
                'format' => $field->format,
                'primary' => $field->primary,
                'nullable' => $field->nullable,
                'sortable' => $field->sortable,
                'computed' => $field->computed !== null,
                'default' => $field->default,
                'validations' => $this->publicValidations($field->validations ?? []),
                'ui' => $field->ui,
            ])->all(),
        ];
    }

    /**
     * Only the hints the contract publishes (MapFieldValidation); server-side
     * rules such as unique and exists reference tables and stay private.
     *
     * @param  list<array<string, mixed>>  $validations
     * @return list<array<string, mixed>>
     */
    private function publicValidations(array $validations): array
    {
        return array_values(array_filter(
            $validations,
            fn (array $validation): bool => in_array($validation['rule'] ?? null, self::PUBLIC_VALIDATION_RULES, true),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function tableSummaries(): array
    {
        return MapTable::query()->with('source')->get()
            ->sortBy(fn (MapTable $table): string => $table->source?->name.'/'.$table->name)
            ->map(fn (MapTable $table): array => $this->summary((string) $table->source?->name, $table))
            ->values()
            ->all();
    }

    /** @return array{source: string, table: string, description: string|null, system: bool, version: int, ui: array<mixed>|null} */
    private function summary(string $source, MapTable $table): array
    {
        return [
            'source' => $source,
            'table' => $table->name,
            'description' => $table->description,
            'system' => $table->isSystem(),
            'version' => $table->version,
            'ui' => $table->ui,
        ];
    }

    /** @return list<array{key: string, enabled: bool}> */
    private function functions(): array
    {
        return DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->orderBy('key')->get()
            ->map(fn (object $row): array => ['key' => $row->key, 'enabled' => (bool) $row->enabled])
            ->all();
    }
}
