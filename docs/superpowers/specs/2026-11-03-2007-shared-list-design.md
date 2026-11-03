# Shared list (#2007) — design

Brainstormed 2026-11-03. Builds on #2006 (share target ids, the Sharing settings group, `SharingService`,
`PATCH /api/me/sharing`); implementation starts from `develop` once #2006 has merged.

## What

Sharing an article to a social network registers it as shared. A **Shared** item in the sidebar, right below
Kept, lists the shared articles, newest share first, and is shown only while the list holds an entry. Shared
articles feed the For you profile. A switch in Settings turns the registering off.

## Decisions

1. **Only social networks count.** Bluesky, Threads, X, Facebook, LinkedIn and Reddit, from the share menu and
   from the selection bubble, and a successful "Post as @handle". Copy link, email, WhatsApp, Telegram,
   Instapaper and the system share sheet do not.
2. **The config marks the networks.** Each of the six entries in `share-targets.json` carries
   `"social": true`; the frontend checks the flag, never a list of ids.
3. **Registered by the client.** An intent window cannot report whether the post went out, so picking a
   social target is the share. "Post as @handle" posts a bare link the server cannot tie to an entry, so the
   client registers it once the composer reports the post sent; a cancelled composer registers nothing.
4. **Only entries.** The link reader shows a page that is no entry, so a share there registers nothing.
5. **Its own entity, `EntryShare`** (user, entry, `sharedAt`; table `entry_share`, cascade-deleted with the user
   and the entry). Sharing again refreshes `sharedAt`. Per copy, like kept: not mirrored onto duplicates.
   Revised 2026-11-03: a `sharedAt` column on `EntryState` pushed that entity past PHPMD's public-method limit,
   and Lars ruled the suppression unacceptable, so the share moved out; `EntryState` keeps its pre-#2007 shape.
6. **Removable by hand.** "Remove from shared" clears `sharedAt`, which also takes the article out of the For
   you history. There is no manual "mark as shared".
7. **Protected like kept.** Retention never deletes a shared entry, and an unsubscribe rescues it as it rescues
   favorites and kept posts, until it is removed from Shared.
8. **Its own For you section**, ranked favorite > shared > kept > viewed. An entry counts once, in its highest
   section. A fixed cap of 40, not a setting. Shared entries are not recommendation candidates.
9. **A switch turns registering off.** "Remember shared articles" in the Sharing group, below the master
   switch, on by default, disabled while sharing is off. Off, a share registers nothing; what is already in
   Shared stays, still removable and still counting for For you. The server enforces it too: a mark arriving
   while it is off, or while sharing is off, is ignored, so every client gets the same rule.
10. **A failed registration is silent.** The share already happened; the next share sends the mark again.
11. **Reposts and quotes count.** A repost (a boost on Mastodon, a repost on Bluesky) or a quote of the entry's
    own post registers it as shared; reposting or quoting a reply in its thread does not. The server marks it
    when the network accepts the post, under the same switch as decision 9. Undoing a repost leaves the entry in
    Shared: the share happened, and removing it stays a manual action.

## Shape

- **Backend**
  - `App\Entity\EntryShare` (composite id user + entry, `sharedAt`, `reshare(\DateTimeImmutable)`) and its
    `EntryShareRepository`; table `entry_share` with cascading foreign keys and an index on
    (`user_id`, `shared_at`). Migration for SQLite and MySQL. `EntryState` is unchanged.
  - `Preferences::$rememberingShares` (`remember_shares`, default true) with `isRememberingShares()`,
    `setRememberingShares()` and `recordsShares()` (sharing on and remembering on). Migration for both.
  - `UpdateEntryStateRequest`/`EntryStateChangeModel` gain `isShared`; `EntryStateUpdater` marks with the
    clock's now when the user `recordsShares()`, ignores the mark otherwise, and always clears.
    `EntryStateJson` and `EntryJson` expose `isShared`.
  - `UpdateSharingRequest`/`SharingSettingsModel` gain the required `rememberShares`; `MeJson` exposes
    `preferences.sharing.rememberShares`.
  - `EntryView::Shared` (`shared`), filtered by `sharedAt IS NOT NULL`; `EntryListSort::SharedAt` orders and
    keyset-pages on the share row's `sharedAt`, left-joined into the list query. `EntryListRow` carries `sharedAt`.
    `SubscriptionCountsJson` gains `sharedCount`.
  - `ReadingHistoryRepository::shared()` excludes favorites; `kept` and `viewed` exclude shared.
    `RecommendationHistoryModel::$shared`, `RecommendationHistoryCaps::$shared` (`SHARED_CAP = 40`), a SHARED
    prompt section and wording, the fingerprint. `RecommendationCandidateRepository` excludes shared entries.
  - `RetentionRepository` protects shared entries; `SavedPostRepository::flaggedIn()` and `SavedStateMover`
    rescue them.
  - Backup: a line kind of its own for each share (feed, guid hash, `sharedAt`); a backup without such lines
    restores no shares. The account line carries
    `rememberShares` (missing means on).
- **Frontend**
  - `share-targets.json`: `"social": true` on the six networks; `ShareTarget` types it.
  - `SHARE_RECORDER` token; `EntryShareRecorder`, provided by the reader view, records the open entry when
    `SharingService.recordsShares()` holds. The share button and the selection actions call it for a social
    target and after a link post is sent; `PostActions.postLink()` returns the sent post.
  - `EntryStateActions.share()`/`unshare()`; `SubscriptionsStore.sharedCount` with `bumpShared()`.
  - Selection kind and view `shared`, its heading, icon and count; a "Shared" sidebar item below Kept,
    rendered only while `sharedCount() > 0`.
  - `entry-actions`: a "Remove from shared" button while `entry.isShared`.
  - Settings: the "Remember shared articles" switch; `SharingService` gains `rememberShares` and
    `setRememberShares()`.
  - Translations in `en.json` and `de.json`.

## Out of scope

- A history of individual shares (when, where, how often).
- Marking an article as shared by hand.
- Counting non-social targets, or a user-editable For you cap for shared.
- Registering shares from the link reader.
