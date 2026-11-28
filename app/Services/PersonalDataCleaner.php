<?php

namespace App\Services;

use App\Exceptions\PersonalDataCleanupException;
use App\Models\Maps\MapSource;
use App\Models\Maps\MapTable;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cleans the install's own data of an anonymised user (sweegly#49). Which tables
 * and columns hold personal data comes from the `personal_data` rule of each
 * table map (MapPersonalDataRules), so the job knows nothing about one app.
 *
 * Every table is cleaned in one transaction, so a failure leaves the data as it
 * was and a retry starts clean. Each step also matches nothing once done, so a
 * second run changes nothing. The PostHog person is removed after the database
 * step and is safe to repeat. Rows that stay carry PLACEHOLDER instead of the
 * person's uuid, the same value in every table, so they cannot be grouped back
 * to one person.
 */
class PersonalDataCleaner
{
    public const string PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

    private const string SOURCE = 'install';

    private const int CHUNK = 200;

    public function __construct(private readonly PostHogService $postHog) {}

    /**
     * @return array{tables: array<string, int>, posthog: string}
     *
     * @throws PersonalDataCleanupException
     */
    public function clean(User $user): array
    {
        $uuid = strtolower((string) $user->uuid);
        if ($uuid === '' || ! Str::isUuid($uuid) || $uuid === self::PLACEHOLDER) {
            throw new PersonalDataCleanupException('The user has no uuid to clean up after.');
        }

        try {
            $tables = DB::transaction(fn (): array => $this->cleanTables($uuid));
        } catch (PersonalDataCleanupException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // The database message can echo row values.
            throw new PersonalDataCleanupException('The personal data could not be cleaned. Nothing was changed; try again.', 0, $exception);
        }

        return ['tables' => $tables, 'posthog' => $this->deletePostHogPerson($uuid)];
    }

    /** @return array<string, int> rows touched per table map */
    private function cleanTables(string $uuid): array
    {
        $maps = MapTable::query()
            ->whereIn('map_source_id', MapSource::query()->where('name', self::SOURCE)->select('map_source_id'))
            ->whereNotNull('personal_data')
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($maps as $map) {
            /** @var array<string, mixed> $rule */
            $rule = $map->personal_data;
            $result[$map->name] = $this->cleanTable($map, $rule, $uuid);
        }

        return $result;
    }

    /** @param array<string, mixed> $rule */
    private function cleanTable(MapTable $map, array $rule, string $uuid): int
    {
        $owner = $this->column($map, (string) $rule['owner']);
        $own = fn (): Builder => $this->rows($map)->where($owner, $uuid);

        switch ($rule['action']) {
            case 'delete':
                return $own()->delete();

            case 'soft_delete':
                $deletedAt = $this->column($map, (string) $rule['deleted_at']);
                $this->softDeleteCascade($map, $rule, $owner, $deletedAt, $uuid);
                // Only rows not already deleted, so a retry does not move the deletion date.
                $count = $own()->whereNull($deletedAt)->update([
                    ...$this->blanks($map, $rule),
                    $deletedAt => now(),
                ]);
                // The person's id goes from rows that were already soft deleted too.
                $own()->update([$owner => self::PLACEHOLDER, ...$this->blanks($map, $rule)]);

                return $count;

            case 'anonymise':
                $this->coarsen($map, $rule, $own);

                return $own()->update([...$this->blanks($map, $rule), $owner => self::PLACEHOLDER]);
        }

        throw new PersonalDataCleanupException("The map \"{$map->name}\" has an unknown personal-data action.");
    }

    /**
     * Marks the rows of each cascade table that belong to a row about to be soft deleted.
     *
     * @param  array<string, mixed>  $rule
     */
    private function softDeleteCascade(MapTable $map, array $rule, string $owner, string $deletedAt, string $uuid): void
    {
        foreach ($rule['cascade'] ?? [] as $entry) {
            $child = MapTable::query()
                ->whereIn('map_source_id', MapSource::query()->where('name', self::SOURCE)->select('map_source_id'))
                ->where('name', $entry['table'])
                ->first();
            if ($child === null) {
                throw new PersonalDataCleanupException("The map \"{$map->name}\" cascades to \"{$entry['table']}\", which is not mapped.");
            }
            $primary = $this->primary($map);
            $parents = $this->rows($map)->where($owner, $uuid)->whereNull($deletedAt)->select($primary);

            $this->rows($child)
                ->whereIn($this->column($child, $entry['field']), $parents)
                ->whereNull($this->column($child, $entry['deleted_at']))
                ->update([$this->column($child, $entry['deleted_at']) => now()]);
        }
    }

    /**
     * Cuts each coarsen field to the day, one row at a time so it works on every database.
     *
     * @param  array<string, mixed>  $rule
     * @param  callable(): Builder  $own
     */
    private function coarsen(MapTable $map, array $rule, callable $own): void
    {
        $fields = array_map(fn (string $name): string => $this->column($map, $name), $rule['coarsen'] ?? []);
        if ($fields === []) {
            return;
        }
        $primary = $this->primary($map);

        $own()->orderBy($primary)->chunkById(self::CHUNK, function ($rows) use ($map, $fields, $primary): void {
            foreach ($rows as $row) {
                $changes = [];
                foreach ($fields as $field) {
                    $value = $row->{$field} ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $day = Carbon::parse((string) $value)->startOfDay();
                    if ($day->format('Y-m-d H:i:s') !== Carbon::parse((string) $value)->format('Y-m-d H:i:s')) {
                        $changes[$field] = $day;
                    }
                }
                if ($changes !== []) {
                    $this->rows($map)->where($primary, $row->{$primary})->update($changes);
                }
            }
        }, $primary);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function blanks(MapTable $map, array $rule): array
    {
        $blanks = [];
        $fields = $map->fields()->get()->keyBy('name');
        foreach ($rule['clear'] ?? [] as $name) {
            $field = $fields->get($name);
            if ($field === null) {
                throw new PersonalDataCleanupException("The map \"{$map->name}\" clears \"$name\", which is not one of its fields.");
            }
            $blanks[$field->sql_name ?? $field->name] = $field->nullable ? null : '';
        }

        return $blanks;
    }

    private function rows(MapTable $map): Builder
    {
        if (! filled($map->sql_name)) {
            throw new PersonalDataCleanupException("The map \"{$map->name}\" has personal data but no sql_name.");
        }

        return DB::table((string) $map->sql_name);
    }

    private function column(MapTable $map, string $field): string
    {
        $model = $map->fields()->where('name', $field)->first();
        if ($model === null) {
            throw new PersonalDataCleanupException("The map \"{$map->name}\" names \"$field\", which is not one of its fields.");
        }

        return (string) ($model->sql_name ?? $model->name);
    }

    private function primary(MapTable $map): string
    {
        $model = $map->fields()->where('primary', true)->first();
        if ($model === null) {
            throw new PersonalDataCleanupException("The map \"{$map->name}\" has no primary field.");
        }

        return (string) ($model->sql_name ?? $model->name);
    }

    /** @return 'deleted'|'skipped' */
    private function deletePostHogPerson(string $uuid): string
    {
        if (! $this->postHog->isConfigured()) {
            return 'skipped';
        }

        try {
            $this->postHog->deletePerson($uuid);
        } catch (HttpClientException) {
            // Never carry the provider's message or the key.
            throw new PersonalDataCleanupException('PostHog could not delete the person. The database was cleaned; try again.');
        }

        return 'deleted';
    }
}
