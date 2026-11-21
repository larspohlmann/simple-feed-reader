# Bluesky and Mastodon like counts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** List rows and magazine cards show a Bluesky or Mastodon post's stored like count next to its comment bubble (#2362).

**Architecture:** The count is stored once per post on `canonical_item.like_count`, carried in `DiscussionCounts` from the timeline parsers through the existing record/refresh path, emitted as `likeCount` by `EntryJson`, and rendered by `entry-meta`.

**Tech Stack:** Symfony 8.1 / Doctrine (PHP 8.5), Angular 20, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-21-social-like-counts-design.md`

## Global Constraints

- Column `canonical_item.like_count`, nullable int, no backfill; never a column on `entry`.
- Display only: no button, no click handler.
- A null incoming like count keeps the stored one.
- JSON key `likeCount`, `int|null`.
- Aria label in en, de, fr, es, plural forms like `reader.discussionCounts.comments*`.
- Clean Code rules of CLAUDE.md; PHPMD-clean on every touched `src` file.

## Review Focus

- A Bluesky repost / Mastodon boost: the count is the original post's, not the boost's (Task 2 tests).
- A post with zero likes: shows `0`, not hidden (Tasks 3 and 4 tests).
- A refresh whose payload lacks the count keeps the stored one (Task 1 test).
- An entry without a canonical item (RSS article): `likeCount` null, no icon (Tasks 3 and 4 tests).
- A row with only a like count (no score, no comments) still shows the `.discussion` group (Task 4 test).

---

### Task 1: Store the like count on the item

**Files:**
- Modify: `backend/src/Entity/DiscussionCounts.php`, `backend/src/Entity/CanonicalItem.php`, `backend/src/Entity/DiscussionCountStoreInterface.php`, `backend/src/Entity/EntryDiscussion.php`
- Create: `backend/migrations/Version20261121150000.php`
- Test: `backend/tests/Entity/DiscussionCountsTest.php`, `backend/tests/Entity/CanonicalItemTest.php`, `backend/tests/Entity/EntryDiscussionTest.php`

**Interfaces:**
- Produces: `DiscussionCounts::$likeCount` (`?int`, public readonly); `DiscussionCounts::fromCommentsAndLikes(?int $commentCount, ?int $likeCount): self` (score null); `fromScoreAndComments` and `unknown` leave likes null. `DiscussionCountStoreInterface::getLikeCount(): ?int`; `CanonicalItem::getLikeCount()`; `CanonicalItem::recordCounts` also records the like count. `EntryDiscussion::getLikeCount()` returns null (it stores no likes).

- [ ] **Step 1: Failing tests**
  - `DiscussionCountsTest::testCommentsAndLikesCarryNoScore`: `fromCommentsAndLikes(3, 9)` → score null, commentCount 3, likeCount 9; `unknown()` and `fromScoreAndComments(1, 2)` → likeCount null.
  - `CanonicalItemTest::testRecordedCountsStoreTheLikeCount`: recordCounts(fromCommentsAndLikes(1, 7)) → getLikeCount() 7.
  - `CanonicalItemTest::testNullLikeCountKeepsTheStoredOne`: record 7, then fromCommentsAndLikes(2, null) → 7, commentCount 2.
  - `EntryDiscussionTest::testItStoresNoLikeCount`: after recordCounts(fromCommentsAndLikes(1, 7)) getLikeCount() is null.
- [ ] **Step 2:** `php bin/phpunit tests/Entity` → FAIL (undefined method).
- [ ] **Step 3: Implement** as in Interfaces; mapping `#[ORM\Column(name: 'like_count', nullable: true)]`. Migration adds `like_count INT DEFAULT NULL` to `canonical_item` for both platforms (follow the dialect pattern of the latest migration), down drops it.
- [ ] **Step 4:** `php bin/phpunit tests/Entity` → PASS; migrate from empty on SQLite and on Docker MySQL, then `bin/console doctrine:schema:validate` → in sync.
- [ ] **Step 5:** commit `feat(#2362): canonical_item stores a post's like count`.

### Task 2: Timelines read the like count

**Files:**
- Modify: `backend/src/Service/Social/Bluesky/Support/BlueskyTimelinePage.php:136`, `backend/src/Service/Social/Mastodon/Support/MastodonTimelinePage.php:170`
- Test: `backend/tests/Service/Social/Bluesky/Support/BlueskyTimelinePageTest.php`, `backend/tests/Service/Social/Mastodon/Support/MastodonTimelinePageTest.php`

**Interfaces:**
- Consumes: `DiscussionCounts::fromCommentsAndLikes` (Task 1).

