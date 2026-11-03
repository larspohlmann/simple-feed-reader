# Remove share targets from the share menu (#2006) — design

Brainstormed 2026-11-03; Lars took every recommendation.

## What

A user can remove share targets they never use from the article share menu and bring them back, or turn
the share feature off entirely. Both choices belong to the account: they follow the user to every device
and travel in the backup.

## Decisions

1. **Per account.** Stored on `user_preferences`, not in `localStorage`. `localStorage` is only a paint
   cache, as for the magazine style.
2. **Managed in Settings.** A "Sharing" group under Settings › Preferences: a master switch, then one
   switch per target showing the target's icon and label. On means shown. The share menu itself gets no
   edit mode.
3. **Master switch.** Turning sharing off removes the share feature everywhere: no share button in the
   article, no share menu, and selecting text offers nothing (no desktop bubble, no phone bar). It is a flag
   of its own, separate from the target list. While it is off the per-target switches stay visible but
   disabled, and turning it back on restores the user's previous selection.
4. **All targets removed has the same effect.** With every target hidden, the feature disappears just as
   with the master switch off. Re-enabling any target brings it back. Settings stays the way back in both
   cases.
5. **The "Post as @handle" entries have no switch of their own.** They live in the share menu and the
   selection bubble, so they disappear when the feature does (decisions 3 and 4), even for an account with
   linked networks. Otherwise they stay; linking the account was already that choice.
6. **Stable ids.** Every entry of `share-targets.json` except dividers gets an `id` (`system`, `copy`,
   `email`, `bluesky`, `threads`, `x`, `facebook`, `linkedin`, `reddit`, `whatsapp`, `telegram`,
   `instapaper`). Menu items are keyed by array index today, which would point a stored choice at a
   different target after any reorder. A spec checks the ids are unique and well-formed.
7. **The config stays the one list.** The server checks only the id format (`^[a-z0-9-]{1,32}$`), at most
   64 ids, duplicates dropped. It does not check ids against the targets. Adding a target, or a self-hoster
   editing `share-targets.json`, needs no backend change. A stored id that matches no target is
   never matched and stays harmless.
8. **Unknown ids survive a write.** A toggle sends the stored list with one id added or removed, so it
   keeps ids for targets this device does not list (the system sheet on a desktop browser, a target a newer
   config dropped).
9. **Dividers collapse.** After hidden targets are dropped, the menu never starts or ends on a divider and
   never shows two in a row. With every fixed target hidden but linked accounts present, the feature is off
   (decision 4), so the post entries never stand alone.
10. **Restore over reject.** A backup line without the fields restores sharing on with no hidden targets;
    ids with a bad format are dropped rather than failing the restore, as `AccountLine` does for an
    unknown magazine style.

## Shape

- **Backend**
  - `Preferences::$sharingEnabled` (bool column `sharing_enabled`, default `true`) and
    `Preferences::$hiddenShareTargets` (JSON column `hidden_share_targets`, default `[]`, `list<string>`).
    One migration for SQLite and MySQL.
  - `PATCH /api/me/sharing` with `{ "enabled": bool, "hiddenTargets": [...] }`, its own route for the reason
    `updatePreferences()` gives. The two fields describe one settings group and the client always holds
    both, so one request carries both. `UpdateSharingRequest` validates format and count, so a bad id is a
    422 problem. `AccountPreferencesWriter::changeSharing()` stores the flag and the deduplicated list,
    handed over as one `SharingSettings` value. The response is the profile, like the other `/api/me`
    writes.
  - `MeJson` exposes `preferences.sharing: { enabled, hiddenTargets }`.
  - Backup: `BackupLines` writes `sharingEnabled` and `hiddenShareTargets`; `AccountLine` reads them with
    defaults `true` and `[]` and filters ids by format; `RestoreLoadPass` sets them.
    `BackupSchemaCoverageTest`, `BackupFieldDeclarations` and `FullyPopulatedAccount` gain the fields.
  - Account wipe needs nothing: the columns sit on the row the user cascade already deletes.
- **Frontend**
  - `share-targets.json` gains the ids; `ShareTarget` types `id: string` on non-divider entries.
  - `SharingService` (`core/preferences/`), shaped like `MagazineStyleService`: `enabled` and `hidden`
    signals backed by a `localStorage` paint cache, `adopt(user)` on every `loadMe()`, `reset()` on logout,
    `setEnabled(enabled)` and `setTargetShown(id, shown)` that apply locally then write through
    `SHARING_WRITER`, and `saveFailed`.
  - `SharingService.available`: a computed that is true only when `enabled` holds and at least one target
    in `SHARE_TARGETS` is not hidden. The share button, the selection bubble and the phone bar render only
    while it is true.
  - `shareMenuItems(networks, mode, hidden)` drops hidden targets and collapses dividers. Target item ids
    become the target's `id`.
  - Settings: a "Sharing" group in `preferences-section`: the master `app-toggle`, then `SHARE_TARGETS`
    (already filtered to what this browser supports) with an `app-toggle` each, disabled while sharing is
    off, plus an error banner on `saveFailed`.
  - Translations for the group title, the master switch and its hint, and the save failure, in every
    locale.
- **Tests**
  - PHPUnit: controller (flag and list stored and echoed, duplicates dropped, bad format 422, too many
    422), `AccountLine` (missing fields, bad ids filtered), backup round trip.
  - Jest: `share-menu.spec.ts` (hidden targets dropped, divider collapse cases); `share-targets.spec.ts`
    (ids unique and well-formed); the service (cache, adopt, reset, unknown ids kept, failed write,
    `available` false when off or when every target is hidden); share button and selection bubble absent
    while `available` is false; the settings group (target switches disabled while off).
  - Gates: `composer check`, `composer md`, both PHPUnit legs, `composer infection:diff`, the migration leg,
    `npm run check`.

## Out of scope

- Reordering targets, or adding custom ones per user.
- A separate switch for each "Post as @handle" entry.
- Instance-wide defaults chosen by an admin.
