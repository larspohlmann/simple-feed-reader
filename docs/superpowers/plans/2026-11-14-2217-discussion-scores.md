# Lemmy post and comment scores — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the score and comment count of a Lemmy post, and each Lemmy comment's score, and show them on list rows, magazine cards, the article header and the comments panel.

**Architecture:** A `DiscussionCounts` value travels from `LemmyPostHeader` through `ParsedEntryModel` onto two new nullable columns in the `EntryDiscussion` embeddable; a second pass after each fetch refreshes stored entries. `EntryCommentModel` gains `?int $score` from Lemmy's `counts.score`. In the SPA, one `VotePillComponent` (framed / bare) draws up-arrow · score · down-arrow, inert for now; #2218 makes its arrows buttons.

**Tech Stack:** Symfony 8.1 / PHP 8.5 / Doctrine (SQLite + MySQL), Angular 20 standalone + signals, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-14-2217-discussion-scores-design.md`

## Global Constraints

- Lemmy only. Reddit, Hacker News, Substack, comment feeds, Mastodon/Bluesky replies: unchanged; their `score` / `commentCount` are `null`.
- Entry JSON fields: `score`, `commentCount` (`number | null`). Comment JSON field: `score` (`number | null`).
- Columns: `score`, `comment_count` — nullable `INT` on `entry`.
- Icons: `arrow_upward`, `arrow_downward`, `chat_bubble`. Numbers compact (`Intl.NumberFormat(lang, { notation: 'compact' })`), tabular.
- Arrows inert in this issue: `aria-hidden`, dimmed with the pill-toggle's disabled opacity (`0.7`). The pill is one `role="img"` with label "N points".
- Transloco keys in **all four** dictionaries (`frontend/public/i18n/{en,de,fr,es}.json`), plural pairs via `pluralKey` (`…One` / `…Other`).
- Clean Code rules of `CLAUDE.md` apply; `composer check`, `composer md` clean on every touched `src` file; `npm run check` green.

## Review Focus

1. **A later fetch whose header no longer parses** (Lemmy changes its RSS) must not wipe stored numbers: an unknown parse leaves the stored counts alone. → Task 3 test `testUnknownCountsKeepTheStoredOnes`.
2. **Score 0 and negative scores** must still render (`0`, `-3`), not be treated as "no score". → Task 1 header data set already has `-3`; Task 5 test `rendersZeroAndNegativeScores`; Task 6/7 templates test `score !== null`, never truthiness.
3. **A non-Lemmy feed** must not pay a guid lookup per fetch for counts it never has. → Task 3 test `testFeedsWithoutCountsSkipTheLookup`.
4. **The Discussion (N) link** must prefer the loaded comment total over the stored count once comments load (the stored one is up to one fetch stale). → Task 7 test.
5. **A comment whose JSON lacks `counts`** (older Lemmy, a trimmed answer) yields `score: null`, not 0. → Task 4 test.

---

### Task 1: `DiscussionCounts` from the Lemmy header to the parsed entry

**Files:**
- Create: `backend/src/Entity/DiscussionCounts.php`
- Modify: `backend/src/Service/Ingestion/Ingest/Model/LemmyFeedPostModel.php`, `backend/src/Service/Ingestion/Ingest/Support/LemmyPostHeader.php`, `backend/src/Service/Ingestion/Parser/Model/ParsedEntryModel.php`, `backend/src/Service/Ingestion/Ingest/PlatformEntryRule/LemmyEntryRule.php`
- Test: `backend/tests/Entity/DiscussionCountsTest.php`, `backend/tests/Service/Ingestion/Ingest/Support/LemmyPostHeaderTest.php`, `backend/tests/Service/Ingestion/Ingest/PlatformEntryRule/LemmyEntryRuleTest.php`

**Interfaces:**
- Produces: `final readonly class App\Entity\DiscussionCounts { public ?int $score; public ?int $commentCount; static unknown(): self; static of(?int $score, ?int $commentCount): self; isUnknown(): bool }` (lives in `App\Entity` because the entity stores it, §8). `LemmyFeedPostModel::$counts: DiscussionCounts` (fourth promoted parameter). `ParsedEntryModel::$discussionCounts: DiscussionCounts` (non-promoted, set to `unknown()` in the constructor, like `$discussion`) and `#[\NoDiscard] withDiscussionCounts(DiscussionCounts $counts): self`.

