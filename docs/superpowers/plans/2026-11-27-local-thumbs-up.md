# Local Thumbs-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every entry carries the thumbs-up; a post the reader can like on a connected network likes there, everything else stores a local like that feeds recommendations.

**Architecture:** A nullable `likedAt` on `EntryState` holds the local like. `PUT`/`DELETE /api/entries/{id}/like` routes through a new `EntryLiking` service in `Service/Social/Social` that picks the network like (existing `SocialLikes`) or the local one (new `LocalLikes` in `Service/Library/Reading`). The recommendation history reads local likes beside network likes; the SPA's `app-entry-like` always renders a toggle.

**Tech Stack:** Symfony 8.1 / Doctrine (PHP 8.5), Angular 20 signals, Jest, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-27-local-thumbs-up-design.md`

## Global Constraints

- Worktree `.claude/worktrees/2587-local-thumbs-up`, branch `feature/2587-local-thumbs-up`. Run nothing from the main checkout; Docker binds the main checkout, so Jest runs here via `./node_modules/.bin/jest` after `npm ci` (path has no `+`).
- Commits: `type(#2587): lower-case summary`; no attribution lines.
- CLAUDE.md applies in full (naming verbs, `final readonly`, no comments by default, PHPMD-clean touched files, queries only in `src/Repository`, `Response::HTTP_*` never literals).
- "Can like on the network" means exactly what `ConnectedAccountStates::buildSocialStates` computes as `canLike`: the entry is a Bluesky or Mastodon post (`SocialPostAddresses::findPost` non-null, network not Lemmy) and the reader holds an account on that network. One rule, server and client.
- A local like never changes a like count: the API answers `likeCount: null` for it (the SPA already keeps the count on null).
- Datetimes naive UTC (`NaiveUtcClock`).
- Gate cadence per CLAUDE.md "Testing": per task the covering tests + `composer cs`/`composer stan`; the full set once before the PR.

## Review Focus

1. A connected reader liking a Bluesky/Mastodon post must still reach the network — never silently go local. (Task 2 test.)
2. A post on a network the reader has *no* account on likes locally; connecting that account later keeps the local like for recommendations (the thumb then shows the network state), and unliking there clears both. (Task 2 tests.)
3. An entry liked both locally and on the network (liked locally, account connected later, liked there too) appears once in the LIKED history. (Task 3 test.)
4. A backup taken before this change (no `likedAt` key) restores without error; one taken after restores the like. (Task 1 test.)
5. A Lemmy post with a linked Lemmy account shows the vote pill and no thumb; without a linked account it shows the local thumb. (Task 4 spec.)

---

### Task 1: `likedAt` on EntryState and its carriers

**Files:**
- Modify: `backend/src/Entity/EntryState.php`
- Create: `backend/migrations/Version20261127130000.php` (both platforms, like the neighbouring migrations)
- Modify: `backend/src/Repository/EntryListRowMarks.php`, `EntryListRowHydrator.php`, `EntryProjection.php`
- Modify: `backend/src/Http/EntryJson.php` (row shape docblock + `'isLiked' => $row->marks->isLiked`)
- Modify: `backend/src/Service/Library/SavedPosts/SavedStateMover.php::moveFlags`
- Modify: `backend/src/Service/Operations/Backup/Dto/EntryStateLine.php`, `Factory/RestoredEntryStateFactory.php`
- Test: `backend/tests/Entity/EntryStateTest.php`, `tests/Service/Library/SavedPosts/SavedStateMoverTest.php`, the existing `EntryStateLine`/restore tests, an `EntryJson`/entry-list functional test that asserts row keys

**Interfaces — Produces:**
- `EntryState::markLiked(\DateTimeImmutable $when): void` (keeps an earlier `likedAt`), `clearLiked(): void`, `getLikedAt(): ?\DateTimeImmutable`, `isLiked(): bool`
- `EntryListRowMarks::$isLiked: bool` (hydrated from `es.likedAt IS NOT NULL` selected as `esLiked`)
- Entry JSON key `isLiked: bool` — the local like only; `social.isLiked` is untouched.
- Backup line key `likedAt: ?string` (ATOM), absent key reads as null.

- [ ] **Step 1: Failing tests**
  - `testMarkLikedKeepsTheFirstInstant`: mark at T1, mark at T2 → `getLikedAt() == T1`; `clearLiked()` → `isLiked() === false`.
  - `testALocalLikeMovesToTheSavedCopy` in the mover test: source liked at T → target `getLikedAt() == T`, source `isLiked() === false`.
  - Backup: a line without `likedAt` parses with `likedAt === null`; a state liked at T round-trips through `EntryStateLine::fromState()`/restore with `getLikedAt() == T`.
  - Entry list: a locally liked entry's row has `'isLiked' => true`, an untouched one `false`.
- [ ] **Step 2: Run them, expect FAIL** — `php bin/phpunit --filter 'EntryStateTest|SavedStateMoverTest|EntryStateLine|<list test>'`
- [ ] **Step 3: Implement** the column (`DATETIME_IMMUTABLE`, nullable, name `liked_at`), the migration (`up`/`down`, SQLite + MySQL), the carriers above. The mover keeps the earlier of two instants when both copies are liked.
- [ ] **Step 4: Run, expect PASS**; `composer cs`, `composer stan`.
- [ ] **Step 5: Migrate from empty on SQLite** (`DATABASE_URL=sqlite:///%kernel.project_dir%/var/mig.db bin/console doctrine:migrations:migrate -n && bin/console doctrine:schema:validate` with that URL) — expect "in sync".
- [ ] **Step 6: Commit** `feat(#2587): a local like on the entry state`

### Task 2: The like endpoint routes network or local

**Files:**
- Create: `backend/src/Service/Library/Reading/LocalLikes.php`
- Create: `backend/src/Service/Social/Social/EntryLiking.php`
- Modify: `backend/src/Service/Social/Social/SocialLikes.php` (add the predicate) — or a small sibling if PHPMD objects
- Modify: `backend/src/Controller/Api/EntryLikeController.php`
- Test: `backend/tests/Service/Library/Reading/LocalLikesTest.php`, `tests/Service/Social/Social/EntryLikingTest.php`, `tests/Controller/Api/EntryLikeControllerTest.php`

**Interfaces:**
- Consumes: Task 1's `EntryState::markLiked/clearLiked`; `EntryStateResolver::resolve(User, EntryListRow)`; `EntryListRepository::getRowForUser(int, int): EntryListRow`.
- Produces:
  - `LocalLikes::like(User $user, EntryListRow $row): void`, `LocalLikes::unlike(User $user, EntryListRow $row): void` (flushes)
  - `SocialLikes::allowsNetworkLike(User $user, Entry $entry): bool` — the Global Constraints rule
  - `EntryLiking::like(User $user, EntryListRow $row): ?int` / `unlike(...): ?int` — network count, or `null` after a local like

- [ ] **Step 1: Failing tests**
  - Controller, rewrite `testAnOrdinaryArticleIsNoPostToLike` → `testAnOrdinaryArticleIsLikedLocally`: `PUT` → 200, payload `['isLiked' => true, 'likeCount' => null]`, no HTTP client call, the entry list row reads `isLiked: true`; `DELETE` → `['isLiked' => false, 'likeCount' => null]`, row `isLiked: false`.
  - Rewrite `testAPostOnANetworkWithoutAnAccountIsRefusedAndCannotBeLiked` → `testAPostOnANetworkWithoutAnAccountIsLikedLocally`: same assertions, no network call.
  - `testAConnectedReadersLikeStillReachesTheNetwork`: the existing `testALikeReachesTheOriginAndTheEntryReadsAsLiked` stays green and its entry's row has `isLiked: false` (no local like written).
  - `testALocalLikeSurvivesConnectingTheNetwork` (EntryLikingTest): local like on a post, then an account on its network is added → `EntryState::isLiked()` still true and no network call happens.
  - `testANetworkUnlikeAlsoClearsALocalLike`: same setup, then `unlike` → network unlike runs and `EntryState::isLiked()` is false, so the reader is never left with a like the thumb no longer shows.
  - Rate limit still applies to both paths (one existing limiter test, if present, covers the local path too).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Controller: `getRowForUser`, `enforceForUser`, then `EntryLiking`; response through `SocialLikeJson::fromPostLike`. `NotASocialPostException` no longer reaches this endpoint.
- [ ] **Step 4: Run, expect PASS**; `composer cs`, `composer stan` (module-cycle rule: `Social/Social → Library/Reading` must not close a cycle — stan says so if it does).
- [ ] **Step 5: Commit** `feat(#2587): the like endpoint keeps a local like where no network can take it`

### Task 3: Local likes feed the recommendations

**Files:**
- Modify: `backend/src/Repository/ReadingHistoryRepository.php::liked`
- Modify: `backend/src/Repository/EntryLikeDql.php`
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/Support/RecommendationPromptText.php`
- Test: `backend/tests/Repository/ReadingHistoryRepositoryTest.php`, `tests/Repository/RecommendationCandidateRepositoryTest.php`, the prompt-text test if one pins the sentence

**Interfaces:** Consumes Task 1's `likedAt`. Produces nothing new.

- [ ] **Step 1: Failing tests**
  - `testALocallyLikedArticleIsLiked`: an article liked locally at T sits in `liked()` ordered by T among network likes.
  - `testAFavoriteOrSharedLocalLikeStaysOutOfLiked`: matches the existing exclusion for network likes.
  - `testAnEntryLikedLocallyAndOnTheNetworkIsListedOnce`.
  - `testALocallyLikedEntryIsNoCandidate` in the candidate repository test.
  - `kept()`/`viewed()` exclude a locally liked entry (they use `EntryLikeDql::exists`) — one test.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** `liked()`: a third query over `EntryState` with `likedAt IS NOT NULL`, joined to a followed subscription, same favorite/shared exclusion; merge, dedupe by entry id keeping the newest instant, sort, cap. `EntryLikeDql::exists()`: `OR EXISTS (SELECT 1 FROM EntryState … WHERE ….entry = <entry alias> AND ….user = :user AND ….likedAt IS NOT NULL)`. Prompt: "LIKED holds the posts and articles the reader liked." (drops "or upvoted on a social network"; upvotes stay in the section).
- [ ] **Step 4: Run, expect PASS**; `composer cs`, `composer stan`.
- [ ] **Step 5: Commit** `feat(#2587): local likes count as liked in the recommendation history`

### Task 4: The SPA toggle on every entry

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`EntryDto.isLiked: boolean`, plus every test fixture builder that types `EntryDto`)
- Modify: `frontend/src/app/reader/entry/entry-like/entry-like.component.{ts,html}`
- Modify: `frontend/src/app/reader/state/entry-row-cache.ts::setLiked`, `frontend/src/app/reader/shell/entry-state-actions.service.ts::like`
- Modify: `frontend/public/i18n/{en,fr,es}.json`
- Test: `entry-like.component.spec.ts`, `entry-row-cache.spec.ts`

**Interfaces:** Consumes the API: `PUT/DELETE /api/entries/{id}/like` → `{isLiked, likeCount: null}` for a local like; entry key `isLiked`.

- [ ] **Step 1: Failing specs**
  - An article (`social: null`, `myVote: null`) renders a toggle button with the `thumb_up` icon, unfilled; with `isLiked: true` filled, `aria-pressed` true, label "Unlike".
  - A post with `social.canLike: false` and `likeCount: 7` renders the local toggle **and** the count 7; liking it leaves 7.
  - A post with `social.canLike: true` behaves exactly as today (network labels).
  - A Lemmy post with `myVote !== null` renders nothing; with `myVote: null` the local toggle.
  - `setLiked` on a local entry optimistically flips `isLiked` (not `social.isLiked`), rolls back on error, leaves `likeCount` untouched.
- [ ] **Step 2: Run, expect FAIL** — `./node_modules/.bin/jest src/app/reader/entry/entry-like src/app/reader/state/entry-row-cache`
- [ ] **Step 3: Implement.** `entry-state-actions.like` passes `!isEntryLiked(entry)` where `isEntryLiked = likeableSocial(entry)?.isLiked ?? entry.isLiked` (add next to `likeableSocial` in `entry-social.ts`). i18n keys: `reader.like.local` "Like" / fr "J'aime" / es "Me gusta"; `reader.unlike.local` "Unlike" / "Je n'aime plus" / "Ya no me gusta"; `reader.actionHint.like.local` "Like — kept here, it shapes your For you picks" / "J'aime — gardé ici, cela oriente vos suggestions Pour vous" / "Me gusta — se guarda aquí y orienta tus sugerencias Para ti"; `reader.actionHint.unlike.local` "Take back your like" / "Retirer votre j'aime" / "Quitar tu me gusta". Check the glossary for the For you name in fr/es before committing.
- [ ] **Step 4: Run, expect PASS**; `./node_modules/.bin/eslint` + `prettier --check` on changed files.
- [ ] **Step 5: Commit** `feat(#2587): the thumbs-up on every entry`

### Task 5: A "Liked" view in the sidebar

Added on Lars's request mid-run: "also add a 'liked' entry to the sidebar".

**Files:**
- Modify: `backend/src/Enum/EntryView.php` (`case Liked = 'liked';`)
- Modify: `backend/src/Repository/EntryScopePredicates.php::applyView` (`EntryView::Liked` → `EntryLikeDql::exists($aliases)`, which after Task 3 covers network likes, Lemmy upvotes and local likes)
- Modify: the sidebar counts query beside the shared count (`EntryStateRepository` / wherever `sharedCount` comes from) and its `*Json` mapper — a `likedCount`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-views.component.{ts,html}` (the first card: All items, Shared, **Liked**, Recently read), the view type/route union in the SPA, `subscriptions.store.ts` (`likedCount` + `bumpLiked(by)`), `entry-state-actions.service.ts::like` (bump ±1 with rollback on failure, like `keep`)
- Modify: `frontend/public/i18n/{en,fr,es}.json`
- Test: `tests/Repository/EntryScopePredicates*` or the entry-list functional test, the counts test, `sidebar-views.component.spec.ts`, `entry-state-actions.service.spec.ts`

**Interfaces:** Consumes Task 1's `likedAt`, Task 3's `EntryLikeDql::exists`, Task 4's `isEntryLiked(entry)`. Produces `GET /api/entries?view=liked`, counts key `liked`.

- [ ] **Step 1: Failing tests**
  - `view=liked` lists a locally liked article, a network-liked post and an upvoted Lemmy post; it omits an unliked entry and another user's like.
  - Order is the default published-date order (`EntryListSort::forView` default) — Ruling below.
  - The sidebar counts answer `liked: 3` for that fixture.
  - The sidebar renders "Liked" with the `thumb_up` icon between Shared and Recently read, links to `/?view=liked`, shows the count when > 0.
  - Liking an entry bumps the count by 1, unliking by −1, a failed request takes the bump back.
  - Unliking a row while in the Liked view removes it from the list, as un-favoriting does in Favorites.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** i18n: en "Liked", fr "J'aime" (check the glossary; "Mentions J'aime" if the glossary uses it), es "Me gusta".
- [ ] **Step 4: Run, expect PASS**; backend cs/stan; frontend eslint/prettier on changed files.
- [ ] **Step 5: Commit** `feat(#2587): a liked view in the sidebar`

Ruling: Liked orders by published date, not like time — three like sources (network, upvote, local) have no single instant column to keyset on; cost if wrong: a follow-up adding a liked-at sort.
