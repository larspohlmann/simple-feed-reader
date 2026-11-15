# Short posts the detector reads as no feed language get the stopword vote (#2243) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A social post under 150 characters that states no language and that the full detector places outside
de/en/fr/es gets the language whose bundled stopwords it holds most, outright, counting words of two letters or more.

**Architecture:**
- The stopword data moves from `Curation/Trending/StopWords` to `Ingestion/Language/StopWords`, and word splitting
  from `HeadlineStems::words()` to `Foundation/Text/Support/CasefoldedWords::of()` (Task 1, no behaviour change).
- A new root service `Ingestion/Language/StopWordVote` counts the stopwords (Task 2).
- `SocialEntryLanguage` asks the vote only where the detector names none of the four on a short body (Task 3).

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 12, Infection; `patrickschur/language-detection`.

**Spec:** `docs/superpowers/specs/2026-11-15-2243-short-post-language-design.md`, the binding authority. Read its
Design and Decisions D1–D7 before Task 1. Execution is subagent-driven, run by the controller.

## Global Constraints

- **Binding rulings:** a general rule only — no per-account, per-word or per-language list, nothing tuned from the
  current news window; French and Spanish first-class in every measurement; keep it small (no fill command, no extra
  stopword lists, no capital/hashtag rule: spec D6, D7).
- The short-text limit is the existing one: `LanguageGuess::overrulesAStatedLanguage()` (150 characters, `mb_strlen`
  of the body). No new length constant.
- A word votes when `mb_strlen($word) >= 2` (spec D2). Count word tokens, not distinct words. A tie or no stopword is
  `null`.
- `Curation/Trending` may depend on `Ingestion/Language`, never the reverse (`ServiceModuleCycleRule`).
- `backend/CLAUDE.md` rules via the root `CLAUDE.md`: `final readonly`, no comments unless a reader would get the code
  wrong, at most 3 parameters, PHPMD-clean touched files, no PHPStan ignores, no `tests/PhpStan` edits.
- Commits: `type(#2243): lower-case imperative summary`, no attribution. Never push.
- Dev MySQL `feedreader` is read-only; measure on `feedreader_2243_copy` only.

## Review Focus

1. **A post with no Latin letters** (Russian, Japanese, emoji-only): the vote must give `null`, never a language —
   pinned by `testACyrillicTextHasNoVote` (Task 2).
2. **An English contraction** (*it's*, *don't*): its one-letter tail must not vote French — pinned by
   `testAOneLetterWordDoesNotVote` (Task 2).
3. **A short post stating a language outside the four** (`it`): stays `null`, the vote is never asked — pinned by
   `testAShortPostStatedOutsideTheFourGetsNoVote` (Task 3).