- [ ] **Step 1: Write the failing tests**
  - `DiscussionCountsTest`: `unknown()->isUnknown()` is true; `of(5, null)->isUnknown()` false; `of(null, null)->isUnknown()` true.
  - `LemmyPostHeaderTest::testReadsTheScoreAndTheCommentCount` over the existing `scores()` provider extended with the expected score: `'535 points | '` → 535, `'-3 points | '` → -3, `'1 point | '` → 1; the fixture's `3 comments` link → `commentCount` 3. Add `testACommentLinkWithoutANumberLeavesTheCountUnknown` (`<a …>comments</a>` → `commentCount` null, `score` still read). Also `'1 comment'` → 1.
  - `LemmyEntryRuleTest::testCarriesTheCountsOntoTheEntry`: applied entry's `discussionCounts` equals `of(535, 3)`.

- [ ] **Step 2: Run them to see them fail**
  Run: `cd backend && php bin/phpunit --filter 'DiscussionCountsTest|LemmyPostHeaderTest|LemmyEntryRuleTest'`
  Expected: FAIL (class `DiscussionCounts` not found).

- [ ] **Step 3: Implement**
  `LemmyPostHeader` reads the score from node 5 (`/^\s*(-?\d+) points? \|/`) and the count from node 6's text (`/^\s*(\d+) comments?\s*$/`, else null). `LemmyEntryRule::apply` adds `->withDiscussionCounts($post->counts)`.

- [ ] **Step 4: Run the tests** — same command, Expected: PASS.

- [ ] **Step 5: Commit** — `git commit -m "feat(#2217): read a Lemmy post's score and comment count"`

### Task 2: Store the counts on the entry

**Files:**
- Create: `backend/migrations/Version20261114100000.php`
- Modify: `backend/src/Entity/EntryDiscussion.php`, `backend/src/Entity/Entry.php`, `backend/src/Service/Ingestion/Ingest/Factory/IngestedEntryFactory.php`
- Test: `backend/tests/Entity/EntryTest.php` (or the existing entity test that covers `setDiscussion`), `backend/tests/Service/Ingestion/Ingest/Factory/IngestedEntryFactoryTest.php`

**Interfaces:**
- Consumes: `DiscussionCounts`, `ParsedEntryModel::$discussionCounts` (Task 1).
- Produces: `Entry::getDiscussionCounts(): DiscussionCounts`; `Entry::recordDiscussionCounts(DiscussionCounts $counts): void` — an unknown value is ignored (keeps what is stored). Columns `score` / `comment_count` on `EntryDiscussion` (`#[ORM\Column(nullable: true)] ?int`).

