# Canonical Item Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every feed's copy of one item (a Lemmy post through Local and All, a Mastodon status through a timeline and RSS) relates to one `canonical_item` row, so collapse, Trending, read state, score, counts, votes, likes and the SPA's threads agree across copies (#2404).

**Architecture:** A kind-neutral `canonical_item` table and `entry.canonical_item_id`, filled at ingestion by tagged per-kind key rules (the `EntryIdentityUrlInterface` pattern). The shared layer — lists, read marking, votes, likes, Trending, JSON — only ever reads the item id, never the kind. Lemmy-only facts move to `lemmy_post` keyed on the item.

**Tech Stack:** Symfony 8.1 / PHP 8.5 / Doctrine ORM (MySQL + SQLite), Angular 20 signals, PHPUnit 12, Jest.

**Spec:** `docs/superpowers/specs/2026-11-20-canonical-item-design.md` — read it before your task; this plan argues from it.

## Global Constraints

- Branch `feature/2404-canonical-item`; commits `type(#2404): …`; no Claude attribution anywhere.
- Nothing in the shared layer names a social network: no `post`, `network`, `SocialNetwork` in `canonical_item`, its entity, its repository, `CanonicalKeys`, `DuplicateCollapseDql`, the read services or the SPA item key.
- `kind` column type is `App\Enum\CanonicalItemKind` (`bluesky`, `mastodon`, `lemmy`). `SocialNetwork` stays the social modules' type.
- `key_hash` is `hash('sha256', $canonicalKey)` (64 chars, as `url_hash`); unique `(kind, key_hash)`; `canonical_key` VARCHAR(2048).
- Entries no rule claims keep `canonical_item_id = NULL`; RSS articles behave exactly as today (url_hash collapse and url_hash single-toggle mirror unchanged).
- Routes stay `/entries/{id}/…`. Entry JSON gains `canonicalItemId: int|null` only (additive; native iOS client unaffected).
- CLAUDE.md rules apply in full: Clean Code names, `final readonly`, queries only in `src/Repository`, PHPMD-clean touched files, PHPStan max, phptramp, no new PHPStan ignores or rule edits, `Response::HTTP_*`, no comments that don't clear the bar.
- No N+1: every list query that renders counts, score, vote or like reads the item through a join or one batched lookup per page.

## Review Focus

1. **Two copies of one new item in one refresh pass, or in two workers at once** — one `canonical_item` row, no unique-constraint failure. Pinned in Task 2 (`testEnsureIsIdempotentForOneKey`, `testTwoCopiesInOneFlushShareOneItem`).
2. **A saved copy** (guid `<guid>#<source feed id>`, saved feeds carry a network) is the same item as its original. Pinned in Task 1 (`testSavedCopyGuidKeysAsItsOriginal` per rule).
3. **A copy in a feed the reader does not subscribe to** — read propagation never creates `entry_state` for it. Pinned in Task 5 (`testCopyInUnsubscribedFeedGetsNoState`).
4. **One copy pruned, the other kept** — the item, its vote, like and `lemmy_post` survive; pruning the last copy removes them. Pinned in Task 8 (`testItemSurvivesWhileACopyRemains`, `testLastCopyPrunedRemovesItem`).
5. **A Mastodon RSS copy and timeline copy, and a Lemmy RSS copy whose page is the post's home** — one item each pair; a Lemmy RSS copy from a foreign instance is its own item. Pinned in Task 1.

---

### Task 1: Kind enum, key rules and resolver

**Files:**
- Create: `backend/src/Enum/CanonicalItemKind.php`
- Create: `backend/src/Service/Foundation/CanonicalItem/CanonicalKeys.php`
- Create: `backend/src/Service/Foundation/CanonicalItem/Model/CanonicalKeyModel.php`
- Create: `backend/src/Service/Foundation/CanonicalItem/CanonicalKeyRule/CanonicalKeyRuleInterface.php`
- Create: `backend/src/Service/Social/Bluesky/BlueskyCanonicalKeyRule.php`, `backend/src/Service/Social/Mastodon/MastodonCanonicalKeyRule.php`, `backend/src/Service/Social/Lemmy/LemmyCanonicalKeyRule.php`
- Test: `backend/tests/Service/Foundation/CanonicalItem/CanonicalKeysTest.php`, one test per rule under `backend/tests/Service/Social/{Bluesky,Mastodon,Lemmy}/`

