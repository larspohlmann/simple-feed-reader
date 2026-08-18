# Read/viewed model redesign — `isRead` → `isHidden`, tick = viewed, circle = hidden

Issue: [#482](https://github.com/larspohlmann/simple-feed-reader/issues/482).
Branch: `feature/482-viewed-hidden-model`.

## Motivation

The green tick and the "Recently read" view are driven by two different flags,
and the code and the UI both blur them. This is a standing source of confusion.
On Strato, 3 legacy `is_viewed=1, is_read=0` rows show in "Recently read" without
a tick. The cause is historical: before #478 there was no `markUnread`, and
`isViewed` was one-way since #307, so "open then mark unread" left the two flags
inconsistent. #478 added the view that made those rows visible.

This redesign gives the two flags distinct, intent-revealing names and one strict
invariant, and maps each to exactly one indicator.

## Target model

Two flags on `entry_state`:

- **`isViewed`** — the user actively opened and read the article. Indicator: the
  **green tick**. Interactive. Drives the "Recently read" list and the recommender
  engagement signal.
- **`isHidden`** (renamed from `isRead`) — the entry is removed from the unread
  list. Indicator: the **green circle** (the existing dot). Display-only. Sticky.

**Invariant: `isViewed` ⊆ `isHidden`.** Every viewed entry is hidden. Not every
hidden entry is viewed (a "mark all read" sweep hides without viewing).

### Transitions

| Action | isViewed | isHidden |
|---|---|---|
| Open article in reader | → true | → true |
| Activate tick | → true | → true |
| Deactivate tick | → **false** | unchanged (**stays true**) |
| Mark all read (sweep) | unchanged | → true |
| Per-entry un-hide | removed — hidden is sticky | |

There is no per-entry "mark unread" any more. Once an entry is opened, ticked, or
swept, it stays out of the unread list. This is deliberate.

### Lists and filters

- "Recently read" view = `isViewed = true` (unchanged).
- unread / all filter = `isHidden` (renamed from `isRead`; behaviour unchanged).

## Enforcement — a Doctrine subscriber

The invariant lives in one place: a Doctrine event subscriber on `EntryState`,
applied on flush. Its single rule: **if `isViewed` is true, force `isHidden`
true**, and set `hiddenAt` from `viewedAt` (or the clock) when empty.

Consequences:

- `markViewed()` sets only `isViewed`/`viewedAt`. The subscriber adds the hidden
  flag. No caller has to remember the coupling.
- Any write path is covered: the web PATCH, a future native iOS client, a direct
  field set. None can persist a viewed-but-not-hidden row.
- The bulk "mark all read" `UPDATE` bypasses ORM events, but it only sets the
  hidden flag, so it never breaks the invariant. No conflict.

The subscriber needs a **functional test** that flushes through the real
`EntityManager`. A direct-invocation test could assert something the real wiring
makes impossible, so it does not count as proof here.

## Backend changes

### Entity `EntryState`

- Rename field `isRead` → `isHidden`, column `is_read` → `is_hidden`.
- Rename field `readAt` → `hiddenAt`, column `read_at` → `hidden_at`.
- Rename accessors `isRead()`/`setIsRead()`/`getReadAt()`/`setReadAt()` →
  `isHidden()`/`setIsHidden()`/`getHiddenAt()`/`setHiddenAt()`.
- `markRead()` → `hide()` (sets `isHidden`/`hiddenAt` only).
- `markViewed(when)` sets `isViewed`/`viewedAt` only (subscriber adds hidden).
- New `clearViewed()` clears `isViewed`/`viewedAt`, leaves the hidden flag.
- `markUnread()` stays as the `isHidden:false` API path (clears both flags), so a
  non-web client that sends `isHidden:false` cannot break the subset. No web path
  uses it any more.

### Controller `EntryController::updateState`

- `isHidden !== null`: true → `hide()`; false → `markUnread()`.
- `isViewed !== null`: true → `markViewed()`; false → `clearViewed()`.
  (Today only `isViewed === true` is accepted; add the `false` branch.)

### `MarkReadService` ("mark all read")

- Behaviour unchanged. Rename the column in the bulk DQL `UPDATE`
  (`es.isRead` → `es.isHidden`, `es.readAt` → `es.hiddenAt`). Still never touches
  `isViewed`.

### DTOs, JSON, repositories

- `UpdateEntryStateRequest`: rename `isRead` → `isHidden`.
- `EntryListRow`, `EffectiveReadState`, `UnreadDql`, `EntryListRepository`,
  `EntryStateRepository`, `RecommendationItemRepository`, `EntryStateResolver`,
  `EntryJson`, `EntryStateJson`, `MeJson`: rename the field and column references.
- The API JSON field the client reads/writes becomes `isHidden`.

### Migrations

- **Schema migration**: rename columns `is_read` → `is_hidden`,
  `read_at` → `hidden_at` on `entry_state`. Must be correct on MySQL and SQLite
  (the CI migrate leg runs both, then `doctrine:schema:validate`).
- **Data migration**: reconcile the legacy rows —
  `UPDATE entry_state SET is_hidden = 1, hidden_at = COALESCE(hidden_at, viewed_at)
  WHERE is_viewed = 1 AND is_hidden = 0`. Under the new rule a viewed entry is
  hidden, so the 3 Strato rows gain a circle and a tick. Portable SQL, both
  dialects.

## Frontend changes

### Indicators

- The **check button** (`app-entry-actions`, `entry-row`, `reader-view`) becomes
  the **viewed tick**: `[class.on]="entry().isViewed"`, `aria-pressed` on
  `isViewed`. It emits a `viewed` toggle instead of `read`.
- The **dot** (magazine cards, `entry-row`) becomes the **hidden circle**:
  `[class.on]="entry().isHidden"`. The fill inverts — a hidden (read) entry fills
  the circle; an unread entry shows the outline. The `.read` dim class on cards
  keys on `isHidden`.

### Store and coupling

- `models.ts`: rename `isRead` → `isHidden` in `EntryDto`, `EntryListRow`, and the
  patch type. Keep `isViewed`.
- `localStatePatch`: new coupling —
  - `isViewed: true` ⇒ also set `isHidden: true` locally.
  - `isViewed: false` leaves `isHidden` unchanged.
  - `isHidden: false` ⇒ also set `isViewed: false` locally (API-safety mirror).
- The tick handler: activate → `patch { isViewed: true }`; deactivate →
  `patch { isViewed: false }`. The client never sends the hidden flag on a tick.
- Un-ticking inside "Recently read" reuses the existing saved-view leave
  animation. `savedViewMembership('viewed')` keys on `isViewed`.
- Open effect in `reader-shell`: send `{ isViewed: true }` only; the backend and
  `localStatePatch` set hidden. Keep the once-per-session guard and the unread
  count decrement (opening hides the entry, so the unread badge drops).

### Sidebar counts

- unread count keys on `isHidden` (was `isRead`). `viewedCount` unchanged.
- "Mark all read" still decrements unread and does not change `viewedCount`.

## Testing

- Backend unit + functional: the subscriber invariant (functional, through the
  EM), `updateState` `isViewed:false` path, `markViewed`/`clearViewed`, the
  mark-all-read column rename, the two migrations (schema + data) on both SQLite
  and MySQL.
- Mutation testing gates the changed files (`composer infection:diff`).
- Frontend Jest: the tick toggles `isViewed` and mirrors `isHidden` on activate
  only; the circle keys on `isHidden`; un-tick in "Recently read" leaves the row;
  the unread filter keys on `isHidden`.
- Backup round-trip test updated for the renamed fields and bumped version.

## Backup

- `EntryStateLine`: rename `isRead`/`readAt` → `isHidden`/`hiddenAt`.
- `AccountBackupExporter`: write `isHidden`/`hiddenAt`.
- `RestoreEntryLoader`: read `isHidden`/`hiddenAt`, call `setIsHidden()`.
- Bump `BackupSchema::VERSION`. Old backups fail the version check on restore —
  acceptable by decision; no backward compatibility, no migration of old files.

## Out of scope

- User-facing labels ("Recently read", "Mark all read") stay unchanged.
- No change to favourite or kept.
- No backward compatibility for old backup files.
