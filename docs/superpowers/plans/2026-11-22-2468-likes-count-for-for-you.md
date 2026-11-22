# Likes and upvotes count for For you (#2468) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Posts the reader liked (Bluesky, Mastodon) or upvoted (Lemmy) become a fifth reading-history section, LIKED, that the For you profile is distilled from.

**Architecture:** `ReadingHistoryRepository` gains `liked()`, read from the stored `social_like` and `discussion_vote` rows through the canonical item to the reader's entries; `kept()` and `viewed()` leave liked entries out. The section travels the path SHARED took in #2007: `RecommendationHistoryCaps` → `RecommendationHistoryLoader` → `RecommendationHistoryModel` → `ProfileInputFingerprint` and the distill prompt. No migration, no API change, no frontend change.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM (DQL), PHPUnit 12; SQLite natively, MySQL in Docker.

**Spec:** `docs/superpowers/specs/2026-11-22-likes-count-for-for-you-design.md`

## Global Constraints

- Section order and weight: FAVORITES, SHARED, LIKED, KEPT, VIEWED. An entry appears in its highest section only.
- `RecommendationHistoryCaps::LIKED_CAP = 40`, fixed; no setting, no JSON field.
- Only `VoteScore::Up` counts. Downvotes, comment votes and like counts are out.
- The scoring call (Jev), the batch call and the consolidation call stay unchanged: profile and FAVORITES only.
- `CLAUDE.md` rules apply to every task: verb-first method names (test helpers included), no abbreviations, queries only in `src/Repository`, default to no comment, `final`, PHPMD-clean touched `src` files, no new PHPStan ignores, no edits under `tests/PhpStan`.
- Commit format `type(#2468): message`, no attribution lines. Run commands from `backend/`.
- Before each commit: `composer cs`, `composer stan` (warm cache first: `bin/console cache:warmup`), `composer md` — all clean for the files the task touches.

## Review Focus

- **An entry without a canonical item (every RSS article)** stays in KEPT and VIEWED: the liked check compares a NULL item and must not drop it. Pinned in Task 1.
- **Another reader's like of the same post** neither puts it in my LIKED nor takes it out of my KEPT. Pinned in Task 1.
- **An upvote recast to a downvote** leaves LIKED and returns to KEPT or VIEWED. Pinned in Task 1.
- **A liked post whose only copy sits in a feed the reader left** has no line. Pinned in Task 1.
- **Likes and upvotes interleaved in time** come back in one cast order, and the cap cuts the merged list, not each table. Pinned in Task 1.

---

### Task 1: The liked history read

**Files:**
- Modify: `backend/src/Repository/ReadingHistoryRepository.php`
- Create: `backend/tests/Support/LikedPostFixtures.php`
- Test: `backend/tests/Repository/ReadingHistoryRepositoryTest.php`

**Interfaces:**
- Consumes: `SocialLike` (`canonicalItem`, `user`, `likedAt`), `DiscussionVote` (`canonicalItem`, `user`, `score`, `votedAt`), `Entry::getCanonicalItem()` / `Entry::linkTo(CanonicalItem)`, `EntryShareDql::exists()`, `App\Enum\VoteScore`.
- Produces:
  - `ReadingHistoryRepository::liked(int $userId, int $cap): list<TitledEntry>`
  - `LikedPostFixtures::linkNewItem(EntityManagerInterface $entityManager, Entry $entry, CanonicalItemKind $kind, string $canonicalKey): CanonicalItem` — builds the item with `CanonicalItems::buildItem`, persists it, links the entry.
  - `LikedPostFixtures::persistLike(EntityManagerInterface $entityManager, User $user, CanonicalItem $item, \DateTimeImmutable $likedAt): SocialLike` — through a persisted `SocialAccounts::mastodonOf($user)` account, one per user per test (reuse it when the user already has one).
  - `LikedPostFixtures::persistVote(EntityManagerInterface $entityManager, User $user, CanonicalItem $item, VoteScore $score, \DateTimeImmutable $votedAt): DiscussionVote` — through a persisted `SocialAccounts::lemmyOf($user)` account, same reuse.

**Decisions:**
- `liked()` makes two reads, one rooted at `SocialLike` (instant `likedAt`), one at `DiscussionVote` with `score = VoteScore::Up` (instant `votedAt`). Each joins the entries whose `canonicalItem` is the liked item, their feed, and the reader's `Subscription`; orders by the instant descending, then `e.id` descending; takes `$cap` rows. PHP merges the two lists by instant descending, then entry id descending, and cuts to `$cap`. Rooting at the like tables keeps both reads on `idx_social_like_user` / `uniq_discussion_vote_user_canonical_item`.
- A row counts only when its entry is the reader's lowest-id subscribed copy of that item (no subscribed entry with the same item and a lower id), is not a favorite (`LEFT JOIN EntryState`, as `shared()` does) and is not shared (`NOT EntryShareDql::exists`).
- `kept()` and `viewed()` add one condition: the entry's item carries neither the reader's like nor their upvote. One private builder in the repository produces that DQL for both; it is not a new class.

