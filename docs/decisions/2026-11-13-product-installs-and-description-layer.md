# 2026-11-13: Product installs and the description layer

Status: accepted (product owner, 2026-11-13)

Pane and Latte are the REST API and control panel for every app the product
owner builds (Sweegly first). Both stay one generic codebase. Each app and
environment gets its own install, and everything an app has (tables, views,
menu, functions, settings) is described as maps in Pane's database, edited in
Latte, and kept separate from the real database structure. The architecture is
in [architecture.md](../architecture.md#target-product-installs-and-the-description-layer).

## Questions and answers

| # | Question | Answer |
| --- | --- | --- |
| 1 | Who defines the structure of the data Pane serves? | Maps in Pane describe what is exposed; the real structure is managed separately (see 29-31). A source is the install's own database or, later, an external one. Endpoints behave the same on both. *(Revised by 29-30: Pane does not create tables from maps.)* |
| 2 | What makes a universe (one install)? | One install per product **and** environment, e.g. Sweegly dev, Sweegly stage, Sweegly prod. |
| 3 | What is an organization for inside a product install? | Usually a single organization: the product owner, which owns the data sources. End users are its members, and row ownership keeps their data apart. |
| 4 | Where are row access rules set? | Per table, in the descriptions, chosen from a fixed set of policies (owner-private, shared read-only, shared read/write, admins only), plus admin-defined row conditions. |
| 5 | How do frontends run complex queries (no SQL from frontends)? | Both: named queries described in Pane (tables, joins, filters, aggregates, typed parameters) called by name, plus a limited structured client query language for everyday filtering, sorting and simple joins. Access rules always apply. |
| 6 | Which model becomes the single description layer? | A new unified, versioned description layer built from the catalog work. Legacy `map_*` data and the `/{story}/{subject}` endpoints move onto it and are then retired (#74). |
| 7 | How are descriptions authored and promoted dev to stage to prod? | ~~Versioned files in a product repo applied by each deploy.~~ Superseded by 27-28: maps live in the database, are edited in Latte, and move between installs as exported bundles. |
| 8 | Where do the app's data tables live? | In the install's single database, next to Pane's system tables, under a separate prefix. The app's own migrations create them (30). |
| 9 | How are changes that could lose data handled? | ~~Additive auto, destructive needs an explicit migration step in the files.~~ Superseded by 29-30: maps never change tables; each app's own migrations do. |
| 10 | Which kind of data source is built first? | The install's own app tables first. The external track (#68 egress, #72 query layer, #73 CRUD) waits until a product needs it. |
| 11 | How do frontends refer to tables and queries? | By the stable names from the maps (e.g. `/data/{source}/tables/shopping_lists/rows`), identical on every install, not per-install UUIDs. *(Revised by 27-28: names come from the maps in Pane's database, which bundles carry between installs; originally "from the description files".)* |
| 12 | Where does product logic beyond CRUD and queries live? | Declaratively in the maps, using a fixed set of generic building blocks: computed fields, defaults, validations and "on create/update, do X" actions such as sending a message template, setting a field or calling a webhook. No product code inside Pane. *(Revised by 27-28: originally "in the files"; the only repo files are exported bundles and app migrations.)* |
| 13 | How do admin consoles (Latte, Burro) connect to installs? | One deployed console per install. Installs and consoles stay fully isolated. |
| 14 | How do installs map to WorkOS? | One WorkOS environment per install (product and environment). |
| 15 | Which repo holds an app's files? | A new repo per app (e.g. `erme2/sweegly-data`), holding the app's migrations and exported map bundles, with version tags. The maps themselves live in Pane's database (27-28). |
| 16 | What happens to work in flight on external sources? | ~~PR #151 held as a draft.~~ Revised by 31. #68, #72 and #73 stay parked. |
| 17 | Do end users share private data with each other? | Yes, through a generic **groups** building block: users join groups (household, team, ...), rows can belong to a group, and access rules can say "members of the row's group". |
| 18 | Which format do exported files use? | YAML checked against a published JSON Schema, now for exported bundles (28). |
| 19 | How does a deploy apply the files? | ~~An artisan apply command, no HTTP surface.~~ Superseded by 27-30: the deploy runs the app's migrations; maps are edited in Latte and moved by bundle import with a dry-run diff. |
| 20 | What happens next? | Update `architecture.md` and `api-v1.md` around these decisions in a PR. After approval, file the board-14 tickets for the new track. |
| 21 | What are Pane and Latte for? | The REST API (Pane) and control panel (Latte) for every app the owner builds. Latte first gets list and edit views for records built from the maps, then advanced features based on what the frontends need. |
| 22 | Where do view details (labels, list columns, widgets, layout, filters) live? | In an optional `ui` part of each table's map, served by Pane to Latte, so standard screens need no Latte code. |
| 23 | Build Latte views on today's `map_*` tables or on the new layer? | On the new maps, seeded from the current `map_*` content; views are built once. |
| 24 | How are app settings (feature switches, logos, texts) defined? | Typed keys declared in the maps (type, default, who may edit); values edited per install in Latte; public ones served to the app. |
| 25 | Where are uploads stored? | S3-compatible object storage from day one; Pane keeps metadata only. |
| 26 | Several languages? | One language per install for now; the format leaves room for translations. |
| 27 | Is Latte one codebase or forked per app? | One repo, many installs. Latte's menu, views and functions come from Pane's database. Generic functions (e.g. a calendar) work in any app and can be switched off per install. Only mapped tables and fields are visible: a table the maps ignore doesn't exist for Pane or Latte. |
| 28 | Who edits maps, menu, views and config, and how do changes move? | They live in the database and are edited in Latte. The editors for maps, menu and config are always available. System maps and tables are protected: an app's product maps can be edited (and broken), the system ones never. Changes move between installs as versioned YAML bundles exported from Latte; an import shows a dry-run diff first. |
| 29 | What does editing a map do to the real table? | Nothing. Maps are separate from the real structure: the SQL can change first and the map later, when ready. Unmapped changes stay invisible. |
| 30 | Who creates and changes the real tables? | Each app repo's versioned migrations, run by the deploy. Pane then discovers the real structure and reports what is unmapped, missing or changed; admins map what they want in Latte. |
| 31 | What happens to PR #151 (catalog discovery)? | Reshape and reuse it: scan the install's own app prefix (never Pane's system tables) as well as external sources, and feed the maps-versus-database report. It stays a draft until the maps design is filed. |
| 32 | Which maps are system maps, protected from Latte editing? | Everything Pane owns: the maps of every Pane system table, meaning every table under the system prefix (users, organizations, memberships, invitations, applications, settings storage, audit, messages, connections, credentials, and any future Pane table) and the maps of the maps themselves. Only app tables (the app prefix, 34) are editable. |
| 33 | How are edits to maps, menu, views and settings versioned? | Full history with rollback: every save creates a new version recording who, when and a diff. Latte shows a map's history and can restore an earlier version, which saves as a new version. |
| 34 | Which prefix do an app's own tables use? | A per-install setting (e.g. `APP_TABLE_PREFIX=sweegly_`). Discovery scans only that prefix, and Pane refuses to start when it overlaps the system prefix (`DB_TABLE_PREFIX`, default `pane_`). |
| 35 | Which S3-compatible storage do uploads use? | Cloudflare R2, one bucket and one set of access keys per install (e.g. sweegly-dev), kept in that install's deploy secrets. Pane talks to it through the S3 API, so another provider stays possible. |
| 36 | Which generic Latte functions ship first? | Besides the always-on editors (maps, menu, settings) and the record list and edit views: members and invitations, and a dashboard (counts and recent records, configured in the maps). Each can be switched off per install. The calendar and the audit viewer come later. |

## Map model (Pane#153)

Decided by the product owner on 2026-11-13, on the questions raised in
[Pane#153](https://github.com/erme2/Pane/issues/153#issuecomment-6866150444):

| # | Question | Answer |
|---|----------|--------|
| 37 | Relational tables or one JSON document per map? | Relational rows for sources, tables and fields; `ui`, validations, defaults and computed expressions as JSON columns on their row. |
| 38 | How fine-grained is versioning? | One version per table map (its fields and `ui` included) holding the full snapshot, the diff against the previous version, the author and the time. Restore saves the old snapshot as a new version. Sources are versioned the same way. |
| 39 | What are the new tables called? | `maps_sources`, `maps_tables`, `maps_fields`, `maps_versions` under `DB_TABLE_PREFIX`, so they are system tables and don't clash with the legacy `map_*` tables. |
| 40 | Which field types and validations? | The legacy lists (number, string, text, boolean, date, array, password, json; unique, exists, max, min, email), stored by name. More types come with the tickets that need them. |
| 41 | What is a computed field in the first version? | Stored only (a type plus an opaque JSON expression). The read and CRUD tickets evaluate it. |
| 42 | Is the system flag stored or worked out? | Worked out from the `sql_name` and `DB_TABLE_PREFIX` on every save, so a stale flag can't unprotect a table. The model layer refuses any change to a system map. |
| 43 | What counts as overlapping prefixes? | Either prefix being a prefix of the other fails at boot. `APP_TABLE_PREFIX` is optional for now (empty means no app tables yet). |
| 44 | How are the legacy maps imported? | A re-runnable data migration copies every legacy table and field (with type and validations) as version 1 by "system import". The legacy tables stay until #74. |
| 45 | What does the first ticket leave out? | Menu, functions, settings, policies and the bundle JSON Schema stay with their own tickets. |
| 46 | What about installs whose Pane tables have no prefix? (2026-11-14, Pane#164) | Solved once: a migration moves them under `DB_TABLE_PREFIX` (the sweegly dev install moves to `pane_`), and `APP_TABLE_PREFIX` needs a non-empty `DB_TABLE_PREFIX`. Pane applies the prefix itself, never through the database connection. |

## Facts checked while deciding

- The Sweegly app and Latte call only Pane's session and sign-in endpoints
  (`/api/v1/session`, `/csrf-cookie`, `/auth/*`); neither uses the legacy
  `/{story}/{subject}` data API, so retiring it needs no frontend migration.
- erme2/deploy already runs one Pane install per environment
  (`sweegly-pane-dev`, ...).

## Still open

These need their own decisions before or during implementation:

- the map model for menu, functions, settings, policies, row conditions,
  named queries, the client query language and actions, and the bundle JSON
  Schema (the model for sources, tables, fields and `ui` is settled in 37-45);
- the limits of the client query language (joins depth, aggregates, paging);
- the action building blocks in the first version, and whether webhooks need
  signing and retries;
- group rules: who creates groups, invitations to groups, leaving, and what
  happens to a group's rows;
- how the shared organization-level roles (administrator, user) combine with
  group membership in policies;
- how an install shows which maps version it runs
  (for example in `/status`);
- the order of the later generic Latte functions (calendar, audit viewer, ...).
