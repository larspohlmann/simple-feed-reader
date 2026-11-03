# Remove share targets from the share menu (#2006) — design

Brainstormed 2026-11-03; Lars took every recommendation.

## What

A user can remove share targets they never use from the article share menu, and bring them back. The
choice belongs to the account: it follows the user to every device and travels in the backup.

## Decisions

1. **Per account.** Stored on `user_preferences`, not in `localStorage`. `localStorage` is only a paint
   cache, as for the magazine style.
2. **Managed in Settings.** A "Share menu" group under Settings › Preferences holds one switch per target,
   showing the target's icon and label. On means shown. The share menu itself gets no edit mode.
3. **Fixed targets only.** The entries of `share-targets.json` can be removed. The "Post as @handle"
   entries of linked social accounts stay, because linking the account was already that choice.
4. **Stable ids.** Every entry of `share-targets.json` except dividers gets an `id` (`system`, `copy`,
   `email`, `bluesky`, `threads`, `x`, `facebook`, `linkedin`, `reddit`, `whatsapp`, `telegram`,
   `instapaper`). Menu items are keyed by array index today, which would point a stored choice at a
   different target after any reorder. A spec checks the ids are unique and well-formed.
5. **The config stays the one list.** The server checks only the id format (`^[a-z0-9-]{1,32}$`), at most
   64 ids, duplicates dropped. It does not check ids against the targets. Adding a target, or a self-hoster
   editing `share-targets.json`, needs no backend change. A stored id that matches no target is
   never matched and stays harmless.
6. **Unknown ids survive a write.** A toggle sends the stored list with one id added or removed, so it
   keeps ids for targets this device does not list (the system sheet on a desktop browser, a target a newer
   config dropped).
7. **Dividers collapse.** After hidden targets are dropped, the menu never starts or ends on a divider and
   never shows two in a row. With every fixed target hidden, the post entries' divider goes too.
8. **All removed means the feature is gone.** With every target hidden, the article has no share button and
   no share menu, and selecting text offers nothing (no desktop bubble, no phone bar). The "Post as @handle"
   entries live only in that menu and bubble, so they go with it, even for an account with linked networks.
   Re-enabling any target in Settings brings the whole feature back.
9. **Restore over reject.** A backup line without the field restores `[]`; ids with a bad format are
   dropped rather than failing the restore, as `AccountLine` does for an unknown magazine style.

## Shape

- **Backend**
  - `Preferences::$hiddenShareTargets`: a JSON column `hidden_share_targets`, default `[]`, `list<string>`.
    Migration for SQLite and MySQL.
  - `PATCH /api/me/share-targets` with `{ "hiddenShareTargets": [...] }`, its own route for the reason
    `updatePreferences()` gives. `UpdateShareTargetsRequest` validates format and count, so a bad id is a
    422 problem. `AccountPreferencesWriter::changeHiddenShareTargets()` stores the deduplicated list. The
    response is the profile, like the other `/api/me` writes.
  - `MeJson` exposes `preferences.hiddenShareTargets`.
  - Backup: `BackupLines` writes `hiddenShareTargets`; `AccountLine` reads it with a default of `[]` and
    filters by format; `RestoreLoadPass` sets it. `BackupSchemaCoverageTest`, `BackupFieldDeclarations`
    and `FullyPopulatedAccount` gain the field.
  - Account wipe needs nothing: the column sits on the row the user cascade already deletes.
- **Frontend**
  - `share-targets.json` gains the ids; `ShareTarget` types `id: string` on non-divider entries.
  - `HiddenShareTargetsService` (`core/preferences/`), shaped like `MagazineStyleService`: a
    `hidden` signal backed by a `localStorage` paint cache, `adopt(user)` on every `loadMe()`,
    `reset()` on logout, `setShown(id, shown)` that applies locally then writes through
    `HIDDEN_SHARE_TARGETS_WRITER`, and `saveFailed`.
  - `shareMenuItems(networks, mode, hidden)` drops hidden targets and collapses dividers. Target item ids
    become the target's `id`.
  - `HiddenShareTargetsService.sharingEnabled`: a computed that is false when every target in
    `SHARE_TARGETS` is hidden. The share button and the selection bubble and phone bar render only while it
    is true.
  - Settings: a "Share menu" group in `preferences-section` listing `SHARE_TARGETS` (already filtered to
    what this browser supports) with an `app-toggle` each, plus an error banner on `saveFailed`.
  - Translations for the group title, hint, and the save failure, in every locale.
- **Tests**
  - PHPUnit: controller (valid list stored and echoed, duplicates dropped, bad format 422, too many 422),
    `AccountLine` (missing field, bad ids filtered), backup round trip.
  - Jest: `share-menu.spec.ts` (hidden targets dropped, divider collapse cases), the share button and
    selection bubble absent when `sharingEnabled` is false,
    `share-targets.spec.ts` (ids unique and well-formed), the service (cache, adopt, reset, unknown ids
    kept, failed write), the settings group.
  - Gates: `composer check`, `composer md`, both PHPUnit legs, `composer infection:diff`, the migration leg,
    `npm run check`.

## Out of scope

- Reordering targets, or adding custom ones per user.
- Hiding the "Post as @handle" entries.
- Instance-wide defaults chosen by an admin.
