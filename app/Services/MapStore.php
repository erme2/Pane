<?php

namespace App\Services;

use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\Maps\MapNotFoundException;
use App\Exceptions\Maps\MapVersionConflictException;
use App\Exceptions\Maps\SystemMapProtectedException;
use App\Models\Maps\MapField;
use App\Models\Maps\MapSource;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Support\Maps\MapDiff;
use App\Support\Maps\MapRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saves maps with full version history (decision 33, Pane#153 answer 2): every
 * save of a source or table map writes the new state and a version holding the
 * whole snapshot, the diff against the previous version and the author.
 * Restoring copies an old snapshot forward as a new version. System maps are
 * refused by the models (decision 32). Saving a map never touches the table it
 * describes (decision 29).
 */
class MapStore
{
    /**
     * @param  array<string, mixed>  $map
     *
     * @throws InvalidMapException
     * @throws MapVersionConflictException
     */
    public function saveSource(array $map, string $author, ?int $expectedVersion = null): MapVersion
    {
        $normalized = MapRules::normalizeSource($map);

        return DB::transaction(function () use ($normalized, $author, $expectedVersion): MapVersion {
            $source = MapSource::query()->where('name', $normalized['name'])->lockForUpdate()->first() ?? new MapSource;
            $this->checkVersion($source->exists ? $source->version : 0, $expectedVersion);
            $before = $source->exists ? $this->sourceSnapshot($source) : null;

            $source->fill([...$normalized, 'version' => ($source->exists ? $source->version : 0) + 1])->save();

            return MapVersion::query()->create([
                'subject_type' => MapVersion::SUBJECT_SOURCE,
                'subject_id' => $source->map_source_id,
                'version' => $source->version,
                'snapshot' => $normalized,
                'diff' => $before === null ? null : MapDiff::flat($before, $normalized),
                'author' => $author,
            ]);
        });
    }

    /**
     * Creates or replaces the map of one table in a source.
     *
     * @param  array<string, mixed>  $map
     *
     * @throws InvalidMapException
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     * @throws SystemMapProtectedException
     */
    public function saveTable(string $source, array $map, string $author, ?int $expectedVersion = null, ?int $restoredFrom = null): MapVersion
    {
        $normalized = MapRules::normalizeTable($map);
        $sourceModel = $this->source($source);

        return DB::transaction(function () use ($sourceModel, $normalized, $author, $expectedVersion, $restoredFrom): MapVersion {
            $table = MapTable::query()
                ->where('map_source_id', $sourceModel->map_source_id)
                ->where('name', $normalized['name'])
                ->lockForUpdate()
                ->first();
            $current = $table->version ?? 0;
            $this->checkVersion($current, $expectedVersion);
            $before = $table === null ? null : $this->tableSnapshot($table);

            $table ??= new MapTable(['map_source_id' => $sourceModel->map_source_id]);
            $table->fill([
                'name' => $normalized['name'],
                'sql_name' => $normalized['sql_name'],
                'description' => $normalized['description'],
                'ui' => $normalized['ui'],
                'version' => $current + 1,
            ])->save();

            $table->fields()->get()->each(fn (MapField $field) => $field->delete());
            foreach ($normalized['fields'] as $field) {
                MapField::query()->create([...$field, 'map_table_id' => $table->map_table_id]);
            }

            $snapshot = ['source' => $sourceModel->name, ...$normalized];

            return MapVersion::query()->create([
                'subject_type' => MapVersion::SUBJECT_TABLE,
                'subject_id' => $table->map_table_id,
                'version' => $table->version,
                'snapshot' => $snapshot,
                'diff' => $before === null ? null : MapDiff::tables($before, $snapshot),
                'author' => $author,
                'restored_from' => $restoredFrom,
            ]);
        });
    }

    /**
     * Saves an earlier version of a table map again, as a new version.
     *
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     * @throws SystemMapProtectedException
     */
    public function restoreTable(string $source, string $table, int $version, string $author, ?int $expectedVersion = null): MapVersion
    {
        $tableModel = $this->table($source, $table);
        $old = MapVersion::query()
            ->where('subject_type', MapVersion::SUBJECT_TABLE)
            ->where('subject_id', $tableModel->map_table_id)
            ->where('version', $version)
            ->first();
        if ($old === null) {
            throw new MapNotFoundException("Version $version of the map \"$source/$table\" does not exist.");
        }

        $snapshot = $old->snapshot;
        unset($snapshot['source']);

        return $this->saveTable($source, $snapshot, $author, $expectedVersion, restoredFrom: $version);
    }

    /**
     * The current map of one table, in the same shape as a version snapshot.
     *
     * @return array<string, mixed>
     *
     * @throws MapNotFoundException
     */
    public function tableMap(string $source, string $table): array
    {
        return $this->tableSnapshot($this->table($source, $table));
    }

    /**
     * @return Collection<int, MapVersion> newest first
     *
     * @throws MapNotFoundException
     */
    public function tableHistory(string $source, string $table): Collection
    {
        return MapVersion::query()
            ->where('subject_type', MapVersion::SUBJECT_TABLE)
            ->where('subject_id', $this->table($source, $table)->map_table_id)
            ->orderByDesc('version')
            ->get();
    }

    /** @throws MapNotFoundException */
    private function source(string $name): MapSource
    {
        return MapSource::query()->where('name', $name)->first()
            ?? throw new MapNotFoundException("The source \"$name\" is not mapped.");
    }

    /** @throws MapNotFoundException */
    private function table(string $source, string $name): MapTable
    {
        return MapTable::query()
            ->where('map_source_id', $this->source($source)->map_source_id)
            ->where('name', $name)
            ->first()
            ?? throw new MapNotFoundException("The table \"$source/$name\" is not mapped.");
    }

    /** @throws MapVersionConflictException */
    private function checkVersion(int $current, ?int $expected): void
    {
        if ($expected !== null && $expected !== $current) {
            throw new MapVersionConflictException($expected, $current);
        }
    }

    /** @return array{name: string, kind: string, description: string|null} */
    private function sourceSnapshot(MapSource $source): array
    {
        return ['name' => $source->name, 'kind' => $source->kind, 'description' => $source->description];
    }

    /** @return array<string, mixed> */
    private function tableSnapshot(MapTable $table): array
    {
        $table->loadMissing('source');

        return [
            'source' => $table->source?->name,
            'name' => $table->name,
            'sql_name' => $table->sql_name,
            'description' => $table->description,
            'ui' => $table->ui,
            'fields' => $table->fields()->get()->map(fn (MapField $field): array => [
                'name' => $field->name,
                'sql_name' => $field->sql_name,
                'type' => $field->type,
                'description' => $field->description,
                'format' => $field->format,
                'primary' => $field->primary,
                'index' => $field->index,
                'sortable' => $field->sortable,
                'nullable' => $field->nullable,
                'default' => $field->default,
                'validations' => $field->validations ?? [],
                'computed' => $field->computed,
                'ui' => $field->ui,
                'position' => $field->position,
            ])->all(),
        ];
    }
}
