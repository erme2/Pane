<?php

namespace App\Services;

use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\Maps\MapNotFoundException;
use App\Exceptions\Maps\MapVersionConflictException;
use App\Exceptions\Maps\SettingNotEditableException;
use App\Models\AuditEvent;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Support\PaneTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * App settings declared in the maps (Pane#158, decisions 24, 26 and 35).
 *
 * Declarations (`maps_settings`) are replaced as a whole list and versioned in
 * `maps_versions` as the subject `settings`; values (`maps_setting_values`)
 * are one per key for the whole install, each with its own version for
 * `If-Match`, and every change is audited. Pane's own code-registered
 * settings (SettingsService) are a separate thing and stay as they are.
 * Images are uploaded to the `r2` disk (Cloudflare R2 over the S3 API); the
 * value keeps the object's path, url, mime and size. Old objects are never
 * deleted, so restoring an earlier value still works.
 */
class AppSettings
{
    public const string SUBJECT = 'settings';

    public const string DISK = 'r2';

    public const int MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> mime => extension; SVG is refused because it can carry scripts. */
    public const array IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public const array TYPES = ['flag', 'text', 'number', 'image'];

    public const array EDITABLE_BY = ['administrator', 'pane_admin'];

    private const string KEY = '/\A[a-z][a-z0-9_.]{0,63}\z/';

    private const int MAX_SETTINGS = 200;

    private const int MAX_TEXT = 2000;

    public function __construct(private readonly AuditEventService $audit) {}

    /**
     * Replaces the whole list of declarations. Values of keys that are no longer declared are kept
     * but not served.
     *
     * @return int the new version of the list
     *
     * @throws InvalidMapException
     */
    public function declare(mixed $items, string $author, ?User $actor = null, ?string $requestId = null): int
    {
        $normalized = $this->normalize($items);

        return DB::transaction(function () use ($normalized, $author, $actor, $requestId): int {
            $table = PaneTable::name(PaneTable::MAPS_SETTINGS);
            $rows = DB::table($table)->lockForUpdate()->get();
            // The version comes from the history, so an empty list does not lose the counter.
            $current = (int) MapVersion::query()->where('subject_type', self::SUBJECT)->where('subject_id', 0)->lockForUpdate()->max('version');
            $this->assertStoredValuesFit($normalized);
            $before = $this->declarationsFrom($rows->sortBy('position')->values()->all());

            $now = now();
            DB::table($table)->delete();
            foreach ($normalized as $position => $item) {
                DB::table($table)->insert([
                    'key' => $item['key'], 'type' => $item['type'],
                    'default' => json_encode($item['default'], JSON_THROW_ON_ERROR),
                    'public' => $item['public'], 'editable_by' => $item['editable_by'],
                    'label' => $item['label'], 'help' => $item['help'],
                    'position' => $position, 'version' => $current + 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            MapVersion::query()->create([
                'subject_type' => self::SUBJECT,
                'subject_id' => 0,
                'version' => $current + 1,
                'snapshot' => ['settings' => $normalized],
                'diff' => $this->diff($before, $normalized),
                'author' => $author,
            ]);
            $this->auditEvent($actor, 'settings.declare', $requestId, ['subject' => self::SUBJECT], ['from_version' => $current, 'to_version' => $current + 1]);

            return $current + 1;
        });
    }

    /**
     * Refuses a declaration whose type no longer fits the value already stored for its key
     * (including a key that was removed and is declared again).
     *
     * @param  list<array<string, mixed>>  $normalized
     *
     * @throws InvalidMapException
     */
    private function assertStoredValuesFit(array $normalized): void
    {
        $values = DB::table(PaneTable::name(PaneTable::MAPS_SETTING_VALUES))->get()->keyBy('key');
        $errors = [];
        foreach ($normalized as $i => $item) {
            $row = $values->get($item['key']);
            if ($row !== null && ! $this->fitsType($item['type'], $this->decode($row->value))) {
                $errors["settings.$i.type"] = "The stored value of \"{$item['key']}\" does not fit the type \"{$item['type']}\".";
            }
        }
        if ($errors !== []) {
            throw new InvalidMapException($errors);
        }
    }

    private function fitsType(string $type, mixed $value): bool
    {
        return match ($type) {
            'flag' => is_bool($value),
            'text' => is_string($value),
            'number' => is_int($value) || is_float($value),
            default => $value === null || (is_array($value) && isset($value['path'], $value['url'], $value['mime'], $value['size'])),
        };
    }

    /** @return list<array<string, mixed>> */
    public function declarations(): array
    {
        return $this->declarationsFrom(DB::table(PaneTable::name(PaneTable::MAPS_SETTINGS))->orderBy('position')->get()->all());
    }

    /**
     * Every declared setting with its current value, for administrators.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (array $declaration): array => $this->describe($declaration, $this->valueRow($declaration['key'])), $this->declarations());
    }

    /**
     * One declared setting with its value.
     *
     * @return array<string, mixed>
     *
     * @throws MapNotFoundException
     */
    public function find(string $key): array
    {
        return $this->describe($this->declaration($key), $this->valueRow($key));
    }

    /**
     * The public settings, as the maps bootstrap serves them to every signed-in user.
     *
     * @return list<array{key: string, type: string, value: mixed}>
     */
    public function publicSettings(): array
    {
        $values = DB::table(PaneTable::name(PaneTable::MAPS_SETTING_VALUES))->get()->keyBy('key');
        $public = [];
        foreach ($this->declarations() as $declaration) {
            if (! $declaration['public']) {
                continue;
            }
            $row = $values->get($declaration['key']);
            $public[] = [
                'key' => $declaration['key'],
                'type' => $declaration['type'],
                'value' => $row === null ? $declaration['default'] : $this->decode($row->value),
            ];
        }

        return $public;
    }

    /** The version of a value; 0 while it still has its default. */
    public function valueVersion(string $key): int
    {
        return (int) ($this->valueRow($key)->version ?? 0);
    }

    /**
     * Sets a value. Images are set through upload(); here an image key can only be cleared (null).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidMapException
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     * @throws SettingNotEditableException
     */
    public function set(User $actor, string $key, mixed $value, int $expectedVersion, ?string $requestId): array
    {
        $declaration = $this->declaration($key);
        $this->assertEditable($actor, $declaration);
        $clean = $this->validate($declaration, $value);

        return $this->write($actor, $declaration, $clean, $expectedVersion, $requestId);
    }

    /**
     * Stores an image on the `r2` disk and sets the value to its metadata.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidMapException
     * @throws MapNotFoundException
     * @throws MapVersionConflictException
     * @throws SettingNotEditableException
     */
    public function upload(User $actor, string $key, UploadedFile $file, int $expectedVersion, ?string $requestId): array
    {
        $declaration = $this->declaration($key);
        $this->assertEditable($actor, $declaration);
        if ($declaration['type'] !== 'image') {
            throw new InvalidMapException(['key' => 'Only image settings accept an upload.']);
        }
        if ($this->valueVersion($key) !== $expectedVersion) {
            throw new MapVersionConflictException($expectedVersion, $this->valueVersion($key));
        }
        if (! $file->isValid()) {
            throw new InvalidMapException(['file' => 'The upload failed.']);
        }
        if ($file->getSize() === false || $file->getSize() > self::MAX_IMAGE_BYTES) {
            throw new InvalidMapException(['file' => 'At most '.(self::MAX_IMAGE_BYTES / 1024 / 1024).' MB.']);
        }
        // The type is read from the file's content, never from the name or the client's header.
        $realPath = $file->getRealPath();
        $mime = $realPath === false ? '' : (string) mime_content_type($realPath);
        if (! isset(self::IMAGE_TYPES[$mime])) {
            throw new InvalidMapException(['file' => 'Must be a PNG, JPEG or WebP image.']);
        }

        $path = Storage::disk(self::DISK)->putFileAs("settings/$key", $file, Str::uuid().'.'.self::IMAGE_TYPES[$mime]);
        if (! is_string($path)) {
            throw new InvalidMapException(['file' => 'The image could not be stored.']);
        }

        return $this->write($actor, $declaration, [
            'path' => $path,
            'url' => Storage::disk(self::DISK)->url($path),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ], $expectedVersion, $requestId);
    }

    /** @param  array<string, mixed>  $declaration */
    private function assertEditable(User $actor, array $declaration): void
    {
        // Both levels resolve to the install's administrator until organizations come back.
        if (! $actor->isPaneAdministrator()) {
            throw SettingNotEditableException::forKey($declaration['key'], $declaration['editable_by']);
        }
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @return array<string, mixed>
     */
    private function write(User $actor, array $declaration, mixed $value, int $expectedVersion, ?string $requestId): array
    {
        $key = $declaration['key'];

        DB::transaction(function () use ($actor, $key, $value, $expectedVersion, $requestId): void {
            $table = PaneTable::name(PaneTable::MAPS_SETTING_VALUES);
            $row = DB::table($table)->where('key', $key)->lockForUpdate()->first();
            $current = (int) ($row->version ?? 0);
            if ($current !== $expectedVersion) {
                throw new MapVersionConflictException($expectedVersion, $current);
            }
            $payload = ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'version' => $current + 1, 'updated_by' => (string) $actor->uuid, 'updated_at' => now()];
            if ($row === null) {
                DB::table($table)->insert([...$payload, 'key' => $key, 'created_at' => now()]);
            } else {
                DB::table($table)->where('key', $key)->update($payload);
            }
            $this->auditEvent($actor, 'settings.value.set', $requestId, ['setting' => $key], ['from_version' => $current, 'to_version' => $current + 1]);
        });

        return $this->find($key);
    }

    /**
     * @param  array<string, mixed>  $declaration
     *
     * @throws InvalidMapException
     */
    private function validate(array $declaration, mixed $value): mixed
    {
        $valid = match ($declaration['type']) {
            'flag' => is_bool($value),
            'text' => is_string($value) && strlen($value) <= self::MAX_TEXT,
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            default => $value === null,
        };
        if (! $valid) {
            throw new InvalidMapException(['value' => match ($declaration['type']) {
                'flag' => 'Must be true or false.',
                'text' => 'Must be a text of at most '.self::MAX_TEXT.' characters.',
                'number' => 'Must be a number.',
                default => 'An image is set by upload; here it can only be cleared with null.',
            }]);
        }

        return $value;
    }

    /**
     * @return list<array{key: string, type: string, default: mixed, public: bool, editable_by: string, label: string|null, help: string|null}>
     *
     * @throws InvalidMapException
     */
    private function normalize(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::MAX_SETTINGS) {
            throw new InvalidMapException(['settings' => 'A list of 0 to '.self::MAX_SETTINGS.' settings.']);
        }

        $errors = [];
        $normalized = [];
        $seen = [];
        foreach ($items as $i => $item) {
            $path = "settings.$i";
            if (! is_array($item)) {
                $errors[$path] = 'Must be an object.';

                continue;
            }
            $key = $item['key'] ?? null;
            if (! is_string($key) || ! preg_match(self::KEY, $key)) {
                $errors["$path.key"] = 'Use 1-64 lowercase letters, digits, dots or underscores, starting with a letter.';
            } elseif (isset($seen[$key])) {
                $errors["$path.key"] = 'Duplicate key.';
            } else {
                $seen[$key] = true;
            }
            $type = $item['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                $errors["$path.type"] = 'One of: '.implode(', ', self::TYPES).'.';
            }
            $editableBy = $item['editable_by'] ?? 'administrator';
            if (! is_string($editableBy) || ! in_array($editableBy, self::EDITABLE_BY, true)) {
                $errors["$path.editable_by"] = 'One of: '.implode(', ', self::EDITABLE_BY).'.';
            }
            $public = $item['public'] ?? false;
            if (! is_bool($public)) {
                $errors["$path.public"] = 'Must be true or false.';
            }
            foreach (['label' => 255, 'help' => 1000] as $field => $max) {
                if (isset($item[$field]) && (! is_string($item[$field]) || strlen($item[$field]) > $max)) {
                    $errors["$path.$field"] = "A text of at most $max characters, or null.";
                }
            }
            $default = $item['default'] ?? null;
            if (is_string($type) && in_array($type, self::TYPES, true) && $default !== null) {
                try {
                    $this->validate(['type' => $type], $default);
                } catch (InvalidMapException) {
                    $errors["$path.default"] = $type === 'image' ? 'An image has no default.' : "Does not fit the type \"$type\".";
                }
            }

            if ($errors === []) {
                $normalized[] = [
                    'key' => $key, 'type' => $type, 'default' => $default, 'public' => $public,
                    'editable_by' => $editableBy, 'label' => $item['label'] ?? null, 'help' => $item['help'] ?? null,
                ];
            }
        }
        if ($errors !== []) {
            throw new InvalidMapException($errors);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MapNotFoundException
     */
    private function declaration(string $key): array
    {
        $row = DB::table(PaneTable::name(PaneTable::MAPS_SETTINGS))->where('key', $key)->first();
        if ($row === null) {
            throw new MapNotFoundException("The setting \"$key\" is not declared.");
        }

        return $this->declarationsFrom([$row])[0];
    }

    private function valueRow(string $key): ?object
    {
        return DB::table(PaneTable::name(PaneTable::MAPS_SETTING_VALUES))->where('key', $key)->first();
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function declarationsFrom(array $rows): array
    {
        return array_map(fn (object $row): array => [
            'key' => $row->key,
            'type' => $row->type,
            'default' => $this->decode($row->default),
            'public' => (bool) $row->public,
            'editable_by' => $row->editable_by,
            'label' => $row->label,
            'help' => $row->help,
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @return array<string, mixed>
     */
    private function describe(array $declaration, ?object $row): array
    {
        return [
            ...$declaration,
            'value' => $row === null ? $declaration['default'] : $this->decode($row->value),
            'version' => (int) ($row->version ?? 0),
        ];
    }

    private function decode(mixed $json): mixed
    {
        return is_string($json) ? json_decode($json, true) : $json;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function diff(array $before, array $after): array
    {
        $old = array_column($before, null, 'key');
        $new = array_column($after, null, 'key');
        $changed = [];
        foreach ($new as $key => $item) {
            if (isset($old[$key]) && $old[$key] !== $item) {
                $changed[] = $key;
            }
        }

        return [
            'added' => array_values(array_diff(array_keys($new), array_keys($old))),
            'removed' => array_values(array_diff(array_keys($old), array_keys($new))),
            'changed' => $changed,
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, int>  $metadata
     */
    private function auditEvent(?User $actor, string $action, ?string $requestId, array $resource, array $metadata): void
    {
        // Values stay out of the audit row (they can be long); it says who changed what, between which versions.
        $this->audit->record($action, AuditEvent::OUTCOME_SUCCESS, [
            'real_actor' => $actor,
            'effective_user' => $actor,
            'request_id' => $requestId,
            'resource_ids' => $resource,
            'metadata' => $metadata,
        ]);
    }
}
