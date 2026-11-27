# Shared Stemming Module Implementation Plan (#2583)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Trending's language-aware word handling into a new `Service/Foundation/Stemming` module, so
Recommendation (the For you stream, #2582) can stem without a `Trending → Recommendation → Trending` cycle.

**Architecture:** A pure move with renames. The classes keep their behaviour and their tests; names lose the
`Headline` prefix because the module stems any text, not only headlines. Trending keeps everything that depends
on a window (document frequencies, window and feed filler, rarity, markers, the per-window `CompoundParts`
cache). `HeadlineFiller` keeps `isSeed()` but delegates it to the new `StopWordStems`.

**Tech Stack:** PHP 8.5, Symfony 8.1 autowiring, `wamania/php-stemmer`, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-11-27-for-you-stream-design.md` §1.

## Global Constraints

- No behaviour change: every existing Trending test passes with no change to its assertions (only imports,
  class names and file locations change).
- `Service/Foundation/Stemming` imports nothing from `App\Service\Curation`, `Content`, `Library` or any group above
  Foundation; it may import `App\Enum\FeedLanguage` and `App\Service\Foundation\Text`.
- Move files with `git mv` so history follows them; rename in the same commit as the move.
- Commit format `refactor(#2583): lower-case summary`; no attribution lines.
- Per-task gates only (CLAUDE.md "Gate cadence"): the moved tests plus the Trending suite, `composer cs` and
  `composer stan` on changed files. The full set runs once before the PR.
- This is a worktree: PhpStorm inspections do not see `.claude/worktrees/`; say so instead of claiming a clean lint.

## Review Focus

1. **Autowiring after a move.** A service the container wires by class (`BundledGermanLexicon`'s
   `#[Autowire]` directory argument, the `GermanLexiconInterface` alias) must still resolve.
   `TrendingContainerWiringTest` covers it; it must pass unchanged apart from imports.
2. **Stale names in strings.** A class name in a string, a docblock `@see`, or a test data provider keyed by
   class name is missed by import fixing. `grep -rn "HeadlineStemmer\|HeadlineStems\|HeadlineEquivalents" src tests`
   must be empty at the end (prose in `docs/` may keep the old names).
3. **The lexicon path.** `%kernel.project_dir%/resources/german-lexicon` and `tests/Fixtures/german-lexicon` stay
   where they are; nothing moves under `resources/`.
4. **The cut-form seeds.** `StopWordStems` must keep both the per-language seeds and the cut-form seeds for a
   language-less feed; the two `HeadlineFillerTest` seed cases that cover these move with it unchanged.
5. **Module boundary.** `ServiceModuleCycleRule` in `composer stan` proves no cycle; also
   `grep -rn "App\\\\Service\\\\Curation" src/Service/Foundation/Stemming` must be empty.

---

### Task 1: Stemmer and stem helpers move to Foundation/Stemming

**Files:**
- Move: `src/Service/Curation/Trending/HeadlineStemmer.php` → `src/Service/Foundation/Stemming/WordStemmer.php`
- Move: `src/Service/Curation/Trending/Factory/SnowballStemmerFactory.php` → `src/Service/Foundation/Stemming/Factory/SnowballStemmerFactory.php`
- Move: `src/Service/Curation/Trending/Support/HeadlineStems.php` → `src/Service/Foundation/Stemming/Support/WordStems.php`
- Move tests: `tests/Service/Curation/Trending/HeadlineStemmerTest.php` → `tests/Service/Foundation/Stemming/WordStemmerTest.php`;
  `tests/Service/Curation/Trending/Support/HeadlineStemsTest.php` → `tests/Service/Foundation/Stemming/Support/WordStemsTest.php`
- Create: `tests/Support/StemmingWiring.php`
- Modify imports: `HeadlineEquivalents.php`, `HeadlineFiller.php`, `HeadlineMarkers.php`, `tests/Support/TrendingWiring.php`,
  `tests/Service/Curation/Trending/HeadlineEquivalentsTest.php`, `HeadlineFillerTest.php`
- Modify: `docs/architecture.md:256` — add `Stemming` to the `Foundation` row.

**Interfaces:**
- Produces: `App\Service\Foundation\Stemming\WordStemmer::stem(string $word, ?FeedLanguage $language): string`
  (constructor `SnowballStemmerFactory $stemmerFactory`); `App\Service\Foundation\Stemming\Support\WordStems`
  with the unchanged statics `bare()`, `cut()`, `isKept()`; `App\Service\Foundation\Stemming\Factory\SnowballStemmerFactory`.
- Produces: `Tests\Support\StemmingWiring::stemmer(): WordStemmer`. `TrendingWiring::stemmer()` is deleted and its
  callers use `StemmingWiring::stemmer()`.

- [ ] **Step 1:** `git mv` the three source files and two tests; rename the classes and namespaces. The moved tests
  keep every test method and assertion.
- [ ] **Step 2:** Fix every importer: `grep -rln "HeadlineStemmer\|HeadlineStems\|Trending\\\\Factory\\\\SnowballStemmerFactory" src tests`.
- [ ] **Step 3:** Run `php bin/phpunit tests/Service/Foundation/Stemming tests/Service/Curation/Trending` — expected: all pass,
  same test count as before the move (record the before count first with the old paths).
- [ ] **Step 4:** Run `vendor/bin/phpcs` and `vendor/bin/phpstan analyse` on the changed files — expected: clean.
- [ ] **Step 5:** Commit `refactor(#2583): move the word stemmer to foundation/stemming`.

### Task 2: German compound splitter and lexicon move

**Files:**
- Move to `src/Service/Foundation/Stemming/`: `GermanCompoundSplitter.php`, `Pass/CompoundSplitSearch.php`,
  `Model/CompoundSplitModel.php`, `Model/GermanLexiconEntryModel.php`, `Model/GermanLexiconModel.php`,
  `GermanLexicon/GermanLexiconInterface.php`, `GermanLexicon/BundledGermanLexicon.php` (names unchanged).
- Move their tests under `tests/Service/Foundation/Stemming/` mirroring `src/`
  (`GermanCompoundSplitterTest`, `Model/CompoundSplitModelTest`, `GermanLexicon/BundledGermanLexiconTest`, and any
  `Model/GermanLexicon*Test`).
- Stays: `Trending/Pass/CompoundParts.php` (the window's per-word cache).
- Modify: `StemmingWiring::germanLexicon(): BundledGermanLexicon`, moved from `TrendingWiring` (same fixture path
  `tests/Fixtures/german-lexicon`), and `StemmingWiring::compoundSplitter(): GermanCompoundSplitter`.

**Interfaces:**
- Consumes: Task 1's namespace.
- Produces: `App\Service\Foundation\Stemming\GermanCompoundSplitter::splitParts(string $word): array` (unchanged
  signature and return docblock), `App\Service\Foundation\Stemming\GermanLexicon\GermanLexiconInterface`.

- [ ] **Step 1:** `git mv` sources and tests; update namespaces and importers (`HeadlineEquivalents`, `TrendingWiring`,
  `TrendingContainerWiringTest`).
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Foundation/Stemming tests/Service/Curation/Trending` — expected: all pass,
  `TrendingContainerWiringTest` included.
- [ ] **Step 3:** cs + stan on changed files — clean.
- [ ] **Step 4:** Commit `refactor(#2583): move the german compound splitter to foundation/stemming`.

### Task 3: Equivalents move as WordEquivalents

**Files:**
- Move: `src/Service/Curation/Trending/HeadlineEquivalents.php` → `src/Service/Foundation/Stemming/WordEquivalents.php`
- Move: `tests/Service/Curation/Trending/HeadlineEquivalentsTest.php` → `tests/Service/Foundation/Stemming/WordEquivalentsTest.php`
- Modify importers: `Trending/Pass/HeadlineWindow.php`, `Trending/TrendingGrouping.php`, `TrendingWiring`,
  `TrendingContainerWiringTest`.

**Interfaces:**
- Produces: `App\Service\Foundation\Stemming\WordEquivalents` with unchanged public methods
  `canonicalizeWords(string $text): array` and `stemWordAndParts(string $word, string $bare, ?FeedLanguage $language): array`;
  constructor `(WordStemmer $stemmer, GermanCompoundSplitter $splitter)`.
- Produces: `StemmingWiring::equivalents(): WordEquivalents`, replacing `TrendingWiring::equivalents()`.

- [ ] **Step 1:** `git mv`, rename class, fix importers and the class's own docblock if it says "headline".
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Foundation/Stemming tests/Service/Curation/Trending` — all pass.
- [ ] **Step 3:** cs + stan on changed files — clean.
- [ ] **Step 4:** Commit `refactor(#2583): move headline equivalents to foundation/stemming as word equivalents`.

### Task 4: StopWordStems takes the seed stems out of HeadlineFiller

**Files:**
- Create: `src/Service/Foundation/Stemming/StopWordStems.php`
- Modify: `src/Service/Curation/Trending/HeadlineFiller.php`
- Create: `tests/Service/Foundation/Stemming/StopWordStemsTest.php`
- Modify: `tests/Service/Curation/Trending/HeadlineFillerTest.php`, `StemmingWiring`, `TrendingWiring::filler()`

**Interfaces:**
- Produces: `final readonly class StopWordStems` with constructor `(WordStemmer $stemmer, StopWordsInterface $stopWords)`
  and `isStopWordStem(string $stem, ?FeedLanguage $language): bool`. The body is today's
  `seedStemsByLanguage()`/`cutSeedStems()` plus `isSeed()`, moved verbatim.
- `HeadlineFiller` constructor becomes `(StopWordStems $stopWordStems)`; `isSeed()` keeps its signature and returns
  `$this->stopWordStems->isStopWordStem($stem, $language)`. Window filler, feed filler and rarity are unchanged.
- Produces: `StemmingWiring::stopWordStems(): StopWordStems`.

- [ ] **Step 1: Write the failing test.** Move the five seed cases from `HeadlineFillerTest`
  (`testEveryStopWordIsASeedThroughItsLanguagesStemmer`, `testEveryKeptStopWordsCutIsASeedWithoutALanguage`,
  `testAWordInNoListIsNoSeedWithoutALanguage`, `testASeedOfOneLanguageIsNoSeedInAnotherUnlessItsListHasIt`,
  `testAContentWordIsNoSeedInItsLanguage`) to `StopWordStemsTest`, renamed from "seed" to "stop-word stem"
  (e.g. `testEveryStopWordIsAStopWordStemThroughItsLanguagesStemmer`), with the same data providers and
  assertions called on `isStopWordStem()`. Keep one case in `HeadlineFillerTest`:
  `testASeedIsAStopWordStem` asserting `isSeed($stemmer->stem('con', Spanish), Spanish)` is true and
  `isSeed('hausautomatisierung', null)` is false, so the delegation stays pinned.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Foundation/Stemming/StopWordStemsTest.php` — expected: FAIL, class not found.
- [ ] **Step 3:** Create `StopWordStems` and switch `HeadlineFiller` to it.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Foundation/Stemming tests/Service/Curation/Trending` — all pass.
- [ ] **Step 5:** cs + stan on changed files — clean; `composer md` on `HeadlineFiller.php` and `StopWordStems.php` — clean.
- [ ] **Step 6:** Commit `refactor(#2583): stop-word stems move out of headline filler`.

### Task 5: Boundary check

- [ ] **Step 1:** `grep -rn "HeadlineStemmer\|HeadlineStems\|HeadlineEquivalents" src tests` — expected: no output.
- [ ] **Step 2:** `grep -rn "App\\\\Service\\\\\(Curation\|Content\|Library\|Social\|Ingestion\)" src/Service/Foundation/Stemming` — expected: no output.
- [ ] **Step 3:** `composer stan` (whole tree, warm cache) — expected: no errors, `ServiceModuleCycleRule` and
  `ServiceRoleRule` included.
- [ ] **Step 4:** If anything was fixed, commit `refactor(#2583): …`; otherwise nothing to commit.