4. **A long post the detector reads as Italian**: stays `null` (49bf22822's fix) — pinned by
   `testALongPostGuessedOutsideTheFourGetsNoVote` (Task 3).
5. **The container wiring** of the new `StopWordVote` argument: unit tests build it by hand, so a wiring break would
   pass them — pinned by `SocialEntryLanguageWiringTest`, through the container's `EntryIngestor` (Task 3).

---

### Task 1: Move the stopword data and the word splitting

**Files:**
- Move: `backend/src/Service/Curation/Trending/StopWords/StopWordsInterface.php` and `BundledStopWords.php` →
  `backend/src/Service/Ingestion/Language/StopWords/` (namespace `App\Service\Ingestion\Language\StopWords`)
- Move: `backend/tests/Service/Curation/Trending/StopWords/BundledStopWordsTest.php` and `BundledStopWordsDataTest.php`
  → `backend/tests/Service/Ingestion/Language/StopWords/` (namespace `App\Tests\Service\Ingestion\Language\StopWords`)
- Create: `backend/src/Service/Foundation/Text/Support/CasefoldedWords.php`
- Create: `backend/tests/Service/Foundation/Text/Support/CasefoldedWordsTest.php`
- Create: `backend/tests/Support/LanguageWiring.php`
- Modify: `backend/src/Service/Curation/Trending/Support/HeadlineStems.php` (delete `words()`),
  `backend/src/Service/Curation/Trending/HeadlineEquivalents.php:66`, `backend/src/Service/Curation/Trending/HeadlineFiller.php:8`
- Modify: `backend/tests/Service/Curation/Trending/Support/HeadlineStemsTest.php` (delete the five `words()` tests),
  `backend/tests/Service/Curation/Trending/HeadlineEquivalentsTest.php:167,356`,
  `backend/tests/Service/Curation/Trending/HeadlineFillerTest.php:66,78`,
  `backend/tests/Service/Curation/Trending/TrendingContainerWiringTest.php:11-12`, `backend/tests/Support/TrendingWiring.php:19,74-80`

**Interfaces:**
- Produces: `App\Service\Ingestion\Language\StopWords\StopWordsInterface::wordsOf(FeedLanguage $language): array`
  (unchanged, `list<string>`), `App\Service\Ingestion\Language\StopWords\BundledStopWords` (unchanged, same
  `#[Autowire]` path).
- Produces: `App\Service\Foundation\Text\Support\CasefoldedWords::of(string $text): array` (`list<string>`), the
  exact body of today's `HeadlineStems::words()`, as a static-only `final class` with a private constructor like its
  siblings in that folder; its docblock keeps `casefolded, diacritics kept`.
- Produces: `App\Tests\Support\LanguageWiring::stopWords(): BundledStopWords` (the bundled file, as
  `TrendingWiring::stopWords()` builds it today); `TrendingWiring::stopWords()` is deleted and its three callers use
  `LanguageWiring::stopWords()`.

- [ ] **Step 1:** `git mv` the two src files and the two test files; fix their namespaces and every import
  (`grep -rn 'Curation\\Trending\\StopWords' backend/src backend/tests backend/config` must print nothing).
- [ ] **Step 2:** Create `CasefoldedWords::of()`; move the five tests `testWordsAreCaseFoldedAndKeepTheirDiacritics`,
  `testADecomposedDiacriticStaysInsideItsWord`, `testCompatibilityCharactersBecomePlainLetters`,
  `testWordsAreSplitButNotCut`, `testTextWithoutWordsHasNoWords` from `HeadlineStemsTest` into `CasefoldedWordsTest`,
  calling `CasefoldedWords::of()`, assertions unchanged. Delete `HeadlineStems::words()`; `HeadlineEquivalents` and
  `HeadlineEquivalentsTest` call `CasefoldedWords::of()` (`grep -rn 'HeadlineStems::words' backend` prints nothing).
- [ ] **Step 3:** Create `LanguageWiring::stopWords()`, delete `TrendingWiring::stopWords()`, switch its callers.
- [ ] **Step 4:** Run from `backend/`:
  `bin/console cache:clear -q && php bin/phpunit tests/Service/Ingestion/Language tests/Service/Foundation/Text tests/Service/Curation/Trending`
  Expected: all green, test count equal to before the move (count it first on the unchanged tree).
- [ ] **Step 5:** `composer stan`. Expected: no error (the module-cycle and role rules accept the new folders).
- [ ] **Step 6:** Commit: `refactor(#2243): move the stopword lists to the language module and word splitting to foundation text`.

### Task 2: `StopWordVote`

**Files:**
- Create: `backend/src/Service/Ingestion/Language/StopWordVote.php`
- Test: `backend/tests/Service/Ingestion/Language/StopWordVoteTest.php`

**Interfaces:**
- Consumes: `StopWordsInterface`, `CasefoldedWords::of()`, `LanguageWiring::stopWords()` (Task 1).
- Produces: `final readonly class StopWordVote`, constructor `(StopWordsInterface $stopWords)`, method
  `languageOf(string $text): ?FeedLanguage`.

- [ ] **Step 1: Write the failing tests** (real bundled lists through `LanguageWiring::stopWords()`, `TestCase`; every
  value below was checked against `scratchpad/2243/proto-vote.php`):

```php
public function testTheLanguageHoldingMostStopwordsWins(): void
{
    self::assertSame(FeedLanguage::German, self::vote('Aus dem Archiv!'));
    self::assertSame(FeedLanguage::English, self::vote('Palantir exec nominated to lead CISA'));
    self::assertSame(FeedLanguage::French, self::vote('Je ne sais pas ce que tu veux'));
    self::assertSame(FeedLanguage::Spanish, self::vote('El gobierno no lo sabe'));
}

public function testATieHasNoVote(): void { self::assertNull(self::vote('the and der die')); }

public function testATextWithoutStopwordsHasNoVote(): void { self::assertNull(self::vote('Hehe')); }

public function testAOneLetterWordDoesNotVote(): void
{
    self::assertNull(self::vote("Silicon Valley plots Jensen Huang's comeback"));
}

public function testARepeatedStopwordCountsEachTime(): void { self::assertSame(FeedLanguage::English, self::vote('the the der')); }

public function testAWordCountsForEveryListHoldingIt(): void { self::assertSame(FeedLanguage::German, self::vote('in der')); }

public function testACyrillicTextHasNoVote(): void { self::assertNull(self::vote('Президент заявил в понедельник')); }

public function testTheVoteFoldsCase(): void { self::assertSame(FeedLanguage::German, self::vote('AUS DEM ARCHIV')); }
```

  `vote(string $text)` is a private static helper: `new StopWordVote(LanguageWiring::stopWords())->languageOf($text)`.
  Add one test with a stub `StopWordsInterface` (an anonymous class returning `['de' => ['zz'], …]`-style lists per
  `FeedLanguage`) proving the vote reads the injected lists: `'zz zz'` → German.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Ingestion/Language/StopWordVoteTest.php`. Expected: FAIL, class
  `StopWordVote` not found.
- [ ] **Step 3:** Implement `StopWordVote`. The constructor stores, per `FeedLanguage::cases()` value, `array<string,
  true>` built from `wordsOf()`. `languageOf()` filters `CasefoldedWords::of($text)` to `mb_strlen >= 2` (a private
  const `MIN_WORD_LENGTH = 2`, with a one-line comment: a single letter is a contraction's tail or an initial, and the
  French list holds nine), counts per language, and returns the language whose count is greater than every other's,
  else `null` (0 counts too). One class docblock line, citing #2243.
- [ ] **Step 4:** Run the test file again. Expected: PASS.
- [ ] **Step 5:** `rm -rf ~/.pdepend && HOME=$(mktemp -d) vendor/bin/phpmd analyze src/Service/Ingestion/Language/StopWordVote.php --no-progress`
  (no finding), then commit: `feat(#2243): vote a short text's language from the bundled stopwords`.

### Task 3: `SocialEntryLanguage` asks the vote

**Files:**
- Modify: `backend/src/Service/Ingestion/Language/SocialEntryLanguage.php`
- Modify: `backend/tests/Service/Ingestion/Language/SocialEntryLanguageTest.php`, `backend/tests/Support/EntryIngestors.php:56`,
  `backend/tests/Service/Ingestion/Ingest/Factory/IngestedEntryFactoryTest.php:353`, `backend/tests/Support/LanguageWiring.php`
- Create: `backend/tests/Service/Ingestion/Language/SocialEntryLanguageWiringTest.php`

**Interfaces:**
- Consumes: `StopWordVote::languageOf()` (Task 2), `LanguageWiring::stopWords()` (Task 1).
- Produces: `SocialEntryLanguage::__construct(LanguageGuess $languageGuess, StopWordVote $stopWordVote)`;
  `LanguageWiring::socialEntryLanguage(): SocialEntryLanguage` (real full detector, bundled lists), used by
  `EntryIngestors` and `IngestedEntryFactoryTest`.

- [ ] **Step 1: Write the failing tests** in `SocialEntryLanguageTest`. Its `languageOf()` helper and every
  `new SocialEntryLanguage(...)` in it pass `new StopWordVote(LanguageWiring::stopWords())` as second argument. New
  tests (helper signature `languageOf(string $body, ?string $statedLanguage, string $detectorSays)`, headline `'…'`):

```php
public function testAShortPostStatingNothingAndGuessedOutsideTheFourGetsTheStopwordVote(): void
{
    self::assertSame(FeedLanguage::German, self::languageOf('Aus dem Archiv!', null, 'la'));
}

public function testTheDetectorsGuessInTheFourWinsOverTheVote(): void
{
    self::assertSame(FeedLanguage::French, self::languageOf('Aus dem Archiv!', null, 'fr'));
}

public function testALongPostGuessedOutsideTheFourGetsNoVote(): void
{
    self::assertNull(self::languageOf(str_pad('Aus dem Archiv!', self::limit(), ' der'), null, 'it'));
}

public function testAShortPostStatedOutsideTheFourGetsNoVote(): void
{
    self::assertNull(self::languageOf('Aus dem Archiv!', 'it', 'it'));
}

public function testTheVoteReadsTheHeadlineToo(): void
{
    $language = new SocialEntryLanguage(new LanguageGuess(StubbedDetector::saying('la')), new StopWordVote(LanguageWiring::stopWords()));

    self::assertSame(FeedLanguage::German, $language->of(self::entry('Aus dem Archiv', 'xx', null)));
}

public function testARealShortEnglishPostTheDetectorReadsAsInterlinguaIsEnglish(): void
{
    self::assertSame(FeedLanguage::English, LanguageWiring::socialEntryLanguage()->of(self::entry('…', 'Palantir exec nominated to lead CISA', null)));
}
```

  The existing `testAPostStatingNothingAndGuessedOutsideTheFourGetsNone` (body `xxxxx`, detector `it`) stays and must
  stay green: no stopword, no vote. `SocialEntryLanguageWiringTest` (`DbTestCase`, the pattern of
  `PlatformEntryRulesWiringTest`; the test container has removed `SocialEntryLanguage` and `IngestedEntryFactory` as
  inlined, but `EntryIngestor` is reachable): one test,
  `testTheContainersIngestorStoresAShortEnglishPostTheDetectorReadsAsInterlinguaAsEnglish` — a `Feed` with
  `setSourceFormat(SourceFormat::BLUESKY_API)`, one `ParsedEntryModel` with `Headline::derived('…')`, summary
  `'Palantir exec nominated to lead CISA'`, no stated language, ingested through
  `self::getContainer()->get(EntryIngestor::class)`; the stored entry's `getText()->getLanguage()` is `FeedLanguage::English`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Ingestion/Language`. Expected: the new tests FAIL (constructor
  arity, then German/English expected, null given).
- [ ] **Step 3:** Implement. `SocialEntryLanguage` takes `StopWordVote $stopWordVote`; `guess()` returns
  `bestFor($text)` when it is not null or when `overrulesAStatedLanguage($body)`, else
  `$this->stopWordVote->languageOf($text)`, with `$text = headline . "\n" . body` as today. `of()` is unchanged. Update
  the class docblock to say a short post read as none of the four takes its stopwords' language (#2243). Add
  `LanguageWiring::socialEntryLanguage()` and use it in `EntryIngestors` and `IngestedEntryFactoryTest`.
- [ ] **Step 4:** `bin/console cache:clear -q && php bin/phpunit tests/Service/Ingestion tests/Service/Curation/Trending tests/Service/Content/LinkCard`.
  Expected: PASS.
- [ ] **Step 5:** PHPMD on `SocialEntryLanguage.php` as in Task 2 (no finding). Commit:
  `feat(#2243): give a short post the detector reads as no feed language its stopwords' language`.

### Task 4: Measure (A = develop 7eeb43652, B = HEAD)

**Files:** none in the repository except the spec's *Outcome*. Scratch: `scratchpad/2243/final/`. Scripts already in
`scratchpad/2243/`: `lang-of.php` (A and B constructions written for this plan's names), `run.sh` (Trending on
`feedreader_2243_copy`, pinned 2026-11-15 12:00 UTC, `LANG_OVERRIDE` and `ONLY_IDS`), `pairs.py`,
`union-fr.ids`/`union-es.ids`, `override-A.json` (develop's tags, made before Task 1 from 7eeb43652's code) and
`override-V2.json` (the spec's simulation). Run every command in the foreground; one `run.sh` week run per call.

- [ ] **Step 1:** Check `feedreader_2243_copy` still holds 7,412 week social posts (`php q.php` with the `dump.php`
  window). If it was dropped, recreate it from `feedreader` and regenerate `override-A.json` at develop 7eeb43652 via
  `git archive 7eeb43652 backend/src | tar -x -C scratchpad/2243/final/A` plus a copied autoloader, and say so.
- [ ] **Step 2:** On HEAD: `php lang-of.php B > final/override-B.json`, timed with `/usr/bin/time -l`. Compare to
  `override-V2.json`: count and list every differing id with its text. Expected 0 (any difference is a finding; the
  simulation read a 300-character cut of the text). Report the vote's mean cost per post (B time − A time over the
  7,412 posts).
- [ ] **Step 3:** Trending A/B, day and week: `LANG_OVERRIDE=override-A.json ./run.sh <window> final-A` and the same for
  B. Headers must show equal entry counts. `python3 pairs.py runs/final-A-<w>.txt runs/final-B-<w>.txt override-A.json final/override-B.json`:
  new / lost / net per de–de, en–en, fr–fr, es–es, mixed or none. Expected near the spec's V2 (+148 week, +7 day).
- [ ] **Step 4:** French-only and Spanish-only, day and week: the same runs with `ONLY_IDS=union-fr.ids` and
  `union-es.ids` (add any id B tags fr/es that the union lacks). Expected 0 new, 0 lost.
- [ ] **Step 5:** By eye: every post B tags fr or es that A gave none (expected 5 fr, 3 es, as in the spec); 30 of the
  en and 20 of the de ones; the 10 largest new or grown groups (`compare.py`). Name any wrong merge.
- [ ] **Step 6:** Fill the spec's *Outcome* with the numbers and verdicts, mark any deviation from the spec's
  prediction, then drop `feedreader_2243_copy` (root, through the mysql container). Commit:
  `docs(#2243): record the measured outcome`.

### Task 5: Gates

- [ ] **Step 1:** `rm -rf ~/.pdepend && composer md`, then
  `HOME=$(mktemp -d) vendor/bin/phpmd analyze <every touched src file> --no-progress`. Expected: no finding.
- [ ] **Step 2:** `composer check`. Expected: clean. If phptramp flags a chain, check
  `composer show larspohlmann/phptramp` first.
- [ ] **Step 3:** `composer test:parallel` and `docker compose exec -T php composer test:parallel`, at the same time.
  Expected: green.
- [ ] **Step 4:** `composer infection:diff`. Expected: at or above `minMsi`; kill escaped mutants with cases (a
  mutant on `>= 2` or on the tie must die to `testAOneLetterWordDoesNotVote` / `testATieHasNoVote`).
- [ ] **Step 5:** PhpStorm inspections on the changed PHP. Expected: no ERROR or WARNING.
- [ ] **Step 6:** Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) for deprecations.
- [ ] **Step 7:** PR notes in the ledger: `Closes #2243`; no migration and no fill (spec D6: stored nulls age out of
  the week within seven days of the deploy); the worker must be restarted to pick the new code up. Do not open the PR
  or push.
