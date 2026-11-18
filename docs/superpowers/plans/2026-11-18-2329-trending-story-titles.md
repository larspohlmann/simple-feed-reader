# Trending Story Titles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every Trending story carries its own headline: a representative member headline chosen at build time, replaced by an LLM-written title when the reader has a chat-model connection.

**Architecture:**
- The **fallback** is chosen inside the build, while the `TrendingRun` still holds the window's weighted terms (`HeadlineWindow::sharedWeightsWithEarlier`). Scoring happens in memory. The text comes from one repository read of the candidates' titles and their feeds' titles. It is stored on `trending_story` with the member it came from, so the next week build can keep it steady.
- The **LLM title** is the first task of a new `Curation/StoryAi` module, which holds the shared plumbing for story AI features:
  - per-task connections: each task resolves its own (`StoryAiTaskInterface::connectionFor`). Titles use the story **writing** connection (chat models), independent of any feature switch.
  - a per-user `StoryAiPass` and one recorded `StoryAiCalls::complete()`, through `RecommendationProviderCall` (`RateLimitedCompletion` plus 429 handling)
  - a generic `story_ai_run` with a `task` column, so cost receipts and usage land where the other runs' land
  - one sweep, `RunDueStoryAiTasks`, which the worker runs every 5 minutes (the rebuild cadence) and the maintenance tick runs right after the Trending rebuild
  Trending implements `StoryAiTaskInterface` for titles. A later task (for example article finding) plugs in the same way; see *Extending*.
- The API's `title` is `llm_title ?? fallback_title ?? lead row title`, so it is never null. The followed-story sidebar and page heading read the same story title through `TrendingStoryFollowRepository::followedBy()`.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Doctrine DBAL in repositories, PHPUnit 12 (SQLite natively, MySQL in Docker), Angular 20 signals, Jest in Docker.

**Spec:** GitHub issue #2329 (`gh issue view 2329`). It is blocked by #2328 (merged, stories per user). The look is agreed: variant "C3 without count", signed off on the real renders in `docs/superpowers/mockups/2329-story-kicker/real-*.png` (title card already built, committed before Task 1).

## Global Constraints