- [ ] **Step 1: Write `LikedPostFixtures`** with the three methods above (`final`, private constructor, like `EntryShareFixtures`).

- [ ] **Step 2: Write the failing tests** in `ReadingHistoryRepositoryTest` (existing `setUp` gives `$this->user`, `$this->feed`; add a second subscribed feed where a test needs a copy). Kinds: `CanonicalItemKind::Mastodon` for likes, `CanonicalItemKind::Lemmy` for votes.

```php
public function testLikesAndUpvotesComeBackInOneCastOrder(): void
// three entries: liked 10:00, upvoted 11:00, liked 12:00
// liked($userId, 10) ids === [third, upvoted, first]

public function testTheLikedCapCutsTheMergedList(): void
// likes at 10:00 and 12:00, upvotes at 11:00 and 13:00
// liked($userId, 3) ids === [upvote 13:00, like 12:00, upvote 11:00]

public function testTwoLikesFromTheSameInstantComeBackHighestIdFirst(): void

public function testADownvoteIsNotLiked(): void
// VoteScore::Down: liked() === [], and the kept entry stays in kept()

public function testAnUpvoteRecastToADownvoteReturnsToKept(): void
// kept entry, upvoted: kept() === [], liked() === [entry]
// $vote->recast(VoteScore::Down, …), flush: liked() === [], kept() === [entry]

public function testALikedFavoriteIsNotInLiked(): void
public function testALikedSharedEntryIsNotInLiked(): void
// EntryShareFixtures::share(...): shared() holds it, liked() === []

public function testALikedEntryLeavesKeptAndViewed(): void
// one liked+kept entry, one liked+viewed entry: kept() === [], viewed() === []

public function testAnEntryWithoutAnItemStaysInKeptAndViewed(): void
// plain kept entry and plain viewed entry, while another entry is liked: both still returned

public function testAStrangersLikeIsNotMine(): void
// a second User likes my kept entry's item: my liked() === [], my kept() === [entry]

public function testTwoCopiesOfALikedPostGiveOneLine(): void
// second subscribed feed, both entries linked to one item:
// liked() ids === [the lower entry id]

public function testALikedPostInAFeedTheReaderLeftHasNoLine(): void
// entry in a feed without a Subscription for $this->user: liked() === []
```

- [ ] **Step 3: Run them to verify they fail**

Run: `php bin/phpunit tests/Repository/ReadingHistoryRepositoryTest.php`
Expected: FAIL — `Call to undefined method …ReadingHistoryRepository::liked()`

- [ ] **Step 4: Implement `liked()` and the kept/viewed condition** in `ReadingHistoryRepository` as decided above. Update the class docblock's section list (favorite, shared, liked, kept, viewed; liked orders by `likedAt` / `votedAt`).

- [ ] **Step 5: Run the tests on both databases**

Run: `php bin/phpunit tests/Repository/ReadingHistoryRepositoryTest.php tests/Service/Curation/Recommendation/Pool`
Run: `docker compose exec -T php php bin/phpunit tests/Repository/ReadingHistoryRepositoryTest.php tests/Service/Curation/Recommendation/Pool`
Expected: PASS on both (if the Docker stack is down, say so in the report instead of starting it).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Repository/ReadingHistoryRepository.php backend/tests/Support/LikedPostFixtures.php backend/tests/Repository/ReadingHistoryRepositoryTest.php
git commit -m "feat(#2468): read the posts a reader liked or upvoted as history"
```

---

### Task 2: LIKED in the history model, the caps and the fingerprint

**Files:**
- Modify: `backend/src/Entity/RecommendationHistoryCaps.php`
- Modify: `backend/src/Service/Curation/Recommendation/Pool/Model/RecommendationHistoryModel.php`
- Modify: `backend/src/Service/Curation/Recommendation/Pool/RecommendationHistoryLoader.php`
- Modify: `backend/src/Service/Curation/Recommendation/Profile/Support/ProfileInputFingerprint.php`
- Test: `backend/tests/Service/Curation/Recommendation/Pool/RecommendationHistoryLoaderTest.php`
- Test: `backend/tests/Service/Curation/Recommendation/Profile/Support/ProfileInputFingerprintTest.php`
- Test: `backend/tests/Service/Curation/Recommendation/Profile/Model/ProfileInputsModelTest.php`

**Interfaces:**
- Consumes: `ReadingHistoryRepository::liked(int $userId, int $cap): list<TitledEntry>` and `LikedPostFixtures` from Task 1.
- Produces:
  - `RecommendationHistoryCaps::LIKED_CAP = 40` and a fifth promoted constructor parameter `public int $liked = self::LIKED_CAP` (after `$shared`).
  - `RecommendationHistoryModel` fifth promoted parameter `public array $liked = []` (`list<ArticleLineModel>`, after `$shared`); `isEmpty()` is false when only `liked` holds lines.
  - Fingerprint JSON gains `'liked' => entry ids`, and `'caps'` becomes `[favorites, shared, liked, kept, viewed]`.

- [ ] **Step 1: Write the failing tests**

`RecommendationHistoryLoaderTest` (give its `settings()` helper a `likedCap` argument beside `sharedCap`):

```php
public function testALikedPostAppearsInLikedNewestLikedFirst(): void
// two liked entries: titles of $history->liked === ['Newer', 'Older']