- [ ] **Step 1: Failing tests**
  - Bluesky `testAPostCarriesItsLikeCount`: post `['replyCount' => 4, 'likeCount' => 11]` → `discussionCounts->likeCount === 11`, commentCount 4, score null.
  - Bluesky `testARepostCarriesTheOriginalPostsLikeCount`: repost item (reason `#reasonRepost`) of a post with likeCount 5 → 5.
  - Bluesky `testAPostWithoutALikeCountHasNone` → null.
  - Mastodon `testAPostCarriesItsFavouritesAsItsLikeCount`: `favourites_count: 8` → 8.
  - Mastodon `testABoostCarriesTheOriginalPostsLikeCount`: boost `favourites_count: 0`, `reblog.favourites_count: 6` → 6.
- [ ] **Step 2:** run both test files → FAIL.
- [ ] **Step 3:** both pages build `DiscussionCounts::fromCommentsAndLikes($post->int(reply key), $post->int('likeCount' | 'favourites_count'))` off the original post.
- [ ] **Step 4:** run → PASS. Then `php bin/phpunit tests/Service/Ingestion` → PASS (refresh path unchanged).
- [ ] **Step 5:** commit `feat(#2362): Bluesky and Mastodon timelines read a post's like count`.

### Task 3: List JSON sends `likeCount`

**Files:**
- Modify: `backend/src/Http/EntryJson.php` (`buildDiscussionRow` and its array shape docblocks)
- Test: `backend/tests/Http/EntryJsonTest.php`

- [ ] **Step 1: Failing tests**
  - `testAPostsLikeCountIsEmitted`: item (Bluesky kind) recordCounts(fromCommentsAndLikes(2, 0)), entry linked → `$json['likeCount'] === 0`; a second copy linked to the same item emits the same.
  - `testAPlainEntryHasNoDiscussionCounts` additionally asserts `likeCount` null.
  - shape test: `'likeCount'` right after `'commentCount'`.
- [ ] **Step 2:** `php bin/phpunit tests/Http/EntryJsonTest.php` → FAIL.
- [ ] **Step 3:** `'likeCount' => $row->entry->selectDiscussionCounts()->getLikeCount()`.
- [ ] **Step 4:** → PASS; then full `composer check`, `composer md`, `php bin/phpunit` → green.
- [ ] **Step 5:** commit `feat(#2362): list rows carry likeCount`.

### Task 4: `entry-meta` shows the like count

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`likeCount: number | null` after `commentCount`), `frontend/src/testing/entry-testing.ts` (`likeCount: null`), `frontend/src/app/core/i18n/discussion-count.ts` (kind union gains `'likes'`), `frontend/src/app/reader/list/entry-meta/entry-meta.component.{ts,html,scss}`, `frontend/public/i18n/{en,de,fr,es}.json`
- Test: `frontend/src/app/reader/list/entry-meta/entry-meta.component.spec.ts`

**Interfaces:**
- Consumes: JSON `likeCount` (Task 3).
- Produces: `EntryMetaComponent.likes` computed via `buildDiscussionCount(…, 'likes')`.

- [ ] **Step 1: Failing tests** (`discussionMeta` helper takes `likeCount`)
  - `shows the like count after the comment bubble`: `{score: null, commentCount: 3, likeCount: 1284}` → `.likes` text `1.3K` (whatever CompactCount gives for the comments case), `.likes` follows `.comments`, aria-label `1,284 likes`-form from `reader.discussionCounts.likesOther`.
  - `labels a single like in the singular`: likeCount 1 → `likesOne` label.
  - `shows zero likes`: likeCount 0 → `.likes` present with `0`.
  - `shows the group for likes alone`: score null, commentCount null, likeCount 2 → `.discussion` present.
  - existing `no group` test sets likeCount null.
- [ ] **Step 2:** `docker compose exec -T frontend npx jest src/app/reader/list/entry-meta` → FAIL.
- [ ] **Step 3:** template adds `<span class="likes" role="img" [attr.aria-label]=…><app-icon name="thumb_up" size="sm" aria-hidden="true" />{{ text }}</span>` after `.comments`; the outer `@if` also checks likes; `.likes` shares the `.comments` rule (one selector list). Keys `reader.discussionCounts.likesOne/likesOther`: en `{{count}} like` / `{{count}} likes`; de `{{count}} Like` / `{{count}} Likes`; fr `{{count}} j'aime` / `{{count}} j'aime`; es `{{count}} me gusta` / `{{count}} me gusta`. Update the component docblock (no longer Lemmy only).
- [ ] **Step 4:** `docker compose exec -T frontend npm run check` → green.
- [ ] **Step 5:** commit `feat(#2362): list rows show a social post's like count`.

### Task 5: Live check

- [ ] Refresh a Bluesky and a Mastodon timeline on the dev stack (migrate first), screenshot a list row and a magazine card on :4200 showing the like count; check today's dev log is free of new errors.