- The spec's decisions are settled; do not reopen them. Each story belongs to one user, so its title does too.
- Fallback: the outlet article in the story's majority language whose headline shares the most weighted terms with the other members. If the story has no outlet article, the representative link card's title. Steady while still a candidate and scoring **within 80 %** of the best. A candidate without markers is preferred.
- Cleaning: strip the outlet name at the end, matched against **that feed's own title**. Strip ticker and label prefixes from the per-language list in Task 2.
- LLM: write in the user's **UI language** (`User::getLocale()`), with the user's **story writing connection** (the spec's "story connection"). Unset (the default) means the active configuration. Only chat models (`AiReadiness::of()` and `RecommendationEngineKind::Llm`).
- LLM input: **up to 12** member headlines, **one per outlet, newest first**, each with its **~300-character** body snippet (`HeadlineBody`). Output: the strict JSON schema `{title}`. Neutral and factual, **≤ ~90 characters**, no outlet names, no clickbait, no trailing punctuation.
- A schema failure or an empty reply leaves the fallback in place. Regenerate when the member count has grown **≥ 50 %** since the title was written, and attempt each story **at most once per hour**. Never in a reader's request.
- Story titles are **on by default**, with a switch in the AI settings to turn them off.
- Project rules (CLAUDE.md) are binding:
  - Queries go only in `src/Repository`. Domain code knows no HTTP; `*Json` mappers shape responses.
  - Models go in `Model/`. A factory builds and never persists.
  - PHPMD stays clean on every touched `src` file, phptramp passes, PHPStan max.
  - Every migration has a MySQL and a SQLite path, and is verified by a migrate from empty plus `doctrine:schema:validate` on both.
- Commits are `feat(#2329): …` (or `fix`/`refactor` where true), with no attribution. Branch `feature/2329-trending-story-titles`. One PR into `develop` with `Closes #2329`.
- Frontend: the title card (`frontend/src/app/reader/trending/story-title/`, `title: string` on `TrendingStoryDto`, placement in `magazine-block.component.html` and `entry-list.component.html`) is **already built and signed off on a real render** by another session. Do not rebuild or restyle it. Task 11 wires and verifies it only.
- YAGNI: build no article-finding or other second story task. The plan only keeps the seams open for one (see *Extending*).
- i18n: en, de, fr, es are all first-class. German uses the loanword "Story".

## Review Focus

1. **Day build flip-flop.** The day build sees fewer members than the week build. If both re-chose the fallback, the title would flip twice a day. The expected behaviour: only the week build re-chooses; the day build titles only stories that have none yet. Pinned in Task 4 (`testTheDayBuildKeepsAFallbackTheWeekChose`).
2. **A story built before this deploy** has a null `fallback_title` until its next build. Cards must still show a title, and a followed story outside the week must keep its newest-member headline. Pinned in Task 1 (`testAStoryWithoutStoredTitlesShowsItsLeadHeadline`) and Task 5 (`testAStoryWithoutStoredTitlesKeepsTheNewestMemberHeadline`).
3. **A failing or rate-limited provider** must not be called on every sweep. Expected: a failed or unusable attempt waits an hour. A 429 stops that user's sweep without stamping, so the deferral is not billed and the story stays due. Pinned in Task 9 (`testAnUnusableReplyIsNotRetriedWithinTheHour`, `testARateLimitStopsTheUsersSweepWithoutStamping`).
4. **An over-greedy suffix strip** could eat a real headline tail ("Merz – der Kanzler") or leave nothing. Expected: strip only when every word of the tail is in the feed title and one of them passes `HeadlineStems::isKept` (three or more letters, or a number of two or more digits), and never strip down to empty. Pinned in Task 2.
5. **A deleted or switched-off writing connection.** Deleting the chosen connection falls back to the active one. A scoring-model connection writes nothing, and the fallback stays. Pinned in Task 6 (`testDeletingTheWritingConnectionFallsBackToTheActiveOne`) and Task 9 (`testAScoringConnectionWritesNoTitle`).

---

### Task 1: Title storage and the story's `title` in the Trending API

**Files:**
- Create: `backend/migrations/Version20261118130000.php`
- Modify: `backend/src/Entity/TrendingStory.php`
- Create: `backend/src/Service/Curation/Trending/Model/StoryTitleModel.php`
- Modify: `backend/src/Repository/TrendingStoryRepository.php`
- Modify: `backend/src/Service/Curation/Trending/Model/RankedStoryModel.php`
- Modify: `backend/src/Service/Curation/Trending/Pass/StoryCardSource.php`
- Modify: `backend/src/Service/Curation/Trending/TrendingStories.php`
- Modify: `backend/src/Http/TrendingJson.php`
- Test: `backend/tests/Repository/TrendingStoryRepositoryTest.php`, `backend/tests/Service/Curation/Trending/Pass/StoryCardSourceTest.php`, `backend/tests/Http/TrendingJsonTest.php`, `backend/tests/Controller/Api/TrendingControllerTest.php`

**Interfaces:**
- Produces:
  - Columns on `trending_story`, all nullable:
    - `fallback_title VARCHAR(1024)`
    - `fallback_entry_id INT` (no foreign key: a deleted entry only fails the membership check)
    - `llm_title VARCHAR(255)`
    - `llm_title_language VARCHAR(5)`
    - `llm_title_member_count INT`
    - `llm_title_attempted_at DATETIME`
  - `final readonly class StoryTitleModel { __construct(public string $text) }`
  - `TrendingStoryRepository::titlesOf(list<int> $storyIds): array<int, StoryTitleModel>` returns `COALESCE(llm_title, fallback_title)` per story and leaves out stories that have neither.
  - `RankedStoryModel` gains `public ?StoryTitleModel $title = null` and `#[\NoDiscard] titled(?StoryTitleModel $title): self`.
  - JSON: the story gains `title: string`.

- [ ] **Step 1: Write the failing tests**
  - `TrendingStoryRepositoryTest::testTitlesPreferTheLlmTitle`: one story with `fallback_title = 'Hafenstreik in Hamburg'` and `llm_title = 'Hafenarbeiter in Hamburg streiken'`, and a second story with only the fallback. Assert `titlesOf([$first, $second])` gives `[$first => 'Hafenarbeiter in Hamburg streiken', $second => 'Hafenstreik in Hamburg']` (compare `->text`).
  - `TrendingStoryRepositoryTest::testTitlesLeaveOutAStoryWithoutTitles`: assert `titlesOf([$untitled]) === []`.
  - `StoryCardSourceTest::testACardCarriesItsStoryTitle`: assert `$cards[0]->story->title?->text === 'Hafenstreik in Hamburg'`.
  - `TrendingJsonTest::testAStoryCarriesItsTitle`: assert `$json['stories'][0]['title'] === 'Hafenstreik in Hamburg'`.
  - `TrendingJsonTest::testAStoryWithoutStoredTitlesShowsItsLeadHeadline`: the story has no title. Assert `title` equals the lead row's entry title.
  - `TrendingControllerTest::testTheDayPageNamesEachStory`: seed `fallback_title`, `GET /api/trending?window=day`, and assert `stories[0].title`.

- [ ] **Step 2: Run them and see them fail**
  Run `php bin/phpunit --filter 'testTitles|testACardCarriesItsStoryTitle|testAStoryCarriesItsTitle|testAStoryWithoutStoredTitlesShowsItsLeadHeadline|testTheDayPageNamesEachStory'`. Expected: FAIL (an unknown method or column).

- [ ] **Step 3: Implement**
  - **Migration** `Version20261118130000`:
    - `skipIf` when `trending_story` already has `fallback_title`.
    - MySQL: one `ALTER TABLE trending_story ADD …` with the six columns.
    - SQLite: one `ALTER TABLE trending_story ADD COLUMN …` per column. `trending_story` carries no FTS trigger, so it needs no rebuild.
    - `down` drops them, using `DROP COLUMN` per column on SQLite.
    - Follow `Version20261118120000`'s `upMysql`/`upSqlite`/`requireSqlite` shape.
  - **Entity:** map the six columns on `TrendingStory` as nullable properties initialised to `null`, with no accessors. The DBAL repositories are their only reader and writer, like `firstSeen`.
  - **`StoryCardSource`:** takes a fifth lookup, `array<int, StoryTitleModel> $titlesByStory`, and passes `$story->titled($this->titlesByStory[$story->storyId] ?? null)` to `TrendingStoryCardModel::fromRows`. Update its "four lookups" docblock to five.
  - **`TrendingStories::sourceFor`** reads `$this->stories->titlesOf($storyIds)`.
  - **`TrendingJson::story`:** add `title: string` to its `@return` array shape, and `'title' => $card->story->title->text ?? $articles->lead->row->entry->getTitle()`. Use the accessor `EntryJson` uses for the row's title.

- [ ] **Step 4: Run the tests and see them pass**
  Run the same filter. Expected: PASS. Then run `php bin/console doctrine:migrations:migrate --no-interaction` and `doctrine:schema:validate` against SQLite and against Docker MySQL (`docker compose exec php …`). Expected: "The database schema is in sync".

- [ ] **Step 5: Commit**
  `git commit -m "feat(#2329): store story titles and name each story in the Trending API"`

---

### Task 2: Headline cleaning

**Files:**
- Create: `backend/src/Service/Curation/Trending/HeadlineMarkers.php`
- Create: `backend/src/Service/Curation/Trending/Model/CleanedHeadlineModel.php`
- Test: `backend/tests/Service/Curation/Trending/HeadlineMarkersTest.php`

**Interfaces:**
- Produces:
  - `final readonly class CleanedHeadlineModel { __construct(public string $text, public bool $markerFree) }`
  - `final readonly class HeadlineMarkers` is an injected root service: its label list is curated, so it is a tuned verdict (§10), not a `Support/` helper. It has `clean(string $headline, ?string $feedTitle, ?FeedLanguage $language): CleanedHeadlineModel`. `markerFree` is false when a ticker or label prefix was stripped. An outlet suffix is not a marker: it is only stripped.

Prefix labels are matched case-insensitively at the start, followed by optional whitespace (a normal or no-break space), then `:`, then whitespace. A language of `null` applies every list.

```php
private const array LABELS = [
    'en' => ['Live', 'Live updates', 'Liveblog', 'Live blog', 'Breaking', 'Breaking news', 'Exclusive', 'Update',
        'Updated', 'Watch', 'Video', 'Analysis', 'Opinion', 'Explainer'],
    'de' => ['Liveblog', 'Live', 'Liveticker', 'Live-Ticker', 'Ticker', 'Eilmeldung', 'Eil', 'Exklusiv', 'Breaking',
        'Kommentar', 'Analyse', 'Video', 'Update', 'News'],
    'fr' => ['En direct', 'Direct', 'Live', 'Exclusif', 'Info exclusive', 'Alerte info', 'Dernière minute', 'Urgent',
        'Breaking', 'Vidéo', 'Analyse', 'Décryptage', 'Tribune'],
    'es' => ['En directo', 'En vivo', 'Directo', 'Última hora', 'Urgente', 'Exclusiva', 'Exclusivo', 'Breaking',
        'Vídeo', 'Video', 'Análisis', 'Opinión'],
];
```

Every language also strips a ticker run of two or more `+` at the start and at the end (`+++ … +++`). That also counts as a marker.

Outlet suffix rule:
- The tail is the text after the last ` - `, ` – `, ` — `, ` | ` or ` · `.
- Strip it when every word of the tail (words split on non-letters and non-digits, compared with `HeadlineStems::bare`) occurs among the feed title's words, and at least one tail word passes `HeadlineStems::isKept`.
- Never strip when the rest would be shorter than 3 characters. Strip once.

- [ ] **Step 1: Write the failing tests** (a data provider of `[headline, feedTitle, language, expectedText, expectedMarkerFree]`)
  - `['Hafenstreik legt Hamburg lahm – Spiegel', 'DER SPIEGEL - Schlagzeilen', de, 'Hafenstreik legt Hamburg lahm', true]`
  - `['Hafenstreik legt Hamburg lahm | tagesschau.de', 'tagesschau.de - Die Nachrichten der ARD', de, 'Hafenstreik legt Hamburg lahm', true]`
  - `['Merz – der Kanzler', 'DER SPIEGEL', de, 'Merz – der Kanzler', true]` ("kanzler" is not in the feed title)
  - `['Liveblog: Hafenstreik in Hamburg', 'NDR', de, 'Hafenstreik in Hamburg', false]`
  - `['+++ Hafenstreik in Hamburg +++', 'NDR', de, 'Hafenstreik in Hamburg', false]`
  - `['EN DIRECT : grève au port du Havre', 'Le Monde', fr, 'grève au port du Havre', false]`
  - `['Última hora: huelga en el puerto', 'El País', es, 'huelga en el puerto', false]`
  - `['Breaking: Port strike in Hamburg - BBC News', 'BBC News - World', en, 'Port strike in Hamburg', false]`
  - `['Spiegel', 'DER SPIEGEL', de, 'Spiegel', true]` (no separator, nothing stripped)
  - `['Exklusiv: Hafenstreik', null, null, 'Hafenstreik', false]` (a null language applies every list)
  - `['Update – Spiegel', 'Spiegel', de, 'Update', true]` (the rest has 3 or more characters, so the strip is allowed, and "Update" carries no colon, so it is no label)

- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit tests/Service/Curation/Trending/HeadlineMarkersTest.php`. Expected: FAIL (the class is not found).
- [ ] **Step 3: Implement `HeadlineMarkers::clean`** with the lists and the rule above. The test builds it with `new HeadlineMarkers()`.
- [ ] **Step 4: Run them and see them pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2329): clean outlet names and ticker labels off headlines`

---

### Task 3: What the build needs to choose a fallback

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Model/StoryMemberModel.php`
- Modify: `backend/src/Service/Curation/Trending/Pass/TrendingRun.php`
- Modify: `backend/src/Service/Curation/Trending/Model/StoredStoryModel.php`
- Modify: `backend/src/Repository/TrendingStoryHistoryRepository.php` (`storedInWindow`)
- Modify: `backend/src/Repository/TrendingEntryRepository.php`
- Create: `backend/src/Service/Curation/Trending/Model/MemberHeadlineModel.php`
- Test: `backend/tests/Service/Curation/Trending/Pass/TrendingRunTest.php`, `backend/tests/Repository/TrendingStoryHistoryRepositoryTest.php`, `backend/tests/Repository/TrendingEntryRepositoryTest.php`

**Interfaces:**
- Produces:
  - `StoryMemberModel` gains `public ?FeedLanguage $language = null, public bool $fromAnOutlet = false, public ?string $urlHash = null`. They are appended, so existing positional callers stay valid. `TrendingRun::memberOf()` fills them: `fromAnOutlet` is `$entry->links->articleUrl !== null`, `language` is `$entry->language`, and `urlHash` is `$entry->links->urlHash` (it counts a post once across timelines, as the card does).
  - `StoredStoryModel` gains `public ?int $fallbackEntryId = null, public bool $titled = false`. `storedInWindow` selects `story.fallback_entry_id` and `story.fallback_title IS NOT NULL`.
  - `final readonly class MemberHeadlineModel { __construct(public int $entryId, public string $title, public ?string $feedTitle) }`
  - `TrendingEntryRepository::headlinesOf(list<int> $entryIds): array<int, MemberHeadlineModel>` reads `entry.title` and `feed.title`, chunked by `INSERT_ROWS`-sized `IN (?)` lists as `TrendingStoryHistoryRepository::earliestJoinsOf` does.
  - `TrendingEntryRepository::linkTitlesOf(list<int> $sharedLinkIds): array<int, string>` reads `shared_link.title`.

- [ ] **Step 1: Write the failing tests**
  - `TrendingRunTest::testAMemberKnowsItsLanguageAndWhetherAnOutletPublishedIt`: an article entry with language `de` and a Mastodon post. Assert `memberOf($article)->language === FeedLanguage::German`, `->fromAnOutlet === true`, `memberOf($post)->fromAnOutlet === false`, and `memberOf($post)->urlHash` equals the post's url hash.
  - `TrendingStoryHistoryRepositoryTest::testStoredStoriesCarryTheirFallbackMember`: the story has `fallback_entry_id = $entry->requireId()` and a title. Assert the `StoredStoryModel` has `fallbackEntryId === $entry->requireId()` and `titled === true`. An untitled story gives `null` and `false`.
  - `TrendingEntryRepositoryTest::testHeadlinesOfCarryTheirFeedsTitle`: feed title `'DER SPIEGEL'`, entry title `'Hafenstreik – Spiegel'`. Assert `headlinesOf([$id])[$id]` equals `new MemberHeadlineModel($id, 'Hafenstreik – Spiegel', 'DER SPIEGEL')`.
  - `TrendingEntryRepositoryTest::testLinkTitlesOfReadTheSharedLinks`: assert `linkTitlesOf([$linkId]) === [$linkId => 'Port strike in Hamburg']`.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit --filter 'testAMemberKnowsItsLanguage|testStoredStoriesCarryTheirFallbackMember|testHeadlinesOfCarryTheirFeedsTitle|testLinkTitlesOfReadTheSharedLinks'`. Expected: FAIL.
- [ ] **Step 3: Implement.** `TrendingRun` keeps three more per-entry arrays (`array<int, ?FeedLanguage>`, `array<int, true>` for outlets and `array<int, string>` for url hashes), beside `feedOfEntry`.
- [ ] **Step 4: Run them and see them pass.** Same filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2329): carry language, outlet and fallback member into the build`

---

### Task 4: Choose and store the fallback at build time

**Files:**
- Create: `backend/src/Service/Curation/Trending/Support/CentroidScores.php`
- Create: `backend/src/Service/Curation/Trending/Support/MostShared.php`
- Create: `backend/src/Service/Curation/Trending/FallbackChoice.php`
- Create: `backend/src/Service/Curation/Trending/Model/TitleCandidateModel.php`
- Create: `backend/src/Service/Curation/Trending/Model/FallbackTitleModel.php`
- Create: `backend/src/Service/Curation/Trending/FallbackTitles.php`
- Modify: `backend/src/Service/Curation/Trending/Model/StoryAssignmentModel.php`, `Model/StoryMatchModel.php`, `Model/TrendingStoryCardModel.php` (`mostShared` uses `MostShared::pick`)
- Modify: `backend/src/Service/Curation/Trending/StoryBuildPolicy/StoryBuildPolicyInterface.php`, `DayStoryBuildPolicy.php`, `WeekStoryBuildPolicy.php`
- Modify: `backend/src/Service/Curation/Trending/SnapshotBuilder/TrendingSnapshotBuilder.php`
- Modify: `backend/src/Repository/TrendingStoryHistoryRepository.php` (`insertStory`, `updateStory`)
- Test: `backend/tests/Service/Curation/Trending/Support/CentroidScoresTest.php`, `Support/MostSharedTest.php`, `FallbackChoiceTest.php`, `StoryBuildPolicy/*Test.php`, `SnapshotBuilder/TrendingSnapshotBuilderTest.php`, `backend/tests/Support/TrendingWiring.php`

**Interfaces:**
- Consumes:
  - `StoryMemberModel::$language`, `$fromAnOutlet` and `$urlHash`
  - `StoredStoryModel::$fallbackEntryId` and `$titled`
  - `TrendingEntryRepository::headlinesOf()` and `linkTitlesOf()`
  - `HeadlineMarkers::clean()` (injected)
- Produces:
  - `final readonly class TitleCandidateModel { __construct(public int $entryId, public CleanedHeadlineModel $headline, public float $score, public int $effectiveTime) }`
  - `final readonly class FallbackTitleModel { __construct(public ?int $entryId, public string $title) }`. `entryId` is null for a link-card title.
  - `CentroidScores::of(HeadlineWindow $headlines, list<int> $memberIds): array<int, float>` (Support: it only computes)
  - `MostShared::pick(non-empty-list<T> $newestFirst, \Closure(T): int $shareCount): T` (Support) is today's rule from `TrendingStoryCardModel::mostShared`, defined once: the most shares, with a tie going to the earliest in newest-first order. The card model and `FallbackTitles` both call it.
  - `final readonly class FallbackChoice` is a root service: the 80 % stickiness and the marker-free precedence are a tuned verdict (§10). It has `choose(non-empty-list<TitleCandidateModel> $candidates, ?int $currentEntryId): TitleCandidateModel`.
  - `FallbackTitles`, injected into the two policies, has two methods:
    - `titledWhereMissing(TrendingRun $run, StoryMatchModel $match, list<StoredStoryModel> $stored): StoryMatchModel`, for the day build
    - `titledAll(TrendingRun $run, StoryMatchModel $match, list<StoredStoryModel> $stored): StoryMatchModel`, for the week build
  - `StoryBuildPolicyInterface::match(TrendingRun $run, list<TrendingStoryModel> $groups, list<StoredStoryModel> $stored): StoryMatchModel`. It now takes the run:
    - `DayStoryBuildPolicy` returns `$this->fallbackTitles->titledWhereMissing($run, $this->matching->attach($groups, $stored), $stored)`.
    - `WeekStoryBuildPolicy` uses `reshape` and then `titledAll`.
    - The policy is the switch, so no boolean method is needed, and the builder keeps its 9 constructor parameters (PHPMD `ExcessiveParameterList`).
  - `StoryAssignmentModel` gains `public ?FallbackTitleModel $fallbackTitle = null` and `#[\NoDiscard] titled(FallbackTitleModel $title): self`.
  - `StoryMatchModel` gains `#[\NoDiscard] withAssignments(list<StoryAssignmentModel> $assignments): self`.

Score: the sum of the weight a member shares with every other member, which reuses the grouping's measure. `sharedWeightsWithEarlier` also counts body terms; the spec's "weighted terms" is read as that measure.

```php
foreach ($memberIds as $entryId) {
    foreach (array_intersect_key($headlines->sharedWeightsWithEarlier($entryId), $isMember) as $earlierId => $weight) {
        $scores[$entryId] += $weight;
        $scores[$earlierId] += $weight;
    }
}
```

Choice (the 80 % rule):

```php
$pool = array_filter($candidates, fn ($candidate) => $candidate->headline->markerFree) ?: $candidates;
$best = the $pool member with the highest [score, effectiveTime, entryId];
$current = the candidate whose entryId is $currentEntryId, if any;
if ($current !== null && ($current->headline->markerFree || !$best->headline->markerFree)
    && $current->score >= 0.8 * $best->score) {
    return $current;
}
return $best;
```

`FallbackTitles`, per assignment:
- `titledWhereMissing` leaves an assignment untouched when its stored story is titled.
- Otherwise the candidates are the outlet members in the story's majority language. Count non-null languages only. A tie goes to the language of the newest outlet member. When no outlet member has a language, every outlet member is a candidate.
- The title is the candidate's `$this->markers->clean(title, feedTitle, language)->text`.
- **With no outlet member** — the representative card, today's rule:
  - group the members whose `articleSite` and `sharedLinkId` are non-null by `sharedLinkId`, and order the cards newest-first by their newest holder
  - `MostShared::pick` chooses among them, counting distinct `urlHash` (once per post)
  - the title is that card's link title, uncleaned, with `entryId` null
  - with no such card either, the candidates are every member, scored the same way
- Read every needed title in one `headlinesOf` and one `linkTitlesOf` call per build, never per story.

`TrendingSnapshotBuilder::buildLocked` reads `$stored` into a variable and calls `$policy->match($run, $groups, $stored)` before `unset($run)`. The `unset` moves below the match, and the peak memory then holds both (accepted; measured in Step 4). The history repository writes `fallback_title` (cut to 1024 with `mb_substr`) and `fallback_entry_id` on insert, and on update when `$assignment->fallbackTitle !== null`. `insertStory` and `updateStory` take the assignment instead of its group.

- [ ] **Step 1: Write the failing tests**
  - `CentroidScoresTest::testTheMemberSharingMostWithTheOthersScoresHighest`: three entries, A "Hafenstreik Hamburg Container", B "Hafenstreik Hamburg", C "Hafenstreik Container". Assert `$scores[A] > $scores[B]` and `$scores[A] > $scores[C]`, and that an id outside `$memberIds` never appears as a key.
  - `MostSharedTest::testTheMostSharedWins` and `testATieGoesToTheNewest`: share counts `[2, 3, 3]` in newest-first order. Assert index 1 wins.
  - `FallbackChoiceTest::testTheBestCandidateWinsWithoutACurrentOne`: scores 10.0 and 7.0. Assert the 10.0 one wins.
  - `FallbackChoiceTest::testTheCurrentStaysWithinEightyPercent`: current 8.0, best 10.0. Assert the current wins.
  - `FallbackChoiceTest::testTheCurrentGivesWayBelowEightyPercent`: current 7.9, best 10.0. Assert the best wins.
  - `FallbackChoiceTest::testAMarkerFreeCandidateBeatsAHigherMarkedOne`: marked 10.0, marker-free 3.0. Assert the marker-free one wins.
  - `FallbackChoiceTest::testAMarkedCurrentGivesWayToAMarkerFreeOne`: current marked 10.0, marker-free 9.0. Assert the marker-free one wins.
  - `FallbackChoiceTest::testATieGoesToTheNewer`: equal scores. Assert the candidate with the larger `effectiveTime` wins.
  - `TrendingSnapshotBuilderTest::testAStoryIsTitledByItsRepresentativeCleanedHeadline` — a week build over three German outlet articles in three feeds:
    - A at 08:00 "Hafenstreik legt Hamburg lahm – Spiegel" (feed "DER SPIEGEL")
    - B at 09:00 "Hafenstreik in Hamburg"
    - C at 10:00 "Streik legt Hamburg lahm"
    - A shares hafenstreik and hamburg with B, and legt, hamburg and lahm with C, so it wins on score. It is also the oldest, so a time tie-break would pick C. The test asserts that precondition through `CentroidScores::of()` on the test's own run.
    - Assert the stored `fallback_title === 'Hafenstreik legt Hamburg lahm'`.
  - `TrendingSnapshotBuilderTest::testTheMajorityLanguageDecidesTheCandidates`: two German and one English article. Assert the fallback is one of the German headlines.
  - `TrendingSnapshotBuilderTest::testAStoryOfPostsIsTitledByItsCard`: Mastodon posts only. Card X is shared by 2 posts, and card Y by 3 copies of one post (one url hash) and is newer. Assert `fallback_title` is X's title, and `fallback_entry_id` is null.
  - `TrendingSnapshotBuilderTest::testTheWeekBuildKeepsASteadyFallback`:
    - after the first test's build, add D at 11:00, a marker-free German outlet headline in a fourth feed
    - first assert through `CentroidScores` that D's score is greater than A's and at most 1.25 × A's
    - rebuild, then assert `fallback_entry_id` is still A
  - `TrendingSnapshotBuilderTest::testTheWeekBuildReplacesAFallbackThatFellBehind`: the same, but with D scoring more than 1.25 × A's (asserted the same way). Assert D replaces A.
  - `TrendingSnapshotBuilderTest::testTheDayBuildKeepsAFallbackTheWeekChose`: a week build titles the story, then a day build over a member subset. Assert `fallback_entry_id` is unchanged.
  - `TrendingSnapshotBuilderTest::testTheDayBuildTitlesANewStory`: a day build only. Assert `fallback_title` is non-null.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit tests/Service/Curation/Trending/Support tests/Service/Curation/Trending/FallbackChoiceTest.php tests/Service/Curation/Trending/SnapshotBuilder`. Expected: FAIL.
- [ ] **Step 3: Implement** as specified. Wire `FallbackTitles` (with `HeadlineMarkers` and `FallbackChoice`) into `TrendingWiring::policies`, so every builder test constructs it.
- [ ] **Step 4: Run them and see them pass.**
  - Run the same, then the whole Trending suite: `php bin/phpunit tests/Service/Curation/Trending tests/Repository`. Expected: PASS.
  - Run `composer md`, `composer tramp` and `composer stan`. Expected: clean.
  - **Measure:** on the dev stack, rebuild user 2's week before and after this task (`TrendingBuildReportModel` `durationMs` and `peakMemoryBytes`, from the worker log or the rebuild command). Record both pairs in the commit message. If either rises clearly, have the grouping hand over the pair weights it already computed instead of `CentroidScores` recomputing them.
- [ ] **Step 5: Commit** `feat(#2329): choose a steady representative headline for every story`

---

### Task 4b: Keep an unchanged story's fallback without scoring

Added during execution at the user's request: Task 4 made user 2's week rebuild ~16 % slower (56–59 s → 66–69 s) by re-scoring every story.

**Files:** the week policy / `FallbackTitles` from Task 4 and their tests; nothing new unless a tiny model is needed.

**Interfaces:**
- Consumes: `StoredStoryModel { id, entryIds (members inside the window), fallbackEntryId, titled }` (Task 3) and the build's story-to-stored-id match.
- Produces: no new public API. Behaviour: when a story keeps a stored id, is `titled`, its `fallbackEntryId` is still a member, and its member entry ids equal the stored `entryIds` as a set, the build keeps the stored fallback title and entry and computes no `CentroidScores` for it. Every other story is chosen exactly as in Task 4.

- [ ] **Step 1: Write the failing tests**
  - `testAnUnchangedStoryKeepsItsStoredFallbackWithoutScoring`: store a story whose `fallback_title` is a member headline that would NOT win on score; rebuild the week with the same members. Assert the stored title and `fallback_entry_id` are unchanged.
  - `testAStoryThatGainedAMemberIsScoredAgain`: same story plus one new member whose headline beats the stored one by more than 1.25×. Assert the title changes to it.
  - `testAStoryThatLostItsFallbackMemberIsScoredAgain`: the stored fallback member leaves; assert a new member headline is chosen.
- [ ] **Step 2: Run them; the first fails.**
- [ ] **Step 3: Implement** the skip in the week path (the day path already titles only untitled stories).
- [ ] **Step 4: Run the Trending tests (SQLite and the touched tests on MySQL), `composer cs/stan/md/tramp`, then re-measure user 2's week rebuild (durationMs, peak memory) twice and record before/after in the commit body.**
- [ ] **Step 5: Commit** `perf(#2329): keep an unchanged story's fallback without scoring`

---

### Task 5: The followed-story sidebar and page heading use the story title

**Files:**
- Modify: `backend/src/Repository/TrendingStoryFollowRepository.php` (`followedBy`, `headline()`)
- Modify: `backend/src/Service/Curation/Trending/Model/FollowedStoryRowModel.php` (docblock only)
- Test: `backend/tests/Repository/TrendingStoryFollowRepositoryTest.php`

**Interfaces:**
- Produces: `FollowedStoryRowModel::$headline` is `COALESCE(story.llm_title, story.fallback_title, <the newest-member subquery>)`. The JSON key `headline` and the frontend (`sidebar-followed-stories`, `list-heading.service.ts`) are unchanged.

- [ ] **Step 1: Write the failing tests**
  - `TrendingStoryFollowRepositoryTest::testAFollowedStoryIsHeadedByItsTitle`: the fallback is "Hafenstreik in Hamburg" and the newest member is "Neue Gespräche". Assert `followedBy($user)[0]->headline === 'Hafenstreik in Hamburg'`.
  - `TrendingStoryFollowRepositoryTest::testTheLlmTitleHeadsAFollowedStory`: assert the LLM title wins over the fallback.
  - `TrendingStoryFollowRepositoryTest::testAStoryWithoutStoredTitlesKeepsTheNewestMemberHeadline`: assert `'Neue Gespräche'`.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit tests/Repository/TrendingStoryFollowRepositoryTest.php`. Expected: the first two fail.
- [ ] **Step 3: Implement** the `COALESCE`, and update the `headline()` docblock. Update the `FollowedStoryRowModel` docblock to say `$headline` is null only for an untitled story with no member in their feeds (accepted: the sidebar fallback becomes rare).
- [ ] **Step 4: Run them and see them pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2329): head followed stories with their story title`

---

### Task 6: Story settings and the story writing connection, in a new `Curation/StoryAi` module

**Files:**
- Create: `backend/src/Entity/StorySettings.php`, `backend/src/Repository/StorySettingsRepository.php`
- Create: `backend/migrations/Version20261118140000.php`
- Create in `backend/src/Service/Curation/StoryAi/`: `StoryWritingConnections.php`, `StorySettingsEditor.php`, `StorySettingsProvider.php`, `Model/StorySettingsModel.php`, `Model/StorySettingsChangeModel.php`, `Exception/WritingConnectionRejectedException.php`
- Create: `backend/src/Dto/Trending/SaveStorySettingsRequest.php`, `backend/src/Http/StorySettingsJson.php`, `backend/src/Controller/Api/StorySettingsController.php`, `backend/src/Http/Problem/ExceptionProblems/StoryAiProblems.php`
- Modify: `backend/src/Service/Curation/Ai/AiProviderConfigurator.php` (`releasePointersTo`), `backend/src/Repository/AccountWipeRepository.php` (`deleteRecommendationData`)
- Modify: `docs/architecture.md` §9 (add `StoryAi` to the `Curation` row)
- Test: `backend/tests/Service/Curation/StoryAi/StoryWritingConnectionsTest.php`, `backend/tests/Controller/Api/StorySettingsControllerTest.php`, `backend/tests/Service/Curation/Ai/AiProviderConfiguratorTest.php`, `backend/tests/Service/Identity/Account/AccountResetTest.php`

**Why a module:** the story AI plumbing serves Trending's title task and any later story task.
- It depends on `Ai`, `Recommendation` and `Recommendation\Llm`. `Trending` depends on it; it never names `Trending`.
- What it needs from Trending (which stories, how to apply a reply) reaches it through the task interface in Task 9. That is §9's "lower module owns an interface the higher one implements".

**Interfaces:**
- Produces:
  - Table `user_story_settings`:
    - `user_id` is the primary key and a foreign key to `user` with ON DELETE CASCADE (`KeyedByUser`).
    - `writing_connection_id INT NULL`, a foreign key to `user_ai_settings (id)` with ON DELETE SET NULL, indexed.
    - `titles_enabled BOOLEAN NOT NULL DEFAULT TRUE`.
    - No row means the defaults, as with `RecommendationSettings`.
  - `StorySettings`:
    - `public const bool DEFAULT_TITLES_ENABLED = true`
    - `update(?AiProviderSettings $writingConnection, bool $titlesEnabled): void`
    - `getWritingConnection(): ?AiProviderSettings`
    - `isTitlesEnabled(): bool`
    - `forgetConnection(AiProviderSettings $connection): void`
  - `StorySettingsRepository::findForUser(User $user): ?StorySettings`
  - `StoryWritingConnections` resolves the story **writing** connection: chat models, for any task that writes text. It **ignores every feature switch**:
    - `usableFor(User $user): ?AiProviderSettings` returns the chosen connection, else the active one, when it can write text; otherwise null.
    - `canWriteText(AiProviderSettings $connection): bool` is `AiReadiness::of($connection) && $connection->getModelKind() === RecommendationEngineKind::Llm`.
    - `candidatesFor(User $user): list<AiProviderSettings>`
  - `final readonly class StorySettingsModel { __construct(public bool $titlesEnabled, public ?AiProviderSettings $storedWritingConnection, public ?AiProviderSettings $writingConnection, public array $writingCandidates) }`. `$writingCandidates` is a `list<AiProviderSettings>`, and `$writingConnection` is the resolved one.
  - `final readonly class StorySettingsChangeModel { __construct(public ?int $writingConnectionId, public bool $titlesEnabled) }`
  - `StorySettingsProvider::forUser(User $user): StorySettingsModel`, and `StorySettingsProvider::titlesEnabledFor(User $user): bool` (no row means `DEFAULT_TITLES_ENABLED`)
  - `StorySettingsEditor::save(User $user, StorySettingsChangeModel $change): void`. It throws `ConfigurationNotFoundException` or `WritingConnectionRejectedException`, and creates the row on its first save.
  - `GET` and `PUT /api/me/ai/stories`, with body `{writingConnectionId: ?int (Positive), titlesEnabled: bool}`, answer `StorySettingsJson::state()`:
    `{writingConnectionId: ?int, writingConnection: ?{id,name,baseUrl,model}, writingCandidates: list<{id,name,baseUrl,model}>, titlesEnabled: bool}`. `writingConnection` is the resolved one, null when none can write.
  - The problem `writing_connection_rejected` is 422, raised when the picked connection cannot write text and is not the stored pick (as in `ProfileSettingsEditor`).
- **Where a later feature's switch goes:**
  - a `<feature>_enabled` column beside `titles_enabled`, with `DEFAULT_<FEATURE>_ENABLED`
  - a field on `StorySettingsChangeModel`, `SaveStorySettingsRequest` and `StorySettingsJson`
  - a parameter of `StorySettings::update()`. At four values, `update()` takes a `StorySwitches` value instead.
  - the check, in that task's `dueStoryIds()` (Task 9)
  - `StoryWritingConnections` stays untouched. A task needing another model kind adds its own connection (see *Extending*).

- [ ] **Step 1: Write the failing tests**
  - `StoryWritingConnectionsTest::testNoRowMeansTheActiveChatConnection`: assert `usableFor($user) === $active`.
  - `StoryWritingConnectionsTest::testAScoringConnectionCannotWrite`: the active connection has a scoring protocol. Assert `usableFor($user) === null` and that `candidatesFor` leaves it out.
  - `StoryWritingConnectionsTest::testTitlesSwitchedOffStillResolveTheConnection`: `titlesEnabled = false`. Assert `usableFor($user) === $active`.
  - `StorySettingsControllerTest::testTheDefaultsShowTitlesOnAndTheActiveConnection`: GET. Assert `titlesEnabled === true`, `writingConnectionId === null` and `writingConnection.id === $active id`.
  - `StorySettingsControllerTest::testSavingAPickAndTheSwitch`: PUT `{writingConnectionId: $other, titlesEnabled: false}`. Assert 200 with the echoed state.
  - `StorySettingsControllerTest::testAScoringPickIsRejected`: assert 422, `application/problem+json`, `type` ending `writing_connection_rejected`.
  - `StorySettingsControllerTest::testAnotherAccountsConnectionIsNotFound`: assert 404.
  - `AiProviderConfiguratorTest::testDeletingTheWritingConnectionFallsBackToTheActiveOne`: assert `StorySettings::getWritingConnection() === null` after the delete.
  - `AccountResetTest::testAResetForgetsTheStorySettings`: assert no `user_story_settings` row remains for the user.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit --filter 'StoryWritingConnections|StorySettingsController|testDeletingTheWritingConnection'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - The migration follows `Version20261003100000`'s pattern for `profile_connection_id`. The SQLite `down` drops the index before the table.
  - `AccountWipeRepository::deleteRecommendationData` adds `$this->deleteByUser(StorySettings::class, $user)`.
  - The controller stays thin (`$request->toChange()`).
  - Run the §6 checklist in `docs/architecture.md`.
- [ ] **Step 4: Run them and see them pass.** Run the same filter, then `composer stan` (the module rules), then migrate and validate on SQLite and MySQL. Expected: PASS, and the schema in sync.
- [ ] **Step 5: Commit** `feat(#2329): add the story writing connection and story-titles switch`

---

### Task 7: Story AI runs record their calls and costs

**Files:**
- Create: `backend/src/Enum/StoryAiTask.php`, `backend/src/Entity/StoryAiRun.php`, `backend/src/Repository/StoryAiRunRepository.php`
- Create: `backend/migrations/Version20261118150000.php`
- Modify: `backend/src/Enum/CallPhase.php`, `backend/src/Repository/CallingRun.php`, `backend/src/Entity/RecommendationRunLog.php`
- Modify: `backend/src/Service/Curation/Recommendation/Run/Factory/RecommendationRunLogFactory.php`, `Run/RecommendationCallRecorder.php`, `Llm/Run/CompletionCallRecorder.php`, `backend/src/Repository/AccountWipeRepository.php`
- Test: `backend/tests/Service/Curation/Recommendation/Run/RecommendationCallRecorderTest.php`, `backend/tests/Service/Identity/Account/AccountResetTest.php`

**Interfaces:**
- Produces:
  - `enum StoryAiTask: string { case Title = 'title'; public function phase(): CallPhase }` (`Title` maps to `CallPhase::StoryTitle`). It lives in `App\Enum` because the entity stores it.
  - `CallPhase::StoryTitle = 'story_title'`. Each later task adds its own phase.
  - Table `story_ai_run`:
    - `id`
    - `user_id`, a foreign key with ON DELETE CASCADE
    - `task VARCHAR(16)`
    - `created_at DATETIME`
    - `streamed_chars INT DEFAULT 0`
    - the `ProviderUsage` embeddable's columns
  - `StoryAiRun::__construct(User $user, StoryAiTask $task, \DateTimeImmutable $createdAt)` and `getTask(): StoryAiTask`
  - `recommendation_run_log.story_ai_run_id INT NULL`, a foreign key to `story_ai_run (id)` with ON DELETE CASCADE, and the index `idx_recommendation_run_log_story_ai_run`. SQLite adds it with `ADD COLUMN … REFERENCES`.
  - `CallingRun::storyAiRun(int $id)` gives `('story_ai_run', 'story_ai_run_id', $id)`.
  - `RecommendationRunLog::forStoryAiRun(StoryAiRun $run, string $requestBody, \DateTimeImmutable $createdAt): self`, with phase `$run->getTask()->phase()` and attempt `1`.
  - `RecommendationCallRecorder::beginForStoryAiRun(StoryAiRun $run, string $renderedRequest): RecordedCall`
  - `CompletionCallRecorder::beginForStoryAiRun(StoryAiRun $run, CompletionRequestModel $request): RecordedCall`

Run-log fit for another model kind: each log row already stores the request, the answering model and the receipt, and `addUsage` banks `prompt_tokens` and cost, which is what an embedding reply reports. A non-chat task adds only its `CallPhase` case and needs no new column.

- [ ] **Step 1: Write the failing test** `RecommendationCallRecorderTest::testAStoryAiCallBanksItsUsageOnItsRun`: a `StoryAiTask::Title` run. Begin, `received(new ProviderCallReceiptModel('req-1', 'gpt-x', usage of 120 prompt and 8 completion tokens with cost 3000), 512)`, then `finishUsable('{"title":"…"}')`. Assert the run's `prompt_tokens === 120` and `completion_tokens === 8`, the log row's `phase === 'story_title'`, its `cost_nano_credits === 3000`, and its `story_ai_run_id` set.
  - `AccountResetTest::testAResetForgetsStoryAiRunsAndTheirCalls`: assert no `story_ai_run` row, and no `recommendation_run_log` row of that run, remains.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit --filter 'testAStoryAiCallBanksItsUsageOnItsRun|testAResetForgetsStoryAiRuns'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Give `StoryTitle` an arm in every exhaustive `match` over `CallPhase` (`grep -rn "CallPhase::" src`).
  - `deleteRecommendationData` deletes `RecommendationRunLog` rows whose `IDENTITY(c.storyAiRun)` is among the user's `StoryAiRun`s, as it does for runs, then `deleteByUser(StoryAiRun::class, $user)`.
  - The SQLite `down` drops `idx_recommendation_run_log_story_ai_run` before the column.
- [ ] **Step 4: Run it and see it pass.** Run the same, then `php bin/phpunit tests/Service/Curation/Recommendation`, then migrate and validate on both platforms. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2329): record story AI calls and costs on a story AI run`

---

### Task 8: The story AI call, and the title prompt, schema and parser

**Files:**
- Create in `backend/src/Service/Curation/StoryAi/`: `Pass/StoryAiPass.php`, `StoryAiCalls.php`, `Model/StoryAiOutcomeModel.php`
- Create: `backend/src/Service/Curation/Trending/Model/StoryHeadlineModel.php`, `Trending/Support/StoryTitlePrompt.php`, `Trending/StoryTitleParser.php`
- Modify: `backend/src/Service/Curation/Recommendation/Llm/Prompt/Model/RecommendationResponseSchema.php`, `Model/CallPromptModel.php`, `RecommendationAnswerBudget.php`
- Modify: `backend/src/Repository/TrendingStoryRepository.php`
- Test: `backend/tests/Service/Curation/StoryAi/StoryAiCallsTest.php`, `backend/tests/Service/Curation/Trending/Support/StoryTitlePromptTest.php`, `backend/tests/Service/Curation/Trending/StoryTitleParserTest.php`, `backend/tests/Repository/TrendingStoryRepositoryTest.php`

**Interfaces:**
- Consumes: `CompletionCallRecorder::beginForStoryAiRun()`, `RecommendationCompletionRequestFactory::create()`, `RecommendationProviderCall::complete()`.
- Produces (shared plumbing, `StoryAi`). `StoryAiCalls::complete()` is the **chat** path; a task calling another model kind brings its own call and still records on the pass's run:
  - `final class StoryAiPass` is the per-user, per-task call context. It holds whichever connection the task resolved:
    - `__construct(public readonly User $user, public readonly AiProviderSettings $connection, public readonly StoryAiTask $task, public readonly \DateTimeImmutable $deadline)`
    - `isPastDeadline(\DateTimeImmutable $now): bool`
    - it holds `?StoryAiRun $run`, created on the first call so an idle user leaves no row
  - `StoryAiOutcomeModel<T>` is `usable(T)`, `unusable()` or `failed(\Throwable)`, with `isUsable(): bool` and `value(): T`.
  - `StoryAiCalls::complete(StoryAiPass $pass, CallPromptModel $prompt, \Closure(string): (T|null) $parse): StoryAiOutcomeModel<T>`:
    - builds the request, records the call on the pass's run, and sends it through `RecommendationProviderCall` with `RetryPlanModel::deferring()`
    - settles the call `finishUsable` or `finishUnusable` by the parse result
    - returns `failed` for any provider failure
    - **rethrows `ProviderRateLimitedException`**: the sweep (Task 9) stops that user
- Produces (the title task, `Trending`):
  - `final readonly class StoryHeadlineModel { __construct(public string $headline, public string $snippet, public ?string $feedTitle = null, public ?FeedLanguage $language = null) }`, with `#[\NoDiscard] cleaned(string $headline): self`
  - `TrendingStoryRepository::memberHeadlinesOf(int $storyId, int $limit): list<StoryHeadlineModel>` is a neutral reader any story task can reuse:
    - Outlet members (`feed.network IS NULL`), newest first by `effective_date` then id, one per `feed_id`, at most `$limit`.
    - The headline is the **raw** entry title, with `feed.title` and the language beside it. The repository cannot inject `HeadlineMarkers`; the task cleans (Task 9). The snippet is `HeadlineBody::body(summary)`, or `bodyAfterTitle` for a derived title.
    - Without outlet members: one per shared link of the story cards (`article_site IS NOT NULL`), newest sharing post first, giving `shared_link.title` and `HeadlineBody::body(shared_link.description)`, with no feed title. It only orders the cards; it never chooses one (choosing is `MostShared`, Task 4).
  - `RecommendationResponseSchema::StoryTitle` maps to `new JsonSchemaModel('story_title', ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false])`. `RecommendationAnswerBudget::answerBoundTokens` gets the arm `StoryTitle => 0`, so it uses the 1024 floor.
  - `CallPromptModel::storyTitle(list<array{role,content}> $messages): self` with reply count 1.
  - `StoryTitlePrompt::messages(list<StoryHeadlineModel> $headlines, string $locale): list<array{role: string, content: string}>`
  - `StoryTitleParser::parse(string $content): ?string`. It decodes through `ModelReplyJsonDecoder`, trims, strips wrapping quotes and trailing `. , ; : ! ? …`, and returns null for a missing or non-string `title`, an empty result, or one over 120 characters.

System message (exact copy; `%s` is `English`, `German`, `French` or `Spanish` from the locale, with `en` for anything else):

```text
You write the headline of one news story from the headlines and openings of the articles that report it.
Write one neutral, factual headline in %s, at most 90 characters, that describes the whole story rather than one article.
Name no outlet, publication or author. No clickbait, no quotation marks around it, no trailing punctuation.
Reply only with JSON: {"title": "..."}
```

The user message has one block per headline: `"%d. %s\n%s"` (a 1-based number, the headline, the snippet), with blocks separated by a blank line.

- [ ] **Step 1: Write the failing tests**
  - `StoryAiCallsTest`, with `tests/Support/StubChatClient.php`:
    - `testAParsedReplyIsUsableAndRecorded`: assert `value() === 'x'` and one `story_ai_run` with task `title` and one log row whose verdict is usable.
    - `testAReplyTheParserRejectsIsUnusable`: assert `isUsable() === false` and the log verdict is unusable.
    - `testATransportFailureIsAFailedOutcome`: the stub throws `ProviderUnreachableException`. Assert `failed` and the log verdict is transport-failed.
    - `testARateLimitIsRethrown`: assert `ProviderRateLimitedException`.
    - `testTwoCallsOfOnePassShareOneRun`: assert one `story_ai_run` row.
  - `StoryTitlePromptTest::testTheSystemMessageNamesTheUiLanguage`: assert that `messages([...], 'de')[0]['content']` contains `'in German, at most 90 characters'`, and that `'fr'` yields `'in French'`.
  - `StoryTitlePromptTest::testEachHeadlineIsNumberedWithItsSnippet`: assert `messages([new StoryHeadlineModel('Hafenstreik', 'Der Hafen steht still.')], 'en')[1]['content'] === "1. Hafenstreik\nDer Hafen steht still."`.
  - `StoryTitleParserTest`, a data provider over `parse()`:
    - `'{"title":"Hamburg port strike halts container traffic."}'` gives `'Hamburg port strike halts container traffic'`
    - `"```json\n{\"title\":\"Streik im Hafen\"}\n```"` gives `'Streik im Hafen'`
    - `'{"title":"  "}'` gives `null`
    - `'{"headline":"x"}'` gives `null`
    - `'not json'` gives `null`
    - a 121-character title gives `null`
    - `'{"title":"„Streik im Hafen“"}'` gives `'Streik im Hafen'`
  - `TrendingStoryRepositoryTest::testMemberHeadlinesTakeOnePerOutletNewestFirst`: 14 outlets, one of them with two members. Assert `memberHeadlinesOf($id, 12)` has 12 rows, newest first, with only that outlet's newer member.
  - `TrendingStoryRepositoryTest::testMemberHeadlinesOfAPostStoryReadItsCards`: assert the card title and description.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit --filter 'StoryAiCalls|StoryTitlePrompt|StoryTitleParser|testMemberHeadlines'`. Expected: FAIL.
- [ ] **Step 3: Implement** as specified.
- [ ] **Step 4: Run them and see them pass.** Run the same filter, then `composer tramp`. Expected: PASS and no tramp chain (the pass object carries user, connection, run and deadline).
- [ ] **Step 5: Commit** `feat(#2329): one recorded story AI call; prompt, schema and parser for titles`

---

### Task 9: The story AI sweep, and the title task

**Files:**
- Create in `backend/src/Service/Curation/StoryAi/`: `StoryAiTask/StoryAiTaskInterface.php`, `StoryAiSweep.php`, `Model/StoryAiReportModel.php`
- Create: `backend/src/Service/Curation/Trending/StoryTitleTask.php`
- Modify: `backend/src/Repository/TrendingStoryRepository.php`
- Test: `backend/tests/Service/Curation/StoryAi/StoryAiSweepTest.php`, `backend/tests/Service/Curation/Trending/StoryTitleTaskTest.php`

**Interfaces:**
- Produces (`StoryAi`):
  - `#[AutoconfigureTag('app.story_ai_task')] interface StoryAiTaskInterface`:
    - `task(): StoryAiTask`
    - `connectionFor(User $user): ?AiProviderSettings` returns the connection this task calls; null skips the user for this task
    - `dueStoryIds(User $user, \DateTimeImmutable $now): list<int>`
    - `run(StoryAiPass $pass, int $storyId): StoryAiOutcomeModel<mixed>` calls `StoryAiCalls::complete()` and applies or stamps the result
    - Why an interface: the sweep lives below Trending, so knowing which stories are due and how a reply lands has to come back up through one (§9). Four methods is the minimum: which model, which stories, and what to do with the reply.
  - `StoryAiSweep::runDue(): StoryAiReportModel` and `runDueBefore(\DateTimeImmutable $deadline): StoryAiReportModel`, over `#[AutowireIterator('app.story_ai_task')] iterable<StoryAiTaskInterface>`:
    - Users: `UserRepository::trendingReaderIdsLoggedInSince(now − StoryAiSweep::ACTIVE_DAYS days)`, with `ACTIVE_DAYS = 14`, the same value as `TrendingRebuild`. It cannot name `TrendingRebuild`: that would close the `Trending → StoryAi` cycle.
    - Per user and task: `connectionFor()` (skip when null), then `dueStoryIds()`, then one `StoryAiPass` built from that connection with `run()` per story. The sweep assumes neither one connection nor a chat model.
    - A story starts only before the deadline. `ProviderRateLimitedException` stops that user for this sweep.
  - `StoryAiReportModel { usable: int, unusable: int, failed: int }` with `none()` and `toLogContext()`.
- Consumes: `StorySettingsProvider::titlesEnabledFor()`, `StoryWritingConnections::usableFor()`, `HeadlineMarkers::clean()`, `ReaderWindows::shownStories()`, `memberHeadlinesOf()`, `StoryTitlePrompt`, `StoryTitleParser`, `StoryAiCalls::complete()`.
- Produces (`Trending`, the title task):
  - `TrendingStoryRepository::titleDueOf(int $userId, \DateTimeImmutable $attemptedBefore): list<int>` covers the user's stories. A story is due when `llm_title IS NULL OR member_count * 2 >= llm_title_member_count * 3`, and also `llm_title_attempted_at IS NULL OR llm_title_attempted_at <= :attemptedBefore`. `member_count` counts `trending_story_entry` rows.
  - `TrendingStoryRepository::recordTitle(int $storyId, string $title, string $language, \DateTimeImmutable $attemptedAt): void` stamps the member count at write.
  - `TrendingStoryRepository::recordTitleAttempt(int $storyId, \DateTimeImmutable $attemptedAt): void`
  - `StoryTitleTask implements StoryAiTaskInterface`:
    - `connectionFor()` is null when `titlesEnabledFor()` is false, else `StoryWritingConnections::usableFor()`.
    - `dueStoryIds()` first runs `titleDueOf($userId, now − 1 hour)`. It returns `[]` without ranking anything when that is empty; otherwise it intersects with the union of `ReaderWindows::shownStories()` ids over both windows.
    - `run()` takes `memberHeadlinesOf($storyId, 12)` (empty input: stamp and skip), cleans each with `$headline->cleaned($this->markers->clean($headline->headline, $headline->feedTitle, $headline->language)->text)`, then calls `StoryTitlePrompt` and `StoryTitleParser::parse(...)`.
    - A usable reply → `recordTitle` with `$pass->user->getLocale()`. An unusable or failed reply → `recordTitleAttempt`, with a warning log on failure.

- [ ] **Step 1: Write the failing tests**
  - `StoryAiSweepTest`, with two in-test task doubles:
    - `testATaskRunsEachDueStoryOfAReaderWithAConnection`: assert `run()` saw story ids `[3, 5]`.
    - `testATaskWithoutAConnectionSkipsTheUser`: `connectionFor()` returns null. Assert no `dueStoryIds()` call for that task, while a second task with a connection still runs.
    - `testARateLimitStopsOnlyThatUser`: the first user's first `run()` throws a deferral. Assert that user's second story never ran and the second user's did.
    - `testNothingStartsAfterTheDeadline`: `runDueBefore(now − 1 s)`. Assert no `run()`.
    - `testEveryTaggedTaskRuns`: two tasks. Assert both ran.
  - `StoryTitleTaskTest`, through the real sweep and `StubChatClient`:
    - `testAVisibleStoryGetsATitleInTheUsersLanguage`: the user's locale is `de`, and the stub answers `{"title":"Hafenarbeiter in Hamburg streiken"}`. Assert `llm_title`, `llm_title_language === 'de'`, and `llm_title_member_count` equal to the member count.
    - `testTheRequestCarriesAtMostTwelveHeadlinesAndTheSchema`: assert `responseSchema->name === 'story_title'` and 12 numbered blocks.
    - `testAnUnusableReplyIsNotRetriedWithinTheHour`: the stub answers `'{}'`. Assert `llm_title` stays null. At +59 minutes, assert the stub was called once; at +61 minutes, twice.
    - `testATitleIsRewrittenOnceTheStoryGrewByHalf`: a title written at 4 members. With 5 members, assert no call. With 6 members, at least an hour later, assert one call and the new title.
    - `testARateLimitStopsTheUsersSweepWithoutStamping`: assert `llm_title_attempted_at` stays null on both due stories and the stub was called once.
    - `testAScoringConnectionWritesNoTitle`: assert no call and no `story_ai_run` row.
    - `testSwitchedOffTitlesWriteNothing`: `titlesEnabled = false`. Assert no call.
    - `testAStoryTheReaderIsNotShownGetsNoTitle`: a story below the reader's threshold. Assert no call.
    - `testTheTitleInputIsCleaned`: a member "Liveblog: Hafenstreik – Spiegel" from the feed "DER SPIEGEL". Assert the user message contains `1. Hafenstreik` and not `Spiegel`.
    - Not unit-tested (review it): with nothing due, `shownStories` is never called. The ranking has no observable side effect to assert on.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit tests/Service/Curation/StoryAi tests/Service/Curation/Trending/StoryTitleTaskTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and see them pass.** Run the same, then `composer check` and `composer md`. Expected: PASS, clean, and no module cycle (`StoryAi` never names `Trending`).
- [ ] **Step 5: Commit** `feat(#2329): a story AI sweep, and a title task that runs on it`

---

### Task 10: Run the story AI sweep after each build

**Files:**
- Create: `backend/src/Service/Operations/Worker/Message/RunDueStoryAiTasks.php`, `backend/src/Service/Operations/Worker/Handler/RunDueStoryAiTasksHandler.php`
- Create: `backend/src/Service/Operations/Maintenance/MaintenanceSweep/StoryAiTickSweep.php`
- Modify: `backend/src/Service/Operations/Worker/WorkerSchedule.php`
- Modify: `backend/src/Entity/User.php` (the `$locale` docblock: it is now also the language of story AI output)
- Test: `backend/tests/Service/Operations/Worker/WorkerScheduleWiringTest.php`, `backend/tests/Service/Operations/Maintenance/MaintenanceTickTest.php`

**Interfaces:**
- Consumes: `StoryAiSweep::runDue()` and `runDueBefore()`.
- Produces:
  - A property-less message `RunDueStoryAiTasks`, added **last** in `WorkerSchedule` as `RecurringMessage::every('5 minutes', new RunDueStoryAiTasks())`. That is the rebuild's cadence ("after each build"), so titles appear up to 5 minutes after a build. The checkpoint stores list positions.
  - The handler logs `'Worker story AI tasks finished.'` with `toLogContext()`.
  - `#[AsTaggedItem(index: 'storyAi', priority: 5)]`, so it runs after `trending` (priority 10) in the same tick. `idleReport()` gives `StoryAiReportModel::none()`.
  - A later task needs no new message or sweep.

- [ ] **Step 1: Write the failing tests**
  - `testStoryAiTasksAreScheduledLast`: assert the schedule's last recurring message is a `RunDueStoryAiTasks`.
  - `testTheTickRunsStoryAiTasksAfterTheTrendingBuild`: assert the tick report's keys order `trending` before `storyAi`.
- [ ] **Step 2: Run them and see them fail.** Run `php bin/phpunit --filter 'testStoryAiTasksAreScheduledLast|testTheTickRunsStoryAiTasksAfterTheTrendingBuild'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and see them pass.** Run the same. Then run the backend gates:
  - `composer test:parallel`, and the MySQL leg `docker compose exec php composer test:parallel`
  - `composer infection:diff`
  Then scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`). Expected: green, MSI at or above `minMsi`, and no new deprecations.
- [ ] **Step 5: Commit** `feat(#2329): run story AI tasks in the worker and the maintenance tick`

---

---

### Task 11: Frontend — the AI settings section, and wiring the agreed title card

**Files:**
- Create: `frontend/src/app/settings/stories/stories-section.component.{ts,html,scss,spec.ts}`, `frontend/src/app/settings/stories/stories-settings.service.{ts,spec.ts}`
- Modify: `frontend/src/app/settings/ai/ai-section.component.html` (render `<app-stories-section [connections]="ai.configs()" />` right after `<app-profile-section>` inside `@if (activeReady())`), `ai-section.component.ts` (imports)
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`
- Do not modify: `frontend/src/app/reader/trending/story-title/**`, `magazine-block.component.html`, `entry-list.component.html`, `TrendingStoryDto.title` (already built and signed off).
- Test: the specs above, plus `frontend/e2e/trending-story-title.spec.ts`

**Interfaces:**
- Consumes:
  - `GET` and `PUT /api/me/ai/stories` (Task 6)
  - `title: string` on the Trending story JSON (Task 1)
  - `headline` on followed stories (Task 5)
- Produces:
  - `StoriesSettingsService extends DraftSettingsService<StoriesSettingsState, SaveStoriesSettings, object>` with endpoint `${base}/api/me/ai/stories`.
  - `StoriesSettingsState` mirrors `StorySettingsJson`.
  - The section is an `app-settings-group` (`icon="trending_up"`) with:
    - an `app-toggle` row `data-testid="story-titles"` that calls `saveInstant({ titlesEnabled })`
    - a `select data-testid="story-connection"` built like `profile-section`'s (an "active" option, a disabled "unusable" option for a stored non-candidate, then the candidates), which calls `saveInstant({ writingConnectionId })`
    - `toastOnSaved(svc, 'settings.stories.saved')`

i18n under `settings.stories`:

| key | en | de | fr | es |
|---|---|---|---|---|
| title | Trending stories | Trending-Stories | Stories Trending | Historias de Trending |
| caption | How your LLM helps with Trending. | Wie dein LLM bei Trending hilft. | Comment votre LLM aide Trending. | Cómo ayuda tu LLM en Trending. |
| titles | Story titles | Story-Titel | Titres des stories | Títulos de las historias |
| titlesDesc | Your LLM writes a headline for each Trending story. Without it, each story shows its most representative headline. | Dein LLM schreibt für jede Trending-Story eine eigene Schlagzeile. Ohne es zeigt jede Story ihre repräsentativste Schlagzeile. | Votre LLM rédige un titre pour chaque story de Trending. Sans lui, chaque story affiche son titre le plus représentatif. | Tu LLM escribe un titular para cada historia de Trending. Sin él, cada historia muestra su titular más representativo. |
| connection | Connection | Verbindung | Connexion | Conexión |
| connectionDesc | The LLM connection that writes story titles. | Die LLM-Verbindung, die Story-Titel schreibt. | La connexion LLM qui rédige les titres des stories. | La conexión LLM que escribe los títulos de las historias. |
| connectionActive | The active connection | Die aktive Verbindung | La connexion active | La conexión activa |
| connectionUnusable | A connection that can no longer write text | Eine Verbindung, die keinen Text mehr schreiben kann | Une connexion qui ne peut plus rédiger de texte | Una conexión que ya no puede escribir texto |
| saved | Story settings saved | Story-Einstellungen gespeichert | Réglages des stories enregistrés | Ajustes de las historias guardados |

- [ ] **Step 1: Write the failing tests**
  - `stories-settings.service.spec.ts` `'loads the state from /api/me/ai/stories'`: flush `{titlesEnabled: true, writingConnectionId: null, writingConnection: {...}, writingCandidates: [...]}` and expect `state()?.titlesEnabled === true`.
  - `stories-section.component.spec.ts`:
    - `'turning story titles off saves titlesEnabled false'`: expect a PUT body `{ writingConnectionId: null, titlesEnabled: false }`.
    - `'choosing a connection saves its id'`: expect a PUT body `{ writingConnectionId: 7, titlesEnabled: true }`.
    - `'a stored pick that is no candidate shows as unusable and disabled'`: expect `[data-testid="story-connection-unusable"]` to be disabled.
  - `ai-section.component.spec.ts` `'shows the stories section next to the profile once a connection is ready'`.
  - `frontend/e2e/trending-story-title.spec.ts`: stub `/api/trending*` with one story `title: 'Hafenarbeiter in Hamburg streiken'` (see `magazine-kicker-one-line.spec.ts`). Expect the title card's text on the Trending page. Stub `/api/trending/followed*` with `headline: 'Hafenarbeiter in Hamburg streiken'`, and expect the sidebar row and the opened story's page heading to show it.
- [ ] **Step 2: Run them and see them fail.** Run `docker compose exec -T frontend npx jest src/app/settings/stories src/app/settings/ai`. Expected: FAIL.
- [ ] **Step 3: Implement.** Styles go in the sibling `.scss`, using design-language tokens only (`--space-*`, `--fs-*`).
- [ ] **Step 4: Run them and see them pass.**
  - Run `docker compose exec -T frontend npm run check`. Never run it at the same time as another Jest run or the PHP suites. Expected: green.
  - With the Docker stack up, run `npm run e2e -- trending-story-title`. Expected: PASS.
  - Live-check on `:4200`: the title card shows the backend title after a build and a tick, in magazine and list, desktop and phone. The followed-story sidebar and heading show the same title. The settings switch and picker persist across a reload.
- [ ] **Step 5: Commit** `feat(#2329): story-title settings beside the profile connection`

---

### Task 12: A story title opens the story's list view; the title is not bold and its card is gray

Added during execution at the user's request (2026-11-18): "when I click on a title, I want to get to the same list view which I get when I click on a followed story in the sidebar", and "the title text should not be bold".

**Files:** find them — the followed-story list view is reached through the `followedStoryId` query param (`frontend/src/app/reader/state/followed-story-visit.ts`, `reader/shell/list-heading.service.ts`, the sidebar's followed-stories component, `reader-api.ts`), served by `GET /api/entries/trending-stories/{id}` (`backend/src/Controller/Api/FollowedStoryEntriesController.php`, `TrendingStoryListQuery`). The title card is `frontend/src/app/reader/trending/story-title/story-title.component.*`.

**Interfaces / behaviour:**
- The title card becomes a link (a real `<a>` with `routerLink` + the same query params the sidebar uses), so it opens in the same list view, works with middle-click/new tab, and is keyboard-focusable; the card below stays as it is (its own clicks unchanged).
- The view must work for a story the reader does **not** follow: the entries endpoint and the heading accept any story the reader owns (`TrendingStoryRepository::requireOwnedBy` → 404 for another user's story id, as today for follow). Check what the list view does with follow-only pieces (unfollow action, sidebar highlight, the "opened" visit post): unfollowed stories show the follow action instead of unfollow (reuse `app-story-actions` if it fits), and no sidebar entry is highlighted. If the param name `followedStoryId` no longer fits, rename it to `storyId` everywhere (sidebar links included) — one name, no alias.
- The heading of that view shows the story title (Task 5 already makes it the story title for followed stories; do the same for unfollowed).
- Title card tint: a neutral gray instead of the accent green (`--accent-soft`) — pick the existing neutral surface token from `docs/design-language.md` (e.g. `--surface-sunken`) that reads as a quiet gray in light and dark mode; the icon may stay in the accent colour. Same rim and shape.
- Title text weight: regular, not bold — use the regular weight token from `docs/design-language.md`; nothing else in the look changes. Re-render with the scratchpad capture script used for the sign-off (`real.mjs`) and compare against the signed-off renders: only the weight and the tint differ. Send the user the new renders (light and dark).

- [ ] **Step 1: Write the failing tests**
  - backend: `testTheEntriesOfAnUnfollowedOwnStoryAreListed` (200, the story's entries); `testAnotherUsersStoryIsNotFound` (404).
  - frontend: story-title renders an `<a>` whose router link + query params equal the sidebar's link for the same story id; clicking it navigates there; the list heading shows the story title for an unfollowed story; the view offers "follow" (not "unfollow") for an unfollowed story.
- [ ] **Step 2: Run them; they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Backend tests (SQLite + MySQL for the touched ones), `composer cs/stan/md`, `docker compose exec -T frontend npm run check`, re-render screenshots (desktop + phone, magazine + list) and click the title in a Playwright run against the stubbed page to confirm the list view opens.**
- [ ] **Step 5: Commit** `feat(#2329): a story title opens the story's list view`

---

### Task 12b: The story's list opens as an overlay, like an article

Added during execution from the user's requests (2026-11-18): the story view's back link must look and behave like the article view's top bar (arrow, icon, muted title; same slide); going back must not reload the underlying list and must keep its scroll position. User decisions: (a) the story view is an **overlay over the current list** (opened from a title: over Trending; from the sidebar: over whatever list was showing), like an article — the list underneath stays mounted; (b) the story's Follow/Unfollow toggle and the newest/oldest order sit at the **right end of the article-style top bar**.

**Behaviour:**
- Opening a story slides it in with the article view's transition; back slides it out and reveals the list exactly as it was (no `/api/trending` or other list request, same scroll offset, no spinner), magazine and list layouts, desktop and phone; prefers-reduced-motion as the article does.
- The top bar is the article view's bar (shared piece extracted if needed, not copied): back arrow, story icon in the favicon slot, story title; Follow/Unfollow and newest/oldest at the right, styled like the bar's existing actions.
- Deep link `/trending/stories/:id` opened directly: the story overlay over the default list underneath (as a deep-linked article does).
- The sidebar's followed-story rows open the same overlay.
- Interim work from Task 12 (header back link in the Settings look, `TrendingReturn` service, `_back-link.scss`) is replaced where the overlay makes it unnecessary; keep only what the overlay reuses.

**Tests:** specs for the overlay open/close and the bar's controls; e2e: scroll Trending, open a story via its title, go back → scroll unchanged within 2 px and no new list request; the sidebar row opens the overlay; a deep link shows the overlay with a working back; reduced motion.

**Commit:** `feat(#2329): a story's list opens over the current list, like an article`

---

## Open questions (noted, not blocking)

- An LLM title or fallback stays on a story that falls out of the week but is still followed. Nothing rewrites it later. That is accepted.
- A current fallback whose entry has aged out of the week window stops being a candidate, so it is replaced. "Still a member" is read as "still a member in the build's window".
- `llm_title_language` is stored, but no reader uses it yet. It records the language a title was written in, as the spec asks. Drop it at review if it is judged unneeded.
- A link-card fallback is not cleaned. The spec says it is "the same thing shown today".

## Extending: a second story AI task

A new task, for example connecting articles to a story, adds only:
- a `StoryAiTask` case, its `CallPhase` case, and the `phase()` arm
- a class implementing `StoryAiTaskInterface` in its owning module: `connectionFor`, `dueStoryIds` (its due query and switch check), and `run` (the call and the apply step)
- a switch column on `user_story_settings`, wired as described in Task 6
- its own due and apply columns or tables, and a migration
- **For a chat task:** a prompt (`Support/`), a parser, a `RecommendationResponseSchema` case and a `CallPromptModel` named constructor. It reuses `StoryWritingConnections` and `StoryAiCalls::complete()`.
- **For another model kind** (for example an embedding encoder):
  - its own connection column on `user_story_settings`, for example `matching_connection_id`
  - a resolver whose candidates filter on `getModelKind()` or the protocol
  - an API field pair beside `writingConnection…`
  - its own call: an embedding call does not go through `StoryAiCalls::complete()`. It begins a call with `CompletionCallRecorder::beginForStoryAiRun()` on the pass's run, or a sibling of it if the request type differs.

It reuses, unchanged:
- `StoryAiPass`, `StoryAiSweep`
- the `story_ai_run` table and the call log
- `RunDueStoryAiTasks` and `StoryAiTickSweep`
- `TrendingStoryRepository::memberHeadlinesOf()`
