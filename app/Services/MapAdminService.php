<?php

namespace App\Services;

use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\Maps\MapNotFoundException;
use App\Exceptions\Maps\MapVersionConflictException;
use App\Exceptions\Maps\SystemMapProtectedException;
use App\Models\AuditEvent;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Support\Maps\MapProtection;
use App\Support\Maps\MapRules;
use App\Support\PaneTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The maps admin API's logic (Pane#157): edits to table maps, the menu and the
 * function switches, each one audited. Editing a map never creates, alters or
 * drops a table: the only schema calls here read it (Pane#153 decision 29).
 * System maps are refused (decision 32); the menu is versioned in
 * `maps_versions` as the subject `menu`, function switches are audited only.
 */
class MapAdminService
{
    public const string SUBJECT_MENU = 'menu';

    private const string KEY = '/\A[a-z][a-z0-9_]{0,63}\z/';

    private const int MAX_MENU_ITEMS = 200;

    public function __construct(private readonly MapStore $store, private readonly AuditEventService $audit) {}

    /**
     * Replaces the map of one table. `$expectedVersion` null means "create": it fails
     * when the table is mapped already.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws InvalidMapException
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     * @throws SystemMapProtectedException
     */
    public function saveTable(User $actor, string $source, string $table, array $body, ?int $expectedVersion, ?string $requestId): MapVersion
    {
        if (isset($body['name']) && $body['name'] !== $table) {
            throw new InvalidMapException(['name' => 'Must match the table in the path.']);
        }
        $map = [...$body, 'name' => $table];

        $existing = $this->existing($source, $table);
        if ($existing === null && $expectedVersion !== null) {
            throw new MapVersionConflictException($expectedVersion, 0);
        }
        if ($existing !== null && $expectedVersion === null) {
            throw new MapVersionConflictException(0, $existing->version);
        }
        if ($existing?->isSystem() || MapProtection::isSystemTable($this->physicalTable($map))) {
            throw SystemMapProtectedException::forTable($table, $this->physicalTable($map));
        }

        $normalized = MapRules::normalizeTable($map);
        $this->checkColumns($normalized);

        $version = $this->store->saveTable($source, $normalized, $this->author($actor), $expectedVersion ?? 0);
        $this->auditEdit($actor, 'maps.table.save', $requestId, ['source' => $source, 'table' => $table], $version->version - 1, $version->version);

        return $version;
    }

    /**
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     * @throws SystemMapProtectedException
     * @throws InvalidMapException
     */
    public function restoreTable(User $actor, string $source, string $table, int $version, int $expectedVersion, ?string $requestId): MapVersion
    {
        $existing = $this->existing($source, $table);
        if ($existing?->isSystem()) {
            throw SystemMapProtectedException::forTable($table, $existing->sql_name);
        }
        // Columns may have changed since the old version was saved.
        $old = $this->store->tableVersion($source, $table, $version);
        $snapshot = $old->snapshot;
        unset($snapshot['source']);
        $this->checkColumns(MapRules::normalizeTable($snapshot));

        $new = $this->store->restoreTable($source, $table, $version, $this->author($actor), $expectedVersion);
        $this->auditEdit($actor, 'maps.table.restore', $requestId, ['source' => $source, 'table' => $table, 'restored_version' => $version], $new->version - 1, $new->version);

        return $new;
    }

    /** @throws MapNotFoundException */
    public function tableHistory(string $source, string $table): Collection
    {
        return $this->store->tableHistory($source, $table);
    }

    /** @return array{version: int, items: list<array<string, mixed>>} */
    public function menu(): array
    {
        $rows = DB::table(PaneTable::name(PaneTable::MAPS_MENU))->orderBy('position')->orderBy('map_menu_id')->get();

        return ['version' => (int) $rows->max('version'), 'items' => $this->items($rows)];
    }

    /**
     * Replaces the whole menu with `$items`.
     *
     *
     * @throws InvalidMapException
     * @throws MapVersionConflictException
     */
    public function saveMenu(User $actor, mixed $items, int $expectedVersion, ?string $requestId, ?int $restoredFrom = null): MapVersion
    {
        $normalized = $this->normalizeMenu($items);

        return DB::transaction(function () use ($actor, $normalized, $expectedVersion, $requestId, $restoredFrom): MapVersion {
            $table = PaneTable::name(PaneTable::MAPS_MENU);
            $rows = DB::table($table)->lockForUpdate()->get();
            $current = (int) $rows->max('version');
            if ($current !== $expectedVersion) {
                throw new MapVersionConflictException($expectedVersion, $current);
            }
            $before = $this->items($rows->sortBy('position')->values());

            $now = now();
            DB::table($table)->delete();
            foreach ($normalized as $item) {
                DB::table($table)->insert([
                    'key' => $item['key'], 'label' => $item['label'], 'icon' => $item['icon'],
                    'target_type' => $item['target']['type'],
                    'target_source' => $item['target']['source'] ?? null,
                    'target_table' => $item['target']['table'] ?? null,
                    'target_function' => $item['target']['function'] ?? null,
                    'parent_key' => $item['parent'], 'position' => $item['position'],
                    'version' => $current + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $version = MapVersion::query()->create([
                'subject_type' => self::SUBJECT_MENU,
                'subject_id' => 0,
                'version' => $current + 1,
                'snapshot' => ['items' => $normalized],
                'diff' => $this->menuDiff($before, $normalized),
                'author' => $this->author($actor),
                'restored_from' => $restoredFrom,
            ]);
            $this->auditEdit($actor, $restoredFrom === null ? 'maps.menu.save' : 'maps.menu.restore', $requestId, ['subject' => self::SUBJECT_MENU], $current, $current + 1);

            return $version;
        });
    }

    /**
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     * @throws InvalidMapException
     */
    public function restoreMenu(User $actor, int $version, int $expectedVersion, ?string $requestId): MapVersion
    {
        $old = MapVersion::query()
            ->where('subject_type', self::SUBJECT_MENU)->where('subject_id', 0)->where('version', $version)->first();
        if ($old === null) {
            throw new MapNotFoundException("Version $version of the menu does not exist.");
        }

        return $this->saveMenu($actor, $old->snapshot['items'] ?? [], $expectedVersion, $requestId, restoredFrom: $version);
    }

    /** @return Collection<int, MapVersion> newest first */
    public function menuHistory(): Collection
    {
        return MapVersion::query()
            ->where('subject_type', self::SUBJECT_MENU)->where('subject_id', 0)
            ->orderByDesc('version')->get();
    }

    /**
     * Switches one function. `$expectedEnabled` is the state the caller saw.
     *
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     */
    public function setFunction(User $actor, string $key, bool $enabled, bool $expectedEnabled, ?string $requestId): bool
    {
        return DB::transaction(function () use ($actor, $key, $enabled, $expectedEnabled, $requestId): bool {
            $row = DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->where('key', $key)->lockForUpdate()->first();
            if ($row === null) {
                throw new MapNotFoundException("The function \"$key\" does not exist.");
            }
            if ((bool) $row->enabled !== $expectedEnabled) {
                throw new MapVersionConflictException((int) $expectedEnabled, (int) $row->enabled);
            }
            DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->where('key', $key)->update(['enabled' => $enabled, 'updated_at' => now()]);
            $this->auditEdit($actor, 'maps.function.switch', $requestId, ['function' => $key, 'enabled' => $enabled], null, null);

            return $enabled;
        });
    }

    /** @return bool|null null when the function does not exist */
    public function functionState(string $key): ?bool
    {
        $row = DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->where('key', $key)->first();

        return $row === null ? null : (bool) $row->enabled;
    }

    private function existing(string $source, string $table): ?MapTable
    {
        return MapTable::query()
            ->whereHas('source', fn ($query) => $query->where('name', $source))
            ->where('name', $table)
            ->first();
    }

    /** @param  array<string, mixed>  $map */
    private function physicalTable(array $map): string
    {
        $sqlName = $map['sql_name'] ?? null;

        return is_string($sqlName) && $sqlName !== '' ? $sqlName : (string) ($map['name'] ?? '');
    }

    /**
     * A map may only name a table and columns that exist: nothing here creates them.
     *
     * @param  array<string, mixed>  $normalized
     *
     * @throws InvalidMapException
     */
    private function checkColumns(array $normalized): void
    {
        $physical = $this->physicalTable($normalized);
        if (! Schema::hasTable($physical)) {
            throw new InvalidMapException(['name' => "The table \"$physical\" does not exist. Maps never create tables; add it with a migration."]);
        }

        $columns = array_map('strtolower', Schema::getColumnListing($physical));
        $errors = [];
        foreach ($normalized['fields'] as $i => $field) {
            if ($field['computed'] !== null) {
                continue;
            }
            $column = is_string($field['sql_name']) && $field['sql_name'] !== '' ? $field['sql_name'] : $field['name'];
            if (! in_array(strtolower($column), $columns, true)) {
                $errors["fields.$i.name"] = "The column \"$column\" does not exist in the table \"$physical\".";
            }
        }
        if ($errors !== []) {
            throw new InvalidMapException($errors);
        }
    }

    /**
     * @return list<array{key: string, label: string, icon: string|null, target: array<string, string>, parent: string|null, position: int}>
     *
     * @throws InvalidMapException
     */
    private function normalizeMenu(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_MENU_ITEMS) {
            throw new InvalidMapException(['items' => 'A list of 1 to '.self::MAX_MENU_ITEMS.' items.']);
        }

        $errors = [];
        $functions = DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->pluck('key')->all();
        $keys = array_map(fn ($item) => is_array($item) && is_string($item['key'] ?? null) ? $item['key'] : null, $items);
        $normalized = [];
        $seen = [];

        foreach ($items as $i => $item) {
            $path = "items.$i";
            if (! is_array($item)) {
                $errors[$path] = 'Must be an object.';

                continue;
            }
            $key = $item['key'] ?? null;
            if (! is_string($key) || ! preg_match(self::KEY, $key)) {
                $errors["$path.key"] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';
            } elseif (isset($seen[$key])) {
                $errors["$path.key"] = 'Duplicate key.';
            } else {
                $seen[$key] = true;
            }
            $label = $item['label'] ?? null;
            if (! is_string($label) || trim($label) === '' || strlen($label) > 255) {
                $errors["$path.label"] = 'A text of 1 to 255 characters.';
            }
            $icon = $item['icon'] ?? null;
            if ($icon !== null && (! is_string($icon) || strlen($icon) > 64)) {
                $errors["$path.icon"] = 'Must be a string of at most 64 characters, or null.';
            }
            $position = $item['position'] ?? $i;
            if (! is_int($position) || $position < 0) {
                $errors["$path.position"] = 'Must be a whole number, 0 or more.';
            }
            $parent = $item['parent'] ?? null;
            if ($parent !== null) {
                $parentItem = is_string($parent) ? $items[array_search($parent, $keys, true)] ?? null : null;
                if (! is_array($parentItem) || $parent === $key) {
                    $errors["$path.parent"] = 'Must be the key of another item in the menu.';
                } elseif (($parentItem['parent'] ?? null) !== null) {
                    $errors["$path.parent"] = 'Only one level of nesting.';
                }
            }

            $target = $this->normalizeTarget($item['target'] ?? null, $functions, "$path.target", $errors);

            if (is_string($key) && is_string($label) && $target !== null) {
                $normalized[] = [
                    'key' => $key, 'label' => $label, 'icon' => $icon, 'target' => $target,
                    'parent' => is_string($parent) ? $parent : null, 'position' => is_int($position) ? $position : $i,
                ];
            }
        }

        if ($errors !== []) {
            throw new InvalidMapException($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $functions
     * @param  array<string, string>  $errors
     * @return array<string, string>|null
     */
    private function normalizeTarget(mixed $target, array $functions, string $path, array &$errors): ?array
    {
        if (is_array($target) && ($target['type'] ?? null) === 'function') {
            if (is_string($target['function'] ?? null) && in_array($target['function'], $functions, true)) {
                return ['type' => 'function', 'function' => $target['function']];
            }
            $errors["$path.function"] = 'One of: '.implode(', ', $functions).'.';

            return null;
        }
        if (is_array($target) && ($target['type'] ?? null) === 'table') {
            $source = $target['source'] ?? null;
            $table = $target['table'] ?? null;
            if (is_string($source) && is_string($table) && $this->existing($source, $table) !== null) {
                return ['type' => 'table', 'source' => $source, 'table' => $table];
            }
            $errors["$path.table"] = 'Must name a mapped table (source and table).';

            return null;
        }
        $errors["$path.type"] = 'One of: table, function.';

        return null;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function items(Collection $rows): array
    {
        return $rows->map(fn (object $row): array => [
            'key' => $row->key,
            'label' => $row->label,
            'icon' => $row->icon,
            'target' => $row->target_type === 'function'
                ? ['type' => 'function', 'function' => $row->target_function]
                : ['type' => 'table', 'source' => $row->target_source, 'table' => $row->target_table],
            'parent' => $row->parent_key,
            'position' => (int) $row->position,
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{added: list<string>, removed: list<string>, changed: array<string, array<string, array{0: mixed, 1: mixed}>>}
     */
    private function menuDiff(array $before, array $after): array
    {
        $old = array_column($before, null, 'key');
        $new = array_column($after, null, 'key');
        $changed = [];
        foreach ($new as $key => $item) {
            if (isset($old[$key])) {
                foreach ($item as $field => $value) {
                    if ($old[$key][$field] !== $value) {
                        $changed[$key][$field] = [$old[$key][$field], $value];
                    }
                }
            }
        }

        return [
            'added' => array_values(array_diff(array_keys($new), array_keys($old))),
            'removed' => array_values(array_diff(array_keys($old), array_keys($new))),
            'changed' => $changed,
        ];
    }

    private function author(User $actor): string
    {
        return (string) $actor->uuid;
    }

    /** @param  array<string, mixed>  $resource */
    private function auditEdit(User $actor, string $action, ?string $requestId, array $resource, ?int $from, ?int $to): void
    {
        // The diff stays in maps_versions: the audit row only says who changed what, and between which versions.
        $this->audit->record($action, AuditEvent::OUTCOME_SUCCESS, [
            'real_actor' => $actor,
            'effective_user' => $actor,
            'request_id' => $requestId,
            'resource_ids' => $resource,
            'metadata' => array_filter(['from_version' => $from, 'to_version' => $to], fn ($v) => $v !== null),
        ]);
    }
}