- [ ] **Step 1: Failing tests**
  - `testRecordsDiscussionCounts`: record `of(12, 4)` → `getDiscussionCounts()` equals it.
  - `testAnUnknownRecordKeepsTheStoredCounts`: record `of(12, 4)` then `unknown()` → still `of(12, 4)`.
  - `testANewEntryHasUnknownCounts`.
  - Factory: an incoming parsed entry with `of(7, 2)` → created entry carries them.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'EntryTest|IngestedEntryFactoryTest'` — FAIL.
- [ ] **Step 3: Implement** the embeddable fields and the two `Entry` methods; the factory calls `recordDiscussionCounts($parsed->discussionCounts)` after `setDiscussion`. Migration in the shape of `Version20261113140000` (skipIf column exists, `abortIfUnsupportedPlatform`, non-transactional): `ALTER TABLE entry ADD COLUMN score INT DEFAULT NULL` and `… comment_count INT DEFAULT NULL`; `down` drops both. No FTS triggers involved (`entry` is altered, not rebuilt).
- [ ] **Step 4: Run** the tests — PASS. Verify the migration on both dialects:
  `cd backend && APP_ENV=test DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate -n && APP_ENV=test DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate` then `rm var/migrate-check.db`; and `docker compose exec php bin/console doctrine:migrations:migrate -n && docker compose exec php bin/console doctrine:schema:validate`. Expected: `[OK] The database schema is in sync with the mapping files.`
- [ ] **Step 5: Commit** — `feat(#2217): store a discussion's score and comment count on the entry`

### Task 3: Refresh stored entries' counts on every fetch, and emit them

**Files:**
- Modify: `backend/src/Service/Ingestion/Ingest/EntryIngestor.php`, `backend/src/Service/Ingestion/Refresh/IncomingPageStore.php:55`, `backend/src/Http/EntryJson.php:53,152-158`, `backend/tests/Support/JsonMappers.php` if it pins the row shape
- Test: `backend/tests/Service/Ingestion/Ingest/RefreshDiscussionCountsTest.php` (beside `FillMissingImagesTest`, same set-up), `backend/tests/Http/EntryJsonTest.php`

**Interfaces:**
- Consumes: `Entry::recordDiscussionCounts`, `getDiscussionCounts` (Task 2), `PlatformEntryRules::apply`.
- Produces: `EntryIngestor::refreshDiscussionCounts(Feed $feed, ParsedFeedModel $parsed): void`, called in `IncomingPageStore::store` right after `fillMissingImages`. Entry JSON gains `score: int|null, commentCount: int|null` (inside `discussionOf`, its array shape updated, and the `EntryRow` alias at line 53).

- [ ] **Step 1: Failing tests**
  - `testUpdatesTheCountsOfAStoredEntry`: stored Lemmy entry at `of(10, 1)`, fresh parse of the same guid with header `42 points | … 9 comments` → `of(42, 9)`.
  - `testUnknownCountsKeepTheStoredOnes` (Review Focus 1).
  - `testFeedsWithoutCountsSkipTheLookup` (Review Focus 3): a repository double expecting `findByFeedIndexedByGuidHash` `$this->never()` for a parse with no Lemmy entries.
  - `EntryJsonTest`: a row whose entry has `of(-3, 0)` emits `'score' => -3, 'commentCount' => 0`; a plain entry emits both `null`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'RefreshDiscussionCountsTest|EntryJsonTest'` — FAIL.
- [ ] **Step 3: Implement** — apply the platform rules to each parsed entry, keep those whose counts are known (return early if none), one `findByFeedIndexedByGuidHash` with their guid hashes, `recordDiscussionCounts` on each match. Reuse the private `guidHashesOf` / `guidHash`.
- [ ] **Step 4: Run** the tests — PASS; `composer md` on `EntryIngestor.php` clean (if it trips, stop and report rather than splitting the class to dodge the count — CLAUDE.md #2025).
- [ ] **Step 5: Commit** — `feat(#2217): refresh discussion counts on every fetch and send them with the entry`

### Task 4: A Lemmy comment's score

**Files:**
- Modify: `backend/src/Service/Content/Comments/Model/EntryCommentModel.php`, `backend/src/Service/Content/Comments/Pass/LemmyThread.php:97-103`, `backend/src/Http/CommentsJson.php:36-50`
- Test: `backend/tests/Service/Content/Comments/CommentsSource/LemmyCommentsSourceTest.php`, `backend/tests/Http/CommentsJsonTest.php`

**Interfaces:**
- Produces: `EntryCommentModel::$score: ?int` (last promoted parameter, default `null`) and `#[\NoDiscard] withScore(int $score): self`; comment JSON `'score' => int|null`.

- [ ] **Step 1: Failing tests**
  - Lemmy source: a comment node with `"counts": {"score": 17}` → model `score` 17; `"score": 0` → 0; a node without `counts` → null (Review Focus 5).
  - `CommentsJsonTest`: emits `score`; a comment-feed comment emits `'score' => null`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'LemmyCommentsSourceTest|CommentsJsonTest'` — FAIL.
- [ ] **Step 3: Implement** — `LemmyThread::commentOrNull` reads `$node->node('counts')->int('score')` and applies `withScore` when not null.
- [ ] **Step 4: Run** — PASS. Then the whole backend gate: `composer check && composer md && php bin/phpunit` and `docker compose exec php composer test:parallel` — all green.
- [ ] **Step 5: Commit** — `feat(#2217): carry a Lemmy comment's score`

### Task 5: `VotePillComponent` and a shared compact count

**Files:**
- Create: `frontend/src/app/core/i18n/compact-count.ts` (+ `.spec.ts`), `frontend/src/app/reader/vote-pill/vote-pill.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/article/social-counts/social-counts-row.component.ts:58-60,113-115` (use the shared formatter), `frontend/public/i18n/{en,de,fr,es}.json`

**Interfaces:**
- Produces: `@Injectable({ providedIn: 'root' }) class CompactCount { format(count: number): string }` reading `LanguageService.lang()` (a signal, so templates re-render on language change). `VotePillComponent` — selector `app-vote-pill`, inputs `score = input.required<number>()`, `variant = input<'framed' | 'bare'>('framed')`; host class `bare` for the bare variant. Keys `reader.discussionCounts.pointsOne` / `pointsOther` ("{{count}} point" / "{{count}} points") and `commentsOne` / `commentsOther` ("{{count}} comment" / "{{count}} comments"), translated in de/fr/es (de: Punkt/Punkte, Kommentar/Kommentare; fr: point/points, commentaire/commentaires; es: punto/puntos, comentario/comentarios).

- [ ] **Step 1: Failing tests** (`vote-pill.component.spec.ts`)
  - `rendersTheScoreBetweenTwoArrows`: 1284 → `.number` text `1.3K` (en), icons `arrow_upward` then `arrow_downward`, both `aria-hidden="true"`.
  - `rendersZeroAndNegativeScores` (Review Focus 2): `0` → `0`, `-3` → `-3`.
  - `labelsThePillForScreenReaders`: `role="img"`, `aria-label` `1 point` for 1 and `1,284 points` — the label carries the full number, not the compact one.
  - `marksTheBareVariant`: `variant="bare"` → host has class `bare`.
  - `compact-count.spec.ts`: `format(1284)` is `1.3K` for `en`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/vote-pill src/app/core/i18n/compact-count` — FAIL.
- [ ] **Step 3: Implement.** Styles in the `.scss`: framed = `pill-toggle` geometry without `cursor: pointer` (ring `--border-strong`, `--radius-pill`), each arrow half padded `var(--space-1) var(--space-2)`, number padded `0 var(--space-0)`, `font-variant-numeric: tabular-nums`, `font-size: var(--fs-sm)`, `color: var(--text-muted)`; arrows `opacity: 0.7`. Bare = no ring, arrow padding `0 var(--space-1)`. Arrows are `<app-icon size="sm">` in `<span class="arrow">` — the element #2218 replaces with a `<button>`.
- [ ] **Step 4: Run** the specs plus `social-counts-row` specs — PASS.
- [ ] **Step 5: Commit** — `feat(#2217): add the vote pill`

### Task 6: List rows and magazine cards

**Files:**
- Modify: `frontend/src/app/reader/models.ts:271-274` (`score: number | null; commentCount: number | null;` with a one-line doc each), `frontend/src/testing/entry-testing.ts` (`score: null, commentCount: null` in `anEntry`), `frontend/src/app/reader/list/entry-meta/entry-meta.component.{ts,html,scss,spec.ts}`

**Interfaces:**
- Consumes: `VotePillComponent`, `CompactCount`, keys from Task 5.

- [ ] **Step 1: Failing tests** (`entry-meta.component.spec.ts`)
  - `showsTheScoreAndCommentCountFirst`: entry `score: 1284, commentCount: 212` → the host's first child is a `.discussion` group holding `app-vote-pill` and a `.comments` count `212` with a `chat_bubble` icon and `aria-label` `212 comments`; `app-entry-pills` and `app-entry-actions` follow.
  - `showsTheVotePillAloneWithoutACommentCount`: `commentCount: null` → no `.comments`.
  - `showsNothingWithoutAScore`: `score: null` → no `.discussion`.
  - `showsAZeroScore`: `score: 0` → `app-vote-pill` present.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/list/entry-meta` — FAIL.
- [ ] **Step 3: Implement** the `.discussion` group before the pills (`display: inline-flex; align-items: center; gap: var(--space-4); font-size: var(--fs-sm); color: var(--text-muted);`), so the line reads counts · pills · actions (actions keep `margin-inline-start: auto`).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(#2217): show a Lemmy post's score and comments on list rows and cards`

### Task 7: Article header and the Discussion (N) link

**Files:**
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.{html,scss,spec.ts}` (`pageHead`, lines ~58-65 and `.tags`), `frontend/src/app/reader/article/entry-comments/entry-discussion-link.component.ts` (+ its spec, create one if absent)

**Interfaces:**
- Consumes: `VotePillComponent` (framed), `EntryDto.score`, `EntryDto.commentCount`.

- [ ] **Step 1: Failing tests**
  - reader-view: an entry with `score: 463` → an `app-vote-pill` in the head line before `app-entry-pills`; `score: null` → none.
  - discussion link: comments state not `ok`, `commentCount: 38` → link text contains `(38)`; once the state is `ok` with 2 comments and 1 omitted → `(3)` (Review Focus 4); `commentCount: null` and not loaded → no number.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/article/reader-view src/app/reader/article/entry-comments/entry-discussion-link` — FAIL.
- [ ] **Step 3: Implement** — wrap the vote pill and `app-entry-pills` in one `.head-line` (`display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); margin: 0 0 var(--space-3);`), moving `.tags`' margin onto it. `commentCount` computed falls back to `entry.commentCount` when the state is not `ok`.
  The link reader renders a linked page, not the entry, so it gets nothing.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(#2217): show a Lemmy post's score in the article header`

### Task 8: Comment scores in the byline

**Files:**
- Modify: `frontend/src/app/reader/entry-comment.ts` (`score: number | null;`), `frontend/src/testing/entry-testing.ts` (`anEntryComment`: `score: null`), `frontend/src/app/reader/article/composer/sent-comment.ts:14` (`score: null`), `frontend/src/app/reader/article/entry-comments/entry-comments.component.{html,scss,spec.ts}`

**Interfaces:**
- Consumes: `VotePillComponent` with `variant="bare"`.

- [ ] **Step 1: Failing tests** (`entry-comments.component.spec.ts`)
  - `showsACommentsScoreAtTheEndOfItsByline`: a comment with `score: 87` → `.byline app-vote-pill.bare` with `87`, after the time.
  - `showsNoScoreForCommentsWithout`: `score: null` → none.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/article/entry-comments` — FAIL.
- [ ] **Step 3: Implement** — `<app-vote-pill class="score" variant="bare" [score]="c.score" />` after the time and before the delete button; `.score { margin-inline-start: auto; }`.
- [ ] **Step 4: Run** — PASS; then `docker compose exec -T frontend npm run check` — green.
- [ ] **Step 5: Commit** — `feat(#2217): show a Lemmy comment's score`

### Task 9: Live check

- [ ] Subscribe the dev account to the Lemmy.World *technology* community feed (`https://lemmy.world/feeds/c/technology.xml`; the dev DB has no Lemmy feed yet) through the app, wait for the fetch, and on http://localhost:4200 check in dark and light theme: magazine card footer, list row, article header, comments byline, phone width (375 px). Compare against the agreed mockups in `.superpowers/brainstorm/49630-1794652656/content/` (`scores-real-posts.html` option A, `scores-article-comments.html` option B).
- [ ] Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) for deprecations and errors.