**Interfaces:**
- Produces:
  - `enum CanonicalItemKind: string { Bluesky='bluesky'; Mastodon='mastodon'; Lemmy='lemmy' }`
  - `final readonly class CanonicalKeyModel { __construct(public CanonicalItemKind $kind, public string $key); keyHash(): string }` (sha256 of `$key`)
  - `interface CanonicalKeyRuleInterface { public function findKey(Entry $entry): ?CanonicalKeyModel; }`, tag `app.canonical_key_rule` (autoconfigure via `#[AutoconfigureTag]` on the interface)
  - `final readonly class CanonicalKeys { public function findKey(Entry $entry): ?CanonicalKeyModel; }` — first non-null rule answer, `#[AutowireIterator('app.canonical_key_rule')]`

Each rule answers null unless `$entry->getFeed()->getNetwork()` is its network, and strips a trailing `#<digits>` saved-copy suffix from the guid before parsing. Rules:
- Bluesky: the guid when it is an AT post URI (`AtPostUri`).
- Mastodon: `MastodonStatusAddress::parseAddress(guid)` else `parseAddress(url)`, then `->uri()` (`https://host/users/x/statuses/123`).
- Lemmy: `LemmyPostUrl::parse(guid)` else `LemmyPostUrl::parse(discussion url)`, as `https://<host>/post/<id>`. It answers for feeds of network Lemmy **and** for any entry `LemmyPostAddress::findPost()` recognises, because community RSS feeds (`/feeds/c/x.xml`) may carry no network; a test with such a feed pins which holds.
- Invariant: for Mastodon and Bluesky a rule answers non-null exactly when `SocialPostAddresses::findPost()` does; for Lemmy, whenever `LemmyPostAddress::findPost()` does (every entry that can be voted on or liked has an item).

