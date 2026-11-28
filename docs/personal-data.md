# Personal data cleanup

When a user is anonymised (`DELETE /api/v1/users/{user_id}`, see [Anonymising a user](api-v1.md#anonymising-a-user)), Pane also cleans the **install's own data**: the tables an app's maps describe. Pane knows nothing about one app: each table map says whether it holds personal data and what to do with it, in an optional `personal_data` section. A map without the section is left alone.

## The `personal_data` section

```json
{
  "name": "price_reports",
  "sql_name": "sweegly_price_reports",
  "fields": [ ... ],
  "personal_data": {
    "owner": "reporter_membership_id",
    "action": "anonymise",
    "clear": ["photo_path"],
    "coarsen": ["reported_at"]
  }
}
```

| Key | Meaning |
| --- | --- |
| `owner` | Required. The field that holds the person's **user uuid**. Rows are matched where it equals the anonymised user's `uuid`. |
| `action` | Required. `delete`: the person's rows are deleted. `soft_delete`: the rows are marked deleted in `deleted_at`. `anonymise`: the rows stay and the owner becomes the placeholder uuid. |
| `deleted_at` | `soft_delete` only, required: a nullable date field of the table. Rows already deleted keep their date. |
| `cascade` | `soft_delete` only. A list of `{ "table", "field", "deleted_at" }`: the rows of that mapped table whose `field` holds the key of a row being soft deleted are marked deleted in its own `deleted_at` field (a shared list's items go with the list). |
| `clear` | Fields blanked on the rows that stay: `null` when the field is nullable, otherwise an empty string. Only nullable, string or text fields. Not with `delete`. |
| `coarsen` | Date fields cut to the day (`00:00:00`) on the rows that stay. Not with `delete`. |

The owner and primary key can't be cleared or coarsened. The rule is checked when the map is saved (`app:install` or the maps API) and again, fail-closed, when the cleanup runs. The section is server-side: the public map read doesn't show it.

Rows that stay carry one fixed placeholder, `00000000-0000-0000-0000-000000000000`, instead of the person's uuid, in every table, so they can't be grouped back to one person.

## What runs

For each map of the `install` source that has a `personal_data` section, in one database transaction (any failure rolls all of it back):

1. `coarsen` the date fields of the person's rows.
2. Apply the action, with `clear`.
3. After the transaction, delete the person from PostHog.

Every step matches nothing once it is done, so a second run changes nothing.

## PostHog

The apps identify with the user uuid, so the job asks PostHog to delete that person with their events and recordings (`POST <POSTHOG_HOST>/api/projects/@current/persons/bulk_delete/`).

- `POSTHOG_PRIVATE_KEY`: a PostHog personal API key, server-side only. Never in the repo, an app bundle, a log or an error message.
- `POSTHOG_HOST`: for example `https://eu.posthog.com`.

If either is unset the job skips PostHog and says so (`posthog: skipped` in the command output), so tests and dev installs work without them. The key needs the permission to delete persons.

## Failure and retry

The cleanup runs right after the user is anonymised. If it fails, the user stays anonymised and the request still succeeds, because the user can no longer sign in to retry. The failure is logged, and recorded as the audit event `user.personal_data_cleanup` with outcome `failure` (success is recorded too). A PostHog answer of `202` with a non-empty `deletion_errors` also counts as a failure, not a success. The failure carries no provider text, no user id and no chained database exception, so nothing in the logs points back at the person. Repeat it with:

```bash
php artisan personal-data:clean <uuid>
```

or by anonymising the user again (an administrator, `DELETE /api/v1/users/{user_id}`). Both are safe to repeat.

## Open points

- The clean-up of **backups** is not part of this job. The retention period of backups that still hold an anonymised user's old rows is to be stated for the privacy policy once the hosting backup policy is fixed.
- Consent choices that an app stores in its own tables are cleared by marking those columns in `clear` or the table `delete`. Pane's own `users.settings` is already emptied when the user is anonymised.
