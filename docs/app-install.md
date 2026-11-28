# Installing an app's data folder

`php artisan app:install <path>` runs an app's data folder against this install: first its pending migrations, then its maps. The deploy runs it; an HTTP endpoint may come later.

```bash
APP_TABLE_PREFIX=sweegly_ php artisan app:install ../sweegly/data
php artisan app:install ../sweegly/data --status   # ran and pending migrations, changes nothing
```

## Folder layout

```
<data folder>/
  migrations/20261117100001_create_lists.sql   # required, at least one file
  maps/sweegly.json                            # optional, any number of *.json files
```

## Migrations

- Files are dbmate-style SQL: the SQL after `-- migrate:up` runs; the `-- migrate:down` part is ignored (no rollback in the MVP).
- The version is the number before the first `_` in the file name; files run in file-name order and each version runs once.
- `{{prefix}}` in the SQL is replaced with `APP_TABLE_PREFIX`.
- Ran versions are kept in `<prefix>schema_migrations` (`version` varchar, the same layout dbmate uses).
- Only pending files run, so a second run changes nothing and a new file runs alone.
- A migration that fails stops the run; the files before it stay recorded as ran.

`APP_TABLE_PREFIX` must be set, use lowercase letters, digits and `_`, end with `_`, and must not overlap `DB_TABLE_PREFIX` (so never `pane_`).

No table is created or altered outside the migration files: the command itself only writes the `schema_migrations` table and Pane's maps tables.

## Maps file

```json
{
  "source": "install",
  "tables": [
    {
      "name": "lists",
      "sql_name": "sweegly_lists",
      "description": "Shopping lists",
      "ui": { "label": "Lists" },
      "fields": [
        { "name": "id", "type": "number", "primary": true },
        { "name": "name", "type": "string", "validations": [{ "rule": "max", "value": 100 }] }
      ]
    }
  ]
}
```

- `source` is optional and can only be `install`.
- Each table is a table map in the format of the maps API (`name`, `sql_name`, `description`, `ui`, `access`, `queries`, an optional `personal_data` section (what happens to a table's rows when a user is anonymised, see [Personal data cleanup](personal-data.md)), and `fields` with `name`, `sql_name`, `type`, `description`, `format`, `primary`, `index`, `sortable`, `nullable`, `default`, `validations`, `computed`, `ui`), checked with the same rules as a map saved through `MapStore`.
- The load is idempotent: a new map is created, a changed map is saved as a new version (author `app:install`), and a map that already matches is left alone.
- System maps are refused: a `sql_name` under `DB_TABLE_PREFIX` or one of the maps' own tables. A `sql_name` outside `APP_TABLE_PREFIX` is refused too.
- Every maps file is read and checked before the first migration runs, so an invalid maps file changes nothing.