- [ ] **Step 1: Write the failing tests.** Per rule, with an `Entry` on a feed of that network:
  - Mastodon `testTimelineAndRssFormsShareOneKey`: guid `https://mastodon.social/users/alice/statuses/123` and guid/url `https://mastodon.social/@alice/123` both give key `https://mastodon.social/users/alice/statuses/123`, kind `Mastodon`.
  - Lemmy `testApIdAndHomeRssPageShareOneKey`: guid `https://lemmy.world/post/54626839` (API) and an RSS entry whose guid is `https://lemmy.world/post/54626839` give the same key; `testForeignRssPageIsItsOwnKey`: RSS guid `https://lemmy.ml/post/5` ≠ API ap_id `https://lemmy.world/post/9`.
  - Bluesky `testAtUriIsTheKey`: guid `at://did:plc:abc/app.bsky.feed.post/3k` → that key.
  - Every rule `testSavedCopyGuidKeysAsItsOriginal`: guid `<original guid>#42` → the original's key.
  - Every rule `testEntryOfAnotherNetworkHasNoKey` and `testArticleFeedHasNoKey` (network null) → null.
  - `CanonicalKeysTest::testFirstAnsweringRuleWins`, `testNoRuleAnswersGivesNull`; `CanonicalKeyModel::keyHash()` equals `hash('sha256', key)`.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Foundation/CanonicalItem tests/Service/Social --filter CanonicalKey` — expect FAIL (classes missing).
- [ ] **Step 3: Implement** the files above.
- [ ] **Step 4: Run** the same command — expect PASS; `composer stan` — no new errors (`ServiceModuleCycleRule` clean: Foundation owns the interface, Social implements it).
- [ ] **Step 5: Commit** `feat(#2404): canonical key rules name the item an entry is`.

### Task 2: `canonical_item` entity, repository, entry link, ingestion writer

**Files:**
- Create: `backend/src/Entity/CanonicalItem.php`, `backend/src/Repository/CanonicalItemRepository.php`
- Create: `backend/src/Service/Ingestion/Ingest/CanonicalItemWriter.php`
- Modify: `backend/src/Entity/Entry.php` (relation, index `idx_entry_canonical_item (canonical_item_id, id)`, count getters/recorders), `EntryIngestor.php`, `StoredEntryRefresher.php`
- Modify: the list projection (`EntryListRepository`'s `projection->rowQueryBuilder`) to fetch-join the item
- Test: `backend/tests/Repository/CanonicalItemRepositoryTest.php`, `backend/tests/Service/Ingestion/Ingest/CanonicalItemWriterTest.php`, extend `EntryIngestorTest`

**Interfaces:**
- Consumes: Task 1 `CanonicalKeys::findKey()`, `CanonicalKeyModel`.
- Produces:
  - `CanonicalItem` (`id`, `kind`, `canonicalKey`, `keyHash`, `score ?int`, `commentCount ?int`, `selfReplyCount int` default 0): `getScore()`, `getCommentCount()`, `getSelfReplyCount()`, `recordScore(?int)` (null keeps the old value), `recordCommentCount(?int)` (null keeps), `recordSelfReplyCount(int)` (only grows), `requireId()`.
  - `Entry::getCanonicalItem(): ?CanonicalItem`, `Entry::linkTo(CanonicalItem $item): void` — hands the entry's own comment count and self-reply count to the item and clears the entry's copy; `getCommentCount()`, `getSelfReplyCount()`, `recordDiscussionCounts()`, `countSelfReplies()` read/write the item when linked, else the `EntryDiscussion` embeddable.
  - `CanonicalItemRepository::ensure(CanonicalKeyModel $key): int` — native per-dialect insert-or-ignore (`INSERT IGNORE` / `INSERT … ON CONFLICT DO NOTHING`) then select the id; safe across processes.
  - `CanonicalItemRepository::findByIds(list<int>): array<int, CanonicalItem>`
  - `CanonicalItemWriter::attach(list<array{0: Entry, 1: ParsedEntryModel}>)`, `refresh(...)` (same pair shape as `EntryLemmyPostWriter`): link via `ensure()` + `getReference`, then record `parsed->discussionCounts` (score, comment count) on the item. Newest fetch wins.

- [ ] **Step 1: Write the failing tests.**
  - `testEnsureIsIdempotentForOneKey`: two `ensure()` calls with one key return one id; table holds one row.
  - `testTwoCopiesInOneFlushShareOneItem`: ingest the same Lemmy post into two feeds in one unit of work, one flush → both entries' `getCanonicalItem()->requireId()` equal.
  - `testRefreshRecordsNewestCounts`: copy A ingested with score 3/comments 2, copy B refreshed with score 5/comments 4 → item score 5, `A->getCommentCount() === 4`.
  - `testNullScoreKeepsTheStoredOne`, `testSelfReplyCountOnlyGrows` on `CanonicalItem`.
  - `testArticleGetsNoItem`: RSS article entry → `getCanonicalItem() === null`, comment count stays on the entry.
  - `testLinkHandsCountsToTheItem`: entry with comment count 7 then `linkTo()` → item 7, entry embeddable null.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'CanonicalItem|EntryIngestorTest'` — FAIL.
- [ ] **Step 3: Implement.** Writer runs in `EntryIngestor::ingest` next to `lemmyPostWriter->attach()` and in `StoredEntryRefresher` next to `lemmyPostWriter->refresh()`. The projection fetch-joins `e.canonicalItem` so list rows add no query.
- [ ] **Step 4: Run** the filter, then `php bin/phpunit` (whole SQLite leg) — PASS.
- [ ] **Step 5: Commit** `feat(#2404): entries of one item link to one canonical_item row`.

### Task 3: `lemmy_post` keyed on the item; score from the item

**Files:**
- Rename: `EntryLemmyPost` → `backend/src/Entity/LemmyPost.php` (table `lemmy_post`, unique OneToOne `canonical_item_id` CASCADE, community columns only); `EntryLemmyPostRepository` → `LemmyPostRepository`; `EntryLemmyPostWriter` → `LemmyPostWriter`; their tests
- Modify: `backend/src/Service/Library/EntryList/ConnectedAccountStates.php`, every other `EntryLemmyPost*` user (`grep -rn EntryLemmyPost backend/src backend/tests`)

**Interfaces:**
- Consumes: Task 2 `CanonicalItem::recordScore()`, `Entry::getCanonicalItem()`.
- Produces: `LemmyPost::__construct(CanonicalItem $item)`, `placeIn(PostCommunity)`, `getCommunity()`; `LemmyPostRepository::byCanonicalItemIds(list<int>): array<int, LemmyPost>` (by item id); `EntryListRowLemmy`'s score comes from `CanonicalItem::getScore()`.

- [ ] **Step 1: Write the failing tests.** `LemmyPostWriterTest::testCommunityStoredOncePerItem` (two copies, one `lemmy_post` row); `ConnectedAccountStatesTest::testBothCopiesShowTheItemScore` (copies A and B → same score and community in their `EntryListRowLemmy`).
- [ ] **Step 2: Run** `php bin/phpunit --filter 'LemmyPost|ConnectedAccountStates'` — FAIL.
- [ ] **Step 3: Implement** the rename and re-key; score writes go to the item (`CanonicalItemWriter` already records `discussionCounts->score`, so `LemmyPostWriter` keeps only the community).
- [ ] **Step 4: Run** the filter and `composer stan` — PASS.
- [ ] **Step 5: Commit** `refactor(#2404): lemmy_post hangs off the canonical item`.

### Task 4: Votes and likes on the item, through the account that made them

**Files:**
- Modify: `backend/src/Entity/DiscussionVote.php`, `SocialLike.php` (replace `entry` with `canonicalItem`, unique `(user_id, canonical_item_id)`), `DiscussionVoteRepository.php`, `SocialLikeRepository.php`
- Modify: `backend/src/Service/Social/Social/DiscussionVotes.php`, `SocialLikes.php`, `SocialPostFinder.php` (if a by-account lookup is missing), `ConnectedAccountStates.php`, `backend/src/Service/Library/SavedPosts/SavedStateMover.php` (drop `copyLike`: a saved copy shares the item)
- Test: `DiscussionVotesTest`, `SocialLikesTest`, `ConnectedAccountStatesTest`, the vote/like controller functional tests

**Interfaces:**
- Consumes: Task 2 `Entry::getCanonicalItem()`.
- Produces: `DiscussionVoteRepository::findOneFor(User, CanonicalItem): ?DiscussionVote`, `findScores(int $userId, list<int> $itemIds): array<int, VoteScore>` (by item id); `SocialLikeRepository::findOneFor(User, CanonicalItem)`, `findLikedItemIds(int $userId, list<int> $itemIds): array<int, true>`. An entry without an item throws `NotASocialPostException` from the vote and like services.

Acting account (spec, "The acting account"): when a vote/like row exists, every action on any copy — change, remove, unlike and `syncFromNetwork` — uses the row's `social_account_id`; only with no row does `ActingAccountChooser` pick per copy. A sync never drops a row on behalf of a different account than the row's.

- [ ] **Step 1: Write the failing tests.**
  - `testVoteOnOneCopyShowsOnTheOther`: vote +1 on copy A → `findScores` for B's item gives `Up`; the list row of B has `myVote = Up`.
  - `testLikeOnOneCopyShowsOnTheOther`, and `like()` on copy B after A is liked makes no network call.
  - `testActionsUseTheStoredAccount`: like on copy A through account A; copy B's feed is account B's private timeline → `unlike()` on copy B calls the liker with account A; `syncFromNetwork()` on copy B fetches through account A and keeps the row.
  - `testNoRowLetsTheChooserPick`: first like on copy B goes through account B.
  - `testSavedCopySharesTheLike`: saving a liked post leaves one `social_like` row and the saved copy shows liked.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'DiscussionVote|SocialLike|ConnectedAccountStates|SavedStateMover'` — FAIL.
- [ ] **Step 3: Implement.** `DiscussionVoteRepository::store()` keeps its MySQL/SQLite upsert on the new key.
- [ ] **Step 4: Run** the filter, then `php bin/phpunit` — PASS.
- [ ] **Step 5: Commit** `feat(#2404): votes and likes belong to the canonical item`.

### Task 5: Read state covers every copy

**Files:**
- Modify: `backend/src/Service/Library/Reading/EntryStateUpdater.php` (`mirror`), `EntryReadMarker.php`
- Modify: `backend/src/Repository/EntryListRepository.php` (`siblingRowsForUser`), `EntryReadMarkRepository.php` (copy lookups)
- Create: `backend/src/Service/Ingestion/Ingest/CopyReadStateInheritor.php` (called after the refresh flush, in `IncomingPageStore::store` once ids exist)
- Test: `EntryStateUpdaterTest`, `EntryReadMarkerTest`, a functional test per mark-read surface (`MarkReadService` feed/tag/all, `MarkEntriesReadService`, For you, saved search, search, Trending story), `CopyReadStateInheritorTest`

**Interfaces:**
- Consumes: Task 2 `Entry::getCanonicalItem()`.
- Produces:
  - `EntryListRepository::siblingRowsForUser(int $userId, Entry $entry): list<EntryListRow>` — same item when linked, else same `url_hash`, else none; subscribed feeds only, excluding `$entry`.
  - `EntryReadMarkRepository::findCopyIdsForUser(int $userId, list<int> $entryIds): list<int>` — the user's subscribed entries sharing an item with `$entryIds`, excluding them.
  - `EntryReadMarkRepository::findUnreadCopyIdsOutsideFeeds(int $userId, list<int> $feedIds, \DateTimeImmutable $until): list<int>` — the user's subscribed, not-yet-hidden entries outside `$feedIds` whose item has an entry in `$feedIds` with `effectiveDate <= $until`.
  - `CopyReadStateInheritor::inherit(list<Entry> $created): void` — one set-based insert: for each subscriber of a new entry's feed who has a hidden state on another copy of its item, a hidden (not viewed) state on the new entry.
- `markEntriesRead` expands its ids with `findCopyIdsForUser` (distinct) before batching; `markSubscriptionsReadUntil`, after its transaction, marks `findUnreadCopyIdsOutsideFeeds` through `markEntriesRead`.

- [ ] **Step 1: Write the failing tests.** Fixture: Lemmy post in feeds Local and All, both subscribed.
  - `testToggleReadMarksTheCopy` and `testToggleUnreadUnmarksTheCopy` (API `isRead:false`).
  - `testScrollPastMarksTheCopy` (`markEntriesRead([A])` → B hidden, B has a state row).
  - `testFeedSweepMarksCopyInOtherFeed`: `MarkReadService` on Local → B in All hidden; All's unread count drops by one; an unrelated All entry stays unread.
  - One test each for tag, all, For you, saved search, search, Trending story mark-read leaving no copy unread.
  - `testCopyInUnsubscribedFeedGetsNoState`: All not subscribed → no `entry_state` row for B.
  - `testArticleCopiesKeepUrlHashMirror`: two RSS copies sharing `url_hash` still mirror on toggle (today's behaviour).
  - `testLateCopyArrivesRead`: A read, then All ingests B → B hidden, not viewed; another subscriber who had not read A sees B unread.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'EntryStateUpdater|EntryReadMarker|MarkRead|CopyReadStateInheritor'` — FAIL.
- [ ] **Step 3: Implement.** Copy-id queries are DQL/QueryBuilder in the repositories; the inheritor's insert is native per-dialect SQL in a repository.
- [ ] **Step 4: Run** the filter, then `php bin/phpunit` — PASS.
- [ ] **Step 5: Commit** `feat(#2404): reading one copy reads every copy`.

### Task 6: Lists collapse and Trending counts by item

**Files:**
- Modify: `backend/src/Repository/DuplicateCollapseDql.php`, `TrendingEntryRepository.php` (select `canonical_item_id`), `TrendingStoryRepository.php` (`MEMBER_COUNT`), `backend/src/Service/Curation/Trending/Model/WindowEntryModel.php`, `Pass/TrendingRun.php`
- Test: `DuplicateCollapseDqlTest` (or the list functional tests that cover it), `TrendingRunTest`, `TrendingStoryRepositoryTest`

**Interfaces:**
- Consumes: Task 2 index `(canonical_item_id, id)`.
- Produces: `WindowEntryModel::$canonicalItemId ?int`; `TrendingRun::add()` groups entries of one item as it groups entries of one URL.

`DuplicateCollapseDql` hides `e` when an earlier in-scope `e2` shares `urlHash` **or** `canonicalItem`. Write it as two `NOT EXISTS` (one per key) if MySQL `EXPLAIN` on the real list statement shows the `OR` loses `idx_entry_url_hash`/`idx_entry_canonical_item`; measure the real statement, not a probe.

- [ ] **Step 1: Write the failing tests.** `testCopiesOfOneItemShowOnce` (Local + All copies → one row, the lower id, in the all-subscriptions list, a tag list and saved-search list); `testFeedViewStillShowsItsCopy` (All alone shows B); `testStoryCountsAnItemOnce` (story 285632's shape: two copies → member count 1 and one shown row); `testCopiesGroupIntoOneStory` in `TrendingRunTest`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'DuplicateCollapse|EntryList|Trending'` — FAIL.
- [ ] **Step 3: Implement.** `MEMBER_COUNT` counts `DISTINCT COALESCE(member_entry.canonical_item_id, -member.entry_id)` (or an equivalent that counts unlinked entries each once).
- [ ] **Step 4: Run** the filter; on MySQL `docker compose exec php composer test:parallel`; record the `EXPLAIN` in the commit body.
- [ ] **Step 5: Commit** `feat(#2404): copies of one item collapse in lists and count once in Trending`.

### Task 7: Entry JSON names the item

**Files:**
- Modify: `backend/src/Http/EntryJson.php`, `CommentsJson.php` if it emits counts, `DiscussionVoteJson.php` (score from the item)
- Test: `backend/tests/Http/EntryJsonTest.php`, `EntryControllerTest`

**Interfaces:**
- Produces: entry JSON `canonicalItemId: int|null`; `discussion.commentCount`, `discussion.score`, `selfReplyCount` from the item when linked.

- [ ] **Step 1: Write the failing tests.** `testPostCarriesItsItemId` (both copies' JSON: same `canonicalItemId`, same `discussion.score`, `discussion.commentCount`, `myVote`); `testArticleCarriesNullItemId`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'EntryJson|EntryController'` — FAIL.
- [ ] **Step 3: Implement.** Run the architecture §6 checklist; note the result in the commit body.
- [ ] **Step 4: Run** the filter — PASS.
- [ ] **Step 5: Commit** `feat(#2404): entry responses name their canonical item`.

### Task 8: Restore, backup schema and retention

**Files:**
- Modify: `backend/src/Service/Operations/Backup/Pass/RestoreEntryLoader.php` (link restored entries after the batch insert), `backend/tests/Service/Operations/Backup/Support/BackupSchemaCoverageTest.php`, `docs/backup.md` §6 (`canonical_item`, `entry.canonical_item_id`, `lemmy_post` as skipped)
- Modify: `backend/src/Service/Ingestion/Retention/EntryPruner.php` + a repository method deleting items no entry references
- Test: restore functional test, `EntryPrunerTest`

**Interfaces:**
- Consumes: Task 1 `CanonicalKeys`, Task 2 `CanonicalItemRepository::ensure()`.
- Produces: `CanonicalItemRepository::deleteUnreferenced(): int`; a restore pass that links every restored entry a rule claims (reuse `CanonicalKeys` + `ensure`; no per-kind code).

- [ ] **Step 1: Write the failing tests.** `testRestoredCopiesShareOneItem` (back up and restore an account holding Local + All copies → one item, rows collapse); `testItemSurvivesWhileACopyRemains`; `testLastCopyPrunedRemovesItem` (item, vote, like, `lemmy_post` gone); `BackupSchemaCoverageTest` passes with the new columns declared.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'Restore|EntryPruner|BackupSchemaCoverage'` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter — PASS.
- [ ] **Step 5: Commit** `feat(#2404): restore and retention keep canonical items whole`.

### Task 9: Migration with backfill

**Files:**
- Create: `backend/migrations/Version20261120120000.php` (house style: `upMySql`/`upSqlite`/`downMySql`/`downSqlite`, `isTransactional(): false`, `skipIf` guards)
- Test: `backend/tests/Migrations/Version20261120120000Test.php` (or the repo's existing migration-test pattern, if any; otherwise a test that runs the migration's backfill against a seeded pre-migration schema on SQLite)

Steps the migration performs, both dialects:
1. Create `canonical_item` (unique `(kind, key_hash)`), add `entry.canonical_item_id` (FK `ON DELETE SET NULL`) + index, create `lemmy_post` (unique `canonical_item_id`, FK CASCADE).
2. Backfill in PHP over `$this->connection`, batched by entry id, for entries of feeds with `network IS NOT NULL`: compute the key with self-contained regexes mirroring Task 1's rules (no `App\` imports — migrations must survive later refactors), insert items, set `entry.canonical_item_id`. Newest entry (highest id) gives `score`, `comment_count`; `self_reply_count` is the max.
3. Move `entry_lemmy_post` community onto `lemmy_post`; drop `entry_lemmy_post`.
4. Re-key `discussion_vote` and `social_like` to `canonical_item_id`; where one user has two rows for one item keep the latest (`voted_at` / liked-at), with its `social_account_id`; rows on entries without an item are deleted (none expected — count and log them).
5. Null `entry.comment_count` and reset `entry.self_reply_count` for linked entries.
6. SQLite: any rebuilt `entry` table loses the FTS triggers — run `EntrySearchIndex::install()` again in the same migration.

- [ ] **Step 1: Write the failing test** `testBackfillTiesCopies`: seed (pre-migration schema) one Lemmy pair (scores 3, 2; ids 1617614 < 1617690 shape), one Mastodon timeline + RSS pair, one RSS article, two votes of one user on the Lemmy pair → after `up`: 2 items, Lemmy item score from the newer entry, one vote (the later), article has NULL item.
- [ ] **Step 2: Run** it — FAIL.
- [ ] **Step 3: Implement** the migration.
- [ ] **Step 4: Verify** on both dialects from empty, as CI does: SQLite `bin/console doctrine:migrations:migrate -n --env=test` on a fresh file then `doctrine:schema:validate`; MySQL the same inside `docker compose exec php` on a throwaway database. Then `down` and `up` again on SQLite. Expected: validate reports mapping and database in sync.
- [ ] **Step 5: Commit** `feat(#2404): migrate copies onto canonical items`.

### Task 10: SPA keys threads, counts and row patches by item

**Files:**
- Create: `frontend/src/app/reader/state/item-key.ts` (+ spec)
- Modify: the entry DTO (`canonicalItemId: number | null`), `reader/article/entry-comments/comments.service.ts`, `reader/article/social-counts/social-counts.service.ts`, `reader/article/composer/post-actions.service.ts`, `reader/state/entry-row-cache.ts`, their specs

**Interfaces:**
- Consumes: Task 7 JSON field.
- Produces: `itemKey(entry: { id: number; canonicalItemId: number | null }): string` → `item:<canonicalItemId>` or `entry:<id>`. `CommentsService` and `SocialCountsService` key their state maps by it (API calls still go to `/entries/{id}`); `EntryRowCache` applies like, vote, score, comment-count and read/viewed patches to every cached record with the same `canonicalItemId`.

- [ ] **Step 1: Write the failing specs.** `itemKey` both branches; `CommentsService` `insertReply on copy A shows in copy B's thread`; `SocialCountsService` `a repost on copy A steps copy B's counts`; `EntryRowCache` `setVote on A patches B's myVote and score`, `setLiked on A patches B`, `saveState read on A patches B`, `an article's patch touches only itself`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npm test -- item-key comments.service social-counts.service entry-row-cache` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check` — PASS.
- [ ] **Step 5: Commit** `feat(#2404): the reader keys threads and counts by canonical item`.

### Task 11: Whole-branch verification

- [ ] **Step 1:** `cd backend && composer check && composer md` — clean on every touched `src` file.
- [ ] **Step 2:** `php bin/phpunit` and `docker compose exec php composer test:parallel` (parallel, never with Jest) — green.
- [ ] **Step 3:** `composer infection:diff` — at or above `minMsi`; fix escaped mutants on changed lines.
- [ ] **Step 4:** `docker compose exec php bin/console doctrine:migrations:migrate -n` on the dev stack; scan `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq .` for deprecations/errors.
- [ ] **Step 5:** Live check on localhost:4200 with a Lemmy post subscribed through Local and All: one row in All, vote on one copy shows on the other, reading one leaves the other read in its feed.
- [ ] **Step 6:** `composer e2e` and `npm run e2e` from this checkout (stack must be this checkout's).