public function testALikedAndKeptEntryAppearsInLikedNotKept(): void
// titles of liked === ['Both'], kept === []

public function testTheLikedSectionReadsItsOwnCap(): void
// three likes, settings(likedCap: 2): titles === the two newest
```

`ProfileInputFingerprintTest`:

```php
public function testTheLikedSectionChangesIt(): void
// history with liked: self::lines([15]) vs. without → assertNotSame

public function testTheLikedCapChangesIt(): void
// new RecommendationHistoryCaps(40, 40, 80, 40, 41) vs. $this->caps() → assertNotSame
```

`ProfileInputsModelTest`:

```php
public function testAHistoryOfOnlyLikesIsNotEmpty(): void
// new ProfileInputsModel(new RecommendationHistoryModel([], [], [], [], [$line]), [])->isEmpty() === false
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Curation/Recommendation/Pool tests/Service/Curation/Recommendation/Profile`
Expected: FAIL — unknown named parameter `$liked` / undefined property.

- [ ] **Step 3: Implement** the four `src` changes. The loader passes `liked: $this->articleLines->loadLines($this->history->liked($userId, $caps->liked))`. Update the docblocks that name the sections (`RecommendationHistoryCaps`, `RecommendationHistoryModel`: "five sections … shared beats liked, liked beats kept", `RecommendationHistoryLoader`, `ProfileInputFingerprint`).

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Curation/Recommendation tests/Http/RecommendationSettingsJsonTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#2468): liked posts are a history section and part of the profile fingerprint"
```

---

### Task 3: LIKED in the distill prompt, and the docs

**Files:**
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/RecommendationPromptBuilder.php` (`historySections()`, and the docblocks at `distillMessages()` and the batch-sections method that list "SHARED, KEPT and VIEWED")
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/Support/RecommendationPromptText.php` (`DISTILL_ROLE`)
- Modify: `docs/features.md` (the "For you" section's first bullet)
- Modify: `docs/recommendations-runs.md` ("The profile" paragraph)
- Test: `backend/tests/Service/Curation/Recommendation/Llm/Prompt/RecommendationPromptBuilderTest.php`

**Interfaces:**
- Consumes: `RecommendationHistoryModel::$liked` from Task 2.
- Produces: the distill user message carries `LIKED (newest first):` between SHARED and KEPT.

**Exact copy** — the opening of `DISTILL_ROLE` up to the shared-article note becomes:

> You read one reader's history from an RSS reader and write a short preference profile for them. The user message holds five sections — FAVORITES, SHARED, LIKED, KEPT and VIEWED, newest first — and, when the reader has any, a SAVED SEARCHES section ahead of them: the search terms they saved to keep following a subject. LIKED holds the posts the reader liked or upvoted on a social network. SAVED SEARCHES and FAVORITES weigh strongest, SHARED next, then LIKED, then KEPT, VIEWED least.

The rest of the constant is unchanged.

- [ ] **Step 1: Update and add the failing tests**

```php
// testTheDistillRoleWeighsSavedSearchesWithFavourites: expect
'SAVED SEARCHES and FAVORITES weigh strongest, SHARED next, then LIKED, then KEPT, VIEWED least'

// testTheDistillRoleNamesTheSharedSection: expect
'five sections — FAVORITES, SHARED, LIKED, KEPT and VIEWED'

public function testTheDistillRoleSaysWhatLikedHolds(): void
// 'LIKED holds the posts the reader liked or upvoted on a social network.'

// testDistillMessagesReturnsTheExactRoleContentStructure: history gains
// liked: [self::line(5, 'Liked one', 10)], and $expectedUser gains, between SHARED and KEPT,
"LIKED (newest first):\n- Liked one — Example Feed — 2026-08-01 — " . str_repeat('x', 10)

public function testAnEmptyLikedSectionReadsNone(): void
// distill user message contains "LIKED (newest first):\n- none"
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Curation/Recommendation/Llm/Prompt/RecommendationPromptBuilderTest.php`
Expected: FAIL on the five tests above.

- [ ] **Step 3: Implement** the prompt change and the copy above; fix any other test in the tree that pins the old distill wording or the four-section user message.

- [ ] **Step 4: Update the docs.** `docs/features.md`: "A ranked selection of your unread articles, based on what you favorite, share, keep, and read, and on the posts you like or upvote on your social networks." `docs/recommendations-runs.md`: the history list in "The profile" names shared entries and liked or upvoted posts (40 each, fixed) beside favourites, kept and viewed.

- [ ] **Step 5: Run the tests**

Run: `php bin/phpunit tests/Service/Curation/Recommendation`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/tests docs/features.md docs/recommendations-runs.md
git commit -m "feat(#2468): the profile prompt weighs liked posts between shared and kept"
```

---

## After the tasks (controller)

- Whole-branch review, `/simplify`, then the gates: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`, `composer infection:diff`.
- A real profile run against the configured provider: the profile debug log shows the LIKED section and the run completes.
- PR into `develop` with `Closes #2468`; merge when CI is green.
