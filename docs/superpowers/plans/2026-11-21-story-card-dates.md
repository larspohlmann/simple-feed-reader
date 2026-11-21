# Story card dates, one card everywhere — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Trending story's card shows when the story was created and when its last article joined, and one component renders the card on the Trending list, the magazine and the story page.

**Architecture:** The backend gives every card one shape, built by one mapper (`StoryCardJson`) from one model (`StoryFaceModel`), and sends it in the Trending page's stories and as a new `story` key on the story page. The frontend's `StoryTitleComponent` takes that shape and becomes the only card; the story page's hand-built copy goes. A new `StoryDatesComponent` sits in the card's meta slot.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (PHPUnit, PHPStan max, PHPMD), Angular 20 standalone + signals (Jest, Transloco).

**Spec:** `docs/superpowers/specs/2026-11-21-story-card-dates-design.md` (issue #2447)

## Global Constraints

- "Created" = `trending_story.first_joined_at`; "last article" = `trending_story.last_joined_at`. Stored naive UTC; read with `NaiveUtc::parse`; sent as `\DateTimeInterface::ATOM`.
- JSON keys: `createdAt`, `lastAddedAt`. No migration.
- Every existing key of both responses stays (iOS); the story page adds `story`.
- Commits `type(#2447): …`, no attribution lines. PHPMD-clean on every touched `src` file. No new PHPStan ignores or rule edits.
- Strings in en, de, fr, es (`frontend/public/i18n/*.json`).
- Component styles in sibling `.scss`; no hex, no ad-hoc px.

## Review Focus

- A story built once: `createdAt` equals `lastAddedAt`; the card shows only "created …", never "last article" with the same time. (Task 4 test.)
- A story page whose story has no title: `story` is `null`, top-level keys unchanged, no card rendered — as today. (Task 2 test.)
- The card on the story page must not link to the page it is on. (Task 5 test.)
- Narrow cards (phone, story-counts container below its breakpoint): the dates line wraps on its own line and never pushes the counts' glyph fallback. (Live check, Task 6.)
- Follow on the story page still toggles through `StoryFollowing` and Hide still closes the layer (`provideStoryLayerActions`), now through the shared card. (Task 5 test, existing spec kept green.)

---

### Task 1: Backend — lifespan and the card's face

**Files:**
- Create: `backend/src/Service/Curation/Trending/Model/StoryLifespanModel.php`
- Create: `backend/src/Service/Curation/Trending/Model/StoryFaceModel.php`
- Modify: `backend/src/Service/Curation/Trending/Model/RankedStoryModel.php` (required `StoryLifespanModel $lifespan` after `$lastSeen`, before `$heading`)
- Modify: `backend/src/Repository/TrendingStoryRepository.php` (`rankedForUser` selects `story.first_joined_at, story.last_joined_at`; new `requireLifespan`)
- Modify: `backend/src/Service/Curation/Trending/Model/TrendingStoryCardModel.php` (`face()`)
- Modify every `new RankedStoryModel(` in `backend/tests` (TrendingJsonTest, StoryCardSourceTest, …)
- Test: `backend/tests/Repository/TrendingStoryRepositoryTest.php`, `backend/tests/Service/Curation/Trending/Model/TrendingStoryCardModelTest.php` (create or extend)

**Interfaces:**
- Produces:
  - `final readonly class StoryLifespanModel { __construct(public \DateTimeImmutable $createdAt, public \DateTimeImmutable $lastAddedAt) }`
  - `final readonly class StoryFaceModel { __construct(public int $storyId, public StoryHeadingModel $heading, public StorySourcesModel $sources, public bool $followed, public StoryLifespanModel $lifespan) }`
  - `TrendingStoryRepository::requireLifespan(int $storyId): StoryLifespanModel` — throws `TrendingStoryNotFoundException` when no row
  - `TrendingStoryCardModel::face(): StoryFaceModel` — heading is the stored one, else `new StoryHeadingModel(<lead entry title>, null)` (the fallback `TrendingJson` applies today); sources `new StorySourcesModel($story->outletCount, $articles->shareCount())`

- [ ] **Step 1: Failing tests**
  - `testRankedStoriesCarryTheirFirstAndLastJoin`: store a story with `first_joined_at` `2031-05-14 08:00:00`, `last_joined_at` `2031-05-14 11:00:00`; `rankedForUser(...)[0]->lifespan` has `createdAt == new \DateTimeImmutable('2031-05-14 08:00:00')`, `lastAddedAt == ...11:00:00`.
  - `testRequireLifespanThrowsForAMissingStory`: `expectException(TrendingStoryNotFoundException::class)` on id `999999`.
  - `testFaceFallsBackToTheLeadTitleWithoutAHeading` and `testFaceCarriesHeadingSourcesFollowAndLifespan` on `TrendingStoryCardModel::face()`: assert `storyId`, `heading->title`, `heading->synopsis`, `sources->outletCount`, `sources->shareCount`, `followed`, and the same `lifespan` instance.
- [ ] **Step 2:** `php bin/phpunit --filter 'TrendingStoryRepositoryTest|TrendingStoryCardModelTest'` → FAIL (class/method missing).
- [ ] **Step 3:** Implement the above; fix every `new RankedStoryModel(` in tests to pass a lifespan.
- [ ] **Step 4:** Same command → PASS; `php bin/phpunit --filter Trending` → PASS.
- [ ] **Step 5:** Commit `feat(#2447): a ranked story carries its first and last join, and a card its face`.

### Task 2: Backend — one card mapper for both responses

**Files:**
- Create: `backend/src/Http/StoryCardJson.php`
- Modify: `backend/src/Http/TrendingJson.php` (inject `StoryCardJson`; `story()` = card fields + `viewed`, `leadEntryId`, `articles`)
- Modify: `backend/src/Service/Curation/Trending/Model/TrendingStoryEntriesModel.php` (add `int $storyId` first and `StoryLifespanModel $lifespan`; method `face(): ?StoryFaceModel`, null when `$heading` is null, `followed` = `$lastOpenedAt !== null`)
- Modify: `backend/src/Service/Curation/Trending/TrendingStoryEntries.php` (pass `$query->storyId` and `$this->stories->requireLifespan($query->storyId)`)
- Modify: `backend/src/Http/TrendingStoryPage.php` (add `'story' => face ? StoryCardJson::from : null`, docblock shape)
- Test: `backend/tests/Http/StoryCardJsonTest.php` (create), `backend/tests/Http/TrendingJsonTest.php`, functional test of `GET` story entries (find with `grep -rln TrendingStoryEntriesController backend/tests`)

**Interfaces:**
- Consumes: Task 1's models.
- Produces: `StoryCardJson::from(StoryFaceModel $face): array{id: int, title: string, synopsis: string|null, outletCount: int, shareCount: int, followed: bool, createdAt: string, lastAddedAt: string}`

- [ ] **Step 1: Failing tests**
  - `StoryCardJsonTest::testMapsEveryCardField`: face (id 12, `Hafenstreik in Hamburg`, `Die Arbeit ruht.`, sources 4/3, followed true, lifespan 08:00/11:00 on 2031-05-14) → exact array with `createdAt '2031-05-14T08:00:00+00:00'`, `lastAddedAt '2031-05-14T11:00:00+00:00'`.
  - `TrendingJsonTest`: a story carries `createdAt` and `lastAddedAt`; existing assertions unchanged.
  - Functional: a titled story's page has `story` equal to the card shape with both dates and `followed` matching the follow; an untitled story's page has `story === null` and still `outletCount`/`shareCount`.
- [ ] **Step 2:** `php bin/phpunit --filter 'StoryCardJsonTest|TrendingJsonTest|TrendingStoryEntries'` → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Same → PASS; `composer check && composer md` clean on touched files.
- [ ] **Step 5:** Commit `feat(#2447): Trending cards and the story page send one card shape with its dates`.

### Task 3: Frontend — the card shape

**Files:**
- Modify: `frontend/src/app/reader/trending/trending.models.ts`
- Modify: `frontend/src/app/reader/state/open-story.ts` (+ spec), `frontend/src/app/reader/state/list-page.ts` if it picks story-page keys
- Test: `frontend/src/app/reader/state/open-story.spec.ts`

**Interfaces:**
- Produces:
  - `export interface StoryCard { id: number; title: string; synopsis: string | null; outletCount: number; shareCount: number; followed: boolean; createdAt: string; lastAddedAt: string }`
  - `TrendingStoryDto extends StoryCard` keeping `viewed`, `leadEntryId`, `articles` (drop its now-inherited fields and move their doc comments onto `StoryCard`)
  - `StorySources = Pick<StoryCard, 'outletCount' | 'shareCount'>`, `StoryRef = Pick<StoryCard, 'id' | 'followed'>`
  - `StoryPageDto.story: StoryCard | null`
  - `OpenStory.cardOf(id: number): StoryCard | null` replacing `titleOf`, `synopsisOf`, `sourcesOf`; `tallyOf` and `followedOnServer` stay (the header and `StoryFollowing` read them)

- [ ] **Step 1:** Failing spec `cardOf returns the landed page's story card` (page `story` = full card, `cardOf(7)` toEqual it; `cardOf(8)` null; page with `story: null` → null).
- [ ] **Step 2:** `docker compose exec -T frontend npx jest src/app/reader/state/open-story.spec.ts` → FAIL.
- [ ] **Step 3:** Implement; fix every caller of the removed `OpenStory` methods (`grep -rn "titleOf\|synopsisOf\|sourcesOf" frontend/src`).
- [ ] **Step 4:** Same → PASS.
- [ ] **Step 5:** Commit `feat(#2447): one story card shape on the Trending page and the story page`.

### Task 4: Frontend — the dates line

**Files:**
- Create: `frontend/src/app/reader/trending/story-dates/story-dates.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`

**Interfaces:**
- Produces: `app-story-dates` with `readonly story = input.required<Pick<StoryCard, 'createdAt' | 'lastAddedAt'>>()`; locale from `LanguageService.lang()` as `entry-kicker-line.component.ts` does; times via `relativeTime`, hover `title` via `formatLongDateTime`.
- Keys: `reader.storyCreated` en `"created {{time}}"`, de `"erstellt {{time}}"`, fr `"créée {{time}}"`, es `"creada {{time}}"`; `reader.storyLastAdded` en `"last article {{time}}"`, de `"letzter Artikel {{time}}"`, fr `"dernier article {{time}}"`, es `"último artículo {{time}}"`.

- [ ] **Step 1: Failing specs**
  - `shows created and last article`: created 2 days and last added 3 hours before a fixed now → text contains `created` and `last article`, separated by `·`; each span's `title` is the `formatLongDateTime` of its ISO.
  - `shows only created when the story never grew`: `createdAt === lastAddedAt` → no `last article` text, no separator.
- [ ] **Step 2:** `docker compose exec -T frontend npx jest story-dates` → FAIL.
- [ ] **Step 3:** Implement. Styles: block row, `font-size: var(--fs-xs)`, `color: var(--text-muted)`, wraps (no `nowrap` on the host); its own line under the counts.
- [ ] **Step 4:** Same → PASS.
- [ ] **Step 5:** Commit `feat(#2447): a story card's created and last-article times`.

### Task 5: Frontend — one card component everywhere

**Files:**
- Modify: `frontend/src/app/reader/trending/story-title/story-title.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/story-layer/story-layer.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/e2e/trending-story-title.spec.ts` if its selectors change

**Interfaces:**
- Consumes: `StoryCard` (Task 3), `app-story-dates` (Task 4).
- Produces: `app-story-title` with `story = input.required<StoryCard>()` and `linked = input(true)`; renders headline, `app-story-source-count storyMeta`, `app-story-dates storyMeta`, foot with `app-story-actions`. With `linked` false there is no `<a>`; the headline renders in a plain block with the same padding.

- [ ] **Step 1: Failing specs**
  - story-title: `renders the dates under the counts`; `with linked false renders no link` (`querySelector('a')` null, title still shown).
  - story-layer: `the title card is the shared story card` — `app-story-title` present with `story` = `cardOf(id)` and no `a.story-link`; no `.story-card` element left. Existing Follow/Hide specs stay green.
- [ ] **Step 2:** `docker compose exec -T frontend npx jest story-title story-layer` → FAIL.
- [ ] **Step 3:** Story layer's `#headlineTemplate` becomes `@if (card(); as card) { <app-story-title [story]="card" [linked]="false" /> }` with `card = computed(() => this.openStory.cardOf(this.context.selection().id))` and `topBlock` keyed on it; delete `title`, `synopsis`, `sources`, `storyRef` and the `.story-card`/`.story-headline`/`.story-foot` rules and imports it no longer needs. Keep the layer's outer inset (`margin: var(--list-card-inset, 0)`) on `app-story-title`. `provideStoryLayerActions()` stays the layer's provider, so the shared card's actions run through it.
- [ ] **Step 4:** Same → PASS; then `docker compose exec -T frontend npm run check` → PASS.
- [ ] **Step 5:** Commit `refactor(#2447): the story page shows the shared story card`.

### Task 6: Verify end to end

- [ ] `php bin/phpunit` and `docker compose exec php composer test:parallel` (not alongside Jest) → green; `composer infection:diff` → at or above `minMsi`.
- [ ] Scan today's dev log for errors.
- [ ] Live check on localhost:4200: a Trending card and that story's page show the same card with both dates (desktop and 375 px wide); the story page card is not a link; Follow and Hide work on both. Screenshot both for the PR.
