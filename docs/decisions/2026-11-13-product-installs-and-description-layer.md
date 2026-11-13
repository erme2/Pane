# 2026-11-13: Product installs and the description layer

Status: accepted (product owner, 2026-11-13)

Pane stays one generic codebase that serves many frontends and kinds of data.
Each product gets its own Pane installs, and everything a product needs (its
tables, access rules, queries and actions) is described in versioned files
rather than coded into Pane. The architecture is in
[architecture.md](../architecture.md#target-product-installs-and-the-description-layer).

## Questions and answers

| # | Question | Answer |
| --- | --- | --- |
| 1 | Who defines the structure of the data Pane serves? | Both models, per data source. One description layer in Pane: a source is either Pane-managed (Pane creates the tables from the descriptions) or external (structure discovered). Endpoints behave the same on both. |
| 2 | What makes a universe (one install)? | One install per product **and** environment, e.g. Sweegly dev, Sweegly stage, Sweegly prod. |
| 3 | What is an organization for inside a product install? | Usually a single organization: the product owner, which owns the data sources. End users are its members, and row ownership keeps their data apart. |
| 4 | Where are row access rules set? | Per table, in the descriptions, chosen from a fixed set of policies (owner-private, shared read-only, shared read/write, admins only), plus admin-defined row conditions. |
| 5 | How do frontends run complex queries (no SQL from frontends)? | Both: named queries described in Pane (tables, joins, filters, aggregates, typed parameters) called by name, plus a limited structured client query language for everyday filtering, sorting and simple joins. Access rules always apply. |
| 6 | Which model becomes the single description layer? | A new unified, versioned description layer built from the catalog work. Legacy `map_*` data and the `/{story}/{subject}` endpoints move onto it and are then retired (#74). |
| 7 | How are descriptions authored and promoted dev to stage to prod? | Versioned files in a product repo, reviewed in PRs and applied by each deploy. |
| 8 | Where do Pane-managed data tables live? | In the install's single database, next to Pane's system tables, under a separate prefix. |
| 9 | How are changes that could lose data handled? | New tables, columns and indexes are applied automatically. Destructive changes (drop, rename, narrowing a type) need an explicit, versioned migration step in the files, and the deploy fails without it. |
| 10 | Which kind of data source is built first? | Pane-managed sources first. The external track (#68 egress, #72 query layer, #73 CRUD) waits until a product needs it. |
| 11 | How do frontends refer to tables and queries? | By the stable names from the description files (e.g. `/data/{source}/tables/shopping_lists/rows`), identical on every install, not per-install UUIDs. |
| 12 | Where does product logic beyond CRUD and queries live? | Declaratively in the files, using a fixed set of generic building blocks: computed fields, defaults, validations and "on create/update, do X" actions such as sending a message template, setting a field or calling a webhook. No product code inside Pane. |
| 13 | How do admin consoles (Latte, Burro) connect to installs? | One deployed console per install. Installs and consoles stay fully isolated. |
| 14 | How do installs map to WorkOS? | One WorkOS environment per install (product and environment). |
| 15 | Which repo holds a product's description files? | A new repo per product (e.g. `erme2/sweegly-data`), shared by all its frontends, with version tags; deploy applies a tagged version to each install. |
| 16 | What happens to work in flight on external sources? | PR #151 (external catalog discovery, #70) stays open as a draft until the description layer is designed, so it can be reshaped to fit. #68, #72 and #73 are parked. |
| 17 | Do end users share private data with each other? | Yes, through a generic **groups** building block: users join groups (household, team, ...), rows can belong to a group, and access rules can say "members of the row's group". |
| 18 | Which format do the description files use? | YAML, one file per table, query or action, checked against a published JSON Schema in editors and CI. |
| 19 | How does a deploy apply the files? | An artisan command run by the deploy on the server (`php artisan pane:descriptions:apply`): validate, dry-run, migrate, then activate in one step. No new HTTP surface. |
| 20 | What happens next? | Update `architecture.md` and `api-v1.md` around these decisions in a PR. After approval, file the board-14 tickets for the new track. |

## Facts checked while deciding

- The Sweegly app and Latte call only Pane's session and sign-in endpoints
  (`/api/v1/session`, `/csrf-cookie`, `/auth/*`); neither uses the legacy
  `/{story}/{subject}` data API, so retiring it needs no frontend migration.
- erme2/deploy already runs one Pane install per environment
  (`sweegly-pane-dev`, ...).

## Still open

These need their own decisions before or during implementation:

- the exact JSON Schema for tables, policies, row conditions, named queries,
  the client query language and actions;
- the limits of the client query language (joins depth, aggregates, paging);
- the action building blocks in the first version, and whether webhooks need
  signing and retries;
- group rules: who creates groups, invitations to groups, leaving, and what
  happens to a group's rows;
- how the shared organization-level roles (administrator, user) combine with
  group membership in policies;
- the data-table prefix, and how the description version of an install is
  shown (for example in `/status`).
