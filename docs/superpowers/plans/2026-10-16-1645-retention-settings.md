# #1645 — Social posts kept 7 days; both retention windows admin-configurable

**Goal:** The age pass of `EntryPruner` deletes social posts (entries of any feed with a
`network`) after 7 days instead of 90, and both windows (RSS 90, social 7) become admin
settings on their own section, `Settings → Admin → Retention`.

**Unchanged:** the per-feed floor of the newest 20 entries, favorite/kept protection, the
per-feed cap pass, the empty-run pass.

## Design

- Storage: a new single-row table `retention_settings` (`entry_retention_days` default 90,
  `social_retention_days` default 7), entity `App\Entity\RetentionSettings`, the same
  single-row pattern as `ProxyServerSettings`/`MailServerSettings`. No row = defaults. A
  separate table, not two columns on `instance_setting`: that endpoint is a full-replace
  PUT the admin-settings page builds from nine fields, and retention has nothing to do
  with registration or passkeys.
- Value: `App\Entity\RetentionWindows` (`entryDays`, `socialDays`), what the entity takes
  and returns, so it lives in `App\Entity` (architecture §8).
- `App\Service\Retention\RetentionSettings`: `current(): RetentionWindows`,
  `update(RetentionWindows)`; reads through
  `Service/Retention/StoredRetentionSettings/StoredRetentionSettingsInterface`, which
  `RetentionSettingsRepository` implements. No memo, so no `ResetInterface`.
- `EntryPruner` takes `RetentionSettings`, reads the windows once per `prune()`, runs the
  age pass for social feeds (`RetentionRepository::socialFeedIdsFetchedBefore`) with the
  social cutoff and for all feeds with the entry cutoff.
- HTTP: `GET/PUT /api/admin/retention` (`AdminRetentionController`), body
  `{entryRetentionDays, socialRetentionDays}`, each `Range(1, 3650)`, full replace;
  `App\Http\Admin\RetentionSettingsJson` shapes the response. `/api/admin` is already
  admin-only in `security.yaml`.
- Migration: platform-aware `CREATE TABLE retention_settings`, as `Version20260904120000`.
- Frontend: `settings/admin/retention/` — `RetentionSettingsService extends
  DraftSettingsService`, `RetentionSectionComponent` with two number fields behind the
  shared save bar and the saved toast; a `SETTINGS_SECTIONS` entry (`admin/retention`,
  icon `auto_delete`) and its admin-guarded lazy route; en/de strings.

## Tasks

1. **Pruner, social window** — tests in `EntryPrunerTest`: a social post past 7 days goes,
   one inside stays; an RSS entry past 7 days stays; floor and favorite still protect social
   posts. Add `socialFeedIdsFetchedBefore`, split the age pass.
2. **Settings storage** — entity, value, repository, interface, service, migration. Pruner
   test: stored windows (e.g. RSS 30, social 2) drive the cutoffs.
3. **Admin endpoint** — DTO, Json mapper, controller; `AdminRetentionControllerTest`: GET
   defaults, PUT stores and echoes, 422 on 0 / missing field, 403 for a non-admin.
4. **Frontend section** — service, component (+ spec: renders loaded values, Save PUTs the
   draft, Reset drops it), section entry, route, i18n.
5. **Gates** — `composer check`, `composer md`, `php bin/phpunit`, migrate-from-empty on
   SQLite, `npm run check` in the worktree.
