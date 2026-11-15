# Trending news-independent tables (#2231) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trending's seeds come from the Snowball stopword lists instead of a hand-picked list, and its equivalence
tables keep only the institutions (EU, UN, NATO); every person, event, country and capital entry goes.

**Architecture:**
- `backend/resources/snowball-stopwords/` holds a generator, the generated `stopwords.php`, its `SOURCE` pin and its
  BSD licence notice.
- `StopWords/StopWordsInterface` with one implementation, `StopWords/BundledStopWords`, reads that file.
- `HeadlineFiller` builds its seed sets from it at construction. `SEED_WORDS` and `SEED_STEMS` go.
- `HeadlineEquivalents` keeps its code; its five tables shrink to the EU, UN and NATO rows.
- No guard, no fold, no territory data: the spec's research measured each and decided against it.

**Tech Stack:** PHP 8.5, Symfony 8.1, `wamania/php-stemmer` ^4, PHPUnit 12, Infection.

**Spec:** `docs/superpowers/specs/2026-11-15-2231-news-independent-tables-design.md`. It is the binding authority. Read
its Design, Decisions D1–D9 and R5's table before Task 1.

## Global Constraints

- **No seed, table entry or value comes from the current news window.** The window measures; it never supplies a word.
  `stopwords.php` is generated, never edited by hand. A test asserts invariants over the data, not words chosen from
  the window.
- The only data source is the Snowball stopword lists at commit `dc14fb462784e5bdf90f3210022b0f91d3881ffd` of
  `snowballstem/snowball-website`, BSD 3-clause, with the SHA-256s in the spec's R1 table. No other data is added.
- Out of scope, by decision: a proper-noun guard (D5), a *Macht/machen* fix (D6), a transliteration fold (D7), an
  acronym rule for *WM* (D8), territory or Wikidata data (D3). Do not build them, even if the measurement tempts.
- Unchanged: `HeadlineWindow`, `HeadlineStemmer`, `GermanCompoundSplitter`, the German lexicon, the weights,
  the filler shares (`WINDOW_SHARE`, `COUNTED_ENTRIES_FLOOR`, `FEED_MINIMUM_ENTRIES`, `FEED_SHARE`) and the
  `HeadlineLinks` thresholds. The public signatures of `HeadlineFiller` (`isSeed`, `isWindowFiller`, `isFeedFiller`,
  `rarityWeight`) and of `HeadlineEquivalents` (`wordsOf`, `wordStemsOf`) stay as they are.
- House rules (CLAUDE.md):
  - `final readonly`, and one role per folder: the interface folder `StopWords/`;
  - no comment unless a future reader would get the code wrong; delete the comments that describe deleted tables;
  - every touched `src` file is PHPMD-clean (`rm -rf ~/.pdepend` before `composer md`), with no suppressions and no
    count-driven splits;
  - no PHPStan ignores or docblocks to silence a finding, no edit to `tests/PhpStan`;
  - tests mirror `src/`, and invocation matchers are called on `$this`.
- Commit format `type(#2231): lower-case imperative summary`, with no Claude attribution. Work on the main checkout,
  branch `feature/2231-news-independent-tables`. Never push.
- Commands run in the foreground. No background jobs, no polling, no subagents.

## Review Focus

1. **Seed equivalence for the per-language sets.** `isSeed($stem, $language)` must be true exactly for the Snowball
   stems of that language's list. The data-driven test in Task 3 covers every word, not a sample.
2. **The language-less set** is the union of `HeadlineStems::cut()` over the four lists' kept words (D2), and every
   entry in it is a cut and is kept by length. The #2225 exclusions (*son, con, sin, fin*) are deliberately gone; the
   tests that pinned them change, they are not "fixed" back.
3. **No canonical is a seed.** `eunion`, `onu` and `nato` must stay out of every language's seeds, and out of the
   language-less set. This is the existing invariant test, which now runs against the Snowball data.
4. **The tables hold no person, event or country** (spec R5). The remaining rows are exactly the EU, UN and NATO rows
   the spec lists. Nothing new is added.
5. **The licence trail:** `NOTICE.txt` names the source, the licence and the changes; `SOURCE` pins the commit and each
   file's SHA-256; `generate.php` aborts on a hash mismatch and reproduces `stopwords.php` byte for byte.

---

### Task 1: Stopword generator, data and licence notice

**Files:**
- Create: `backend/resources/snowball-stopwords/generate.php`, `stopwords.php` (generated), `SOURCE`, `NOTICE.txt`,
  `LICENSE.txt`
- Modify: `.gitattributes` (root): add `backend/resources/snowball-stopwords/stopwords.php linguist-generated=true -diff`

**Interfaces:**
- `generate.php` takes no argument, fetches over HTTPS, and writes `stopwords.php` next to itself. It prints each
  language's word count and the file size.
- `stopwords.php`: `return ['de' => list<string>, 'en' => list<string>, 'fr' => list<string>, 'es' => list<string>];`
  - The keys are in the order `de, en, fr, es` (the `FeedLanguage` values).
  - Each list is casefolded (`MB_CASE_FOLD`, then NFKC), with diacritics kept, distinct, sorted with `SORT_STRING`, and
    every word matches `^\p{L}+$`.

- [ ] **Step 1:** Write `generate.php` in the style of `resources/german-lexicon/generate.php`
  (`declare(strict_types=1)`, `require __DIR__ . '/../../vendor/autoload.php'`, top-level constants). Pin as
  constants: the commit, and the language → (Snowball name, SHA-256) map from the spec's R1 table
  (`de` → `german`, `en` → `english`, `fr` → `french`, `es` → `spanish`). For each language it:
  - fetches `https://raw.githubusercontent.com/snowballstem/snowball-website/<commit>/algorithms/<name>/stop.txt`;
  - throws a `RuntimeException` naming the file if the body's `hash('sha256', …)` differs from the pin;
  - for each line: cuts at the first `|`, trims, takes the first whitespace-separated token, casefolds it with NFKC,
    and keeps it only if it matches `^\p{L}+$`;
  - removes duplicates and sorts.
  Then it writes the array with `var_export`, behind a `<?php` and `declare(strict_types=1);` header, deterministically.
- [ ] **Step 2:** Run `php resources/snowball-stopwords/generate.php` from `backend/`, twice, keeping the first output
  aside, and `cmp` the two. Expected: identical. The counts are close to the spec's R1 table: de about 231 words,
  en about 124 (174 minus the contractions), fr about 154, es about 308. Record the exact counts.
- [ ] **Step 3:** Break-test the hash check: change one character of one pinned SHA-256 in a scratch copy of the
  script, run the copy, and quote its failure. Expected: a `RuntimeException` naming the file. Delete the copy.
- [ ] **Step 4:** Write `SOURCE` in the `feed-directory/SOURCE` and `german-lexicon/SOURCE` style: the repository, the
  commit and its date (2026-10-17T07:30:28Z), the four URLs with their SHA-256, the fetch date, the parse rule, the
  four word counts, the byte size of `stopwords.php`, and the reproduction (two runs, `cmp` identical).
- [ ] **Step 5:** Write `LICENSE.txt`: the 3-clause BSD licence as stated on `https://snowballstem.org/license.html`
  (© 2001 Dr Martin Porter, © 2002 Richard Boulton), word for word. Write `NOTICE.txt`. It covers:
  - the source: the Snowball project's stopword lists for German, English, French and Spanish, with the repository
    and commit;
  - the licence: BSD 3-clause, with a pointer to `LICENSE.txt`;
  - the changes: comments removed, casefolded, contractions and other non-letter entries dropped, duplicates removed,
    sorted; `generate.php` makes them and `SOURCE` pins the input;
  - the line "stopwords.php is under the BSD 3-clause licence; the repository's code stays MIT".
- [ ] **Step 6:** Add the `.gitattributes` line. Commit
  `feat(#2231): generate the Snowball stopword lists as Trending seed data`.

### Task 2: The stopwords behind an interface

**Files:**
- Create: `backend/src/Service/Curation/Trending/StopWords/StopWordsInterface.php`,
  `StopWords/BundledStopWords.php`
- Test: `backend/tests/Service/Curation/Trending/StopWords/BundledStopWordsTest.php`,
  `backend/tests/Service/Curation/Trending/StopWords/BundledStopWordsDataTest.php`
- Modify: `backend/tests/Service/Curation/Trending/TrendingContainerWiringTest.php`

**Interfaces:**
- `interface StopWordsInterface { public function wordsOf(FeedLanguage $language): array; }`, returning
  `list<string>` (declared in the interface's `@return`).
- `final readonly class BundledStopWords implements StopWordsInterface`:
  - `__construct(#[Autowire('%kernel.project_dir%/resources/snowball-stopwords/stopwords.php')] private string $path)`;
  - `wordsOf()` requires `$path` and returns that language's list;
  - throws `\UnexpectedValueException` naming the path when the file does not return an array holding a
    `list<string>` for the language.

- [ ] **Step 1: Failing tests.**
  `BundledStopWordsTest`, on temporary files written by the test:
  - `testItReturnsTheListOfTheAskedLanguage`: a file returning `['de' => ['und'], 'en' => ['the'], 'fr' => ['les'],
    'es' => ['los']]` gives `['the']` for English and `['und']` for German;
  - `testAFileOfTheWrongShapeIsRefused`: a file returning `['de' => 'und']` throws `\UnexpectedValueException`;
  - `testAFileMissingALanguageIsRefused`: a file returning `['de' => ['und']]` throws `\UnexpectedValueException`
    when asked for French.
  `BundledStopWordsDataTest`, on the real file (`dirname(__DIR__, 5) . '/resources/snowball-stopwords/stopwords.php'`):
  - `testEveryLanguageHasWords`: for each `FeedLanguage`, the list is not empty;
  - `testEveryWordIsCasefoldedLettersAndSorted`: each word matches `^\p{L}+$`, equals its own
    `mb_convert_case(…, MB_CASE_FOLD)`, and each list equals its sorted, de-duplicated self;
  - `testTheListsHoldTheirLanguagesFunctionWords`: `und` in German, `the` in English, `les` in French, `los` in Spanish.
  `TrendingContainerWiringTest::testTheContainersStopWordsAreTheBundledOnes`: the container resolves
  `StopWordsInterface` to `BundledStopWords`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Curation/Trending/StopWords`. Expected: FAIL (the classes are missing).
- [ ] **Step 3:** Implement. Autowiring binds the single implementation, as for `GermanLexiconInterface`; add an alias
  in `services.yaml` only if `debug:container StopWordsInterface` shows it does not.
- [ ] **Step 4:** Run the tests and the wiring test. Expected: PASS. `composer stan` and `composer md` on the new files:
  clean.
- [ ] **Step 5:** Commit `feat(#2231): read the Snowball stopwords behind an interface`.

### Task 3: Seeds from the stopwords

**Files:**
- Modify: `backend/src/Service/Curation/Trending/HeadlineFiller.php`, `backend/tests/Support/TrendingWiring.php`
- Test: `backend/tests/Service/Curation/Trending/HeadlineFillerTest.php`

**Interfaces:**
- `HeadlineFiller::__construct(HeadlineStemmer $stemmer, StopWordsInterface $stopWords)`.
  - Per language: the set of `$stemmer->stem($word, $language)` over `$stopWords->wordsOf($language)`, as today.
  - Without a language: the set of `HeadlineStems::cut($word)` over every language's words that
    `HeadlineStems::isKept(HeadlineStems::bare($word))` keeps.
  - `SEED_WORDS`, `SEED_STEMS` and their docblocks are deleted. Both sets are built once, in the constructor.
- `TrendingWiring::filler()` passes `new HeadlineFiller(self::stemmer(), self::stopWords())`, and a new
  `TrendingWiring::stopWords(): BundledStopWords` builds it on the real file (it is a few KB, D9).

- [ ] **Step 1: Rewrite the tests first.** In `HeadlineFillerTest`:
  - keep the four filler-share tests, the rarity test and `contentWordsByLanguage` (*Sturm, con, guerre, guerra* are
    no seed in their language) unchanged;
  - `testEveryStopWordIsASeedThroughItsLanguagesStemmer`: for each language and each word of
    `TrendingWiring::stopWords()->wordsOf($language)`, `isSeed(stem(word, language), language)` is true;
  - `testEveryKeptStopWordsCutIsASeedWithoutALanguage`: for each language and each word that `isKept` keeps,
    `isSeed(HeadlineStems::cut($word), null)` is true;
  - `testAWordInNoListIsNoSeedWithoutALanguage`: `storm`, `guerre`, `sturm` give false for `null`;
  - `testASeedOfOneLanguageIsNoSeedInAnotherUnlessItsListHasIt`: `isSeed(stem('con', Spanish), Spanish)` is true and
    `isSeed(stem('con', English), English)` is false;
  - delete `testTheSeedListHoldsEnglishAndGermanFunctionWordsAsStems`, `testFrenchAndSpanishFunctionWordsAreSeeds`,
    `testAWordThatCarriesMeaningInAnotherLanguageIsNotASeed`, `testEverySeedWithoutALanguageIsInCutForm` and
    `testEachLanguagesFunctionWordsAreSeedsThroughItsStemmer`: they pin hand-picked words or the deleted constant.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Curation/Trending/HeadlineFillerTest.php`. Expected: FAIL (the
  constructor takes one argument).
- [ ] **Step 3:** Implement. Keep the constructor a sentence: one private static helper per set at most, and no
  boolean parameter.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Trending`. Expected: PASS, except tests in
  `HeadlineEquivalentsTest` that assert a deleted table row, which Task 4 rewrites. Report every other failure with
  its reason. `HeadlineEquivalentsTest::testEveryCanonicalIsAnOpaqueIdNoMapKeysAndNoLanguageSeeds` and
  `testEveryStemKeyIsBareAndNoSeedOfItsLanguage` must pass: no canonical or key may be a Snowball seed.
- [ ] **Step 5:** `rm -rf ~/.pdepend && composer md`, `composer stan`. Expected: clean for `HeadlineFiller.php`.
- [ ] **Step 6:** Commit `feat(#2231): seed Trending with the Snowball stopword lists`.

### Task 4: Tables cut to the institutions

**Files:**
- Modify: `backend/src/Service/Curation/Trending/HeadlineEquivalents.php`
- Modify: `backend/tests/Fixtures/german-lexicon/lexicon.tsv` (add `gipfel	n	9` and `nato	n	10`, keeping the
  byte order)
- Test: `backend/tests/Service/Curation/Trending/HeadlineEquivalentsTest.php`,
  `backend/tests/Service/Curation/Trending/Pass/HeadlineWindowTest.php`

**Interfaces:** none change. The tables become exactly (spec, Design):
- `EXACT_WORDS`: `'EU' => 'eunion', 'UE' => 'eunion', 'UN' => 'onu', 'UNO' => 'onu', 'ONU' => 'onu'`;
- `SHOUTABLE_WORDS`: `['UN' => true, 'UNO' => true]`;
- `PHRASES`: `united nations, vereinte nationen, nations unies, naciones unidas, vereinten nationen` → `onu`;
  `europaische union, european union, union europeenne, union europea, europaischen union` → `eunion`;
- `CUT_STEMS`: `'otan' => 'nato'`;
- `STEMS_BY_LANGUAGE`: `'de' => [], 'en' => [], 'fr' => ['otan' => 'nato'], 'es' => ['otan' => 'nato']`.
- Comments: the class docblock becomes one line naming what belongs (institutions whose names do not change with
  the news; never a person, an event or a country, #2231). The `EXACT_WORDS` docblock no longer mentions the
  six-letter cut collisions. The `CUT_STEMS` docblock loses "check the window's words before adding one".

- [ ] **Step 1: Rewrite the tests first.** In `HeadlineEquivalentsTest`:
  - replace `testCrossLanguageNamesShareOneStem` with `testNatoAndOtanShareOneStem`: `NATO` and `OTAN`, without a
    language, both give `['nato']`;
  - `testTwoSpellingsOfOneNameCountOnce`: `'NATO OTAN und'`, without a language, gives `['nato', 'und']`;
  - `testACanonicalIsNeverSplit`: `wordStemsOf('eunion', 'eunion', German)` is `['eunion']`;
  - `testAPartGoesThroughTheGermanEquivalences` becomes `testAPartThatIsACanonicalKeepsIt`: `Natogipfel` in German
    contains `'nato'` (the fixture gains `gipfel` and `nato`);
  - `spellingsByLanguage` holds only institutions: de *Vereinten Nationen* → `onu`, de *Europäischen Union* →
    `eunion`, en *United Nations* → `onu`, fr *OTAN* → `nato`, fr *Nations unies* → `onu`, es *OTAN* → `nato`,
    es *Naciones Unidas* → `onu`;
  - `abbreviationsNextToCapitals`: drop the `WM` case;
  - `testAnAbbreviationInAHyphenatedCompoundFires`: drop the `WM` assertion;
  - `testOneAllCapsNeighbourDoesNotSilenceAnAbbreviation`: use the shoutable `UN` in `'Die UN WHO warnt'` and
    expect `onu` (one neighbour is all-caps, the other is not);
  - delete `testALongFormWhoseStemCollidesIsAnExactWord` (*Großbritannien* goes) and
    `testALanguageStemThatIsACanonicalNeedsNoEntry` (*Ukraine* is no canonical any more);
  - keep every invariant test (`testEveryCanonicalIs…`, `testEveryStemKey…`, `testEveryExactWord…`,
    `testEveryShoutableWordIsAnExactWord`, `testNoPhraseStartsAnotherPhrase`, `testEveryPhraseKeyIsInFoldedForm`,
    `testEveryCutStemKeyIsACut`) unchanged, and add `testNoTableHoldsAPersonAnEventOrACountry`: the set of canonicals
    over all tables equals `['eunion', 'nato', 'onu']`.
  In `HeadlineWindowTest::testEquivalentSpellingsShareWeight`: `'NATO warns'` and `'OTAN avertit'`, without a
  language, share `self::TWICE`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Curation/Trending`. Expected: the new and changed tests FAIL on the
  current tables (the canonical-set test, `NATO OTAN und`), the rest PASS.
- [ ] **Step 3:** Cut the tables and the comments as listed. Do not reorder or reformat the rows that stay.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Trending tests/Command`. Expected: PASS. Any other test
  that fails because a deleted row joined two spellings is reported with its reason; its expected value changes only
  if the new stems are right.
- [ ] **Step 5:** `rm -rf ~/.pdepend && composer md`, `composer stan`. Expected: clean.
- [ ] **Step 6:** Commit `refactor(#2231): keep only the institutions in the Trending equivalence tables`.

### Task 5: Measurement, written into the spec's Outcome

**Files:**
- Modify: `docs/superpowers/specs/2026-11-15-2231-news-independent-tables-design.md` (the Outcome section)
- Scratch only, never committed: the outputs and scripts, under
  `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/5bb3d5ca-28c2-427b-85d0-779b6950f4b5/scratchpad/2231/`.

The kit is in `…/scratchpad/measure-kit/` (`README.md`). It is read-only against the dev DB, runs natively from
`backend/`, and needs
`DATABASE_URL='mysql://feedreader:feedreader@127.0.0.1:33306/feedreader?serverVersion=8.4&charset=utf8mb4' APP_ENV=dev`
exported. `…/scratchpad/2231/tools/ab3.sh` is the research swap script; adapt it to swap whole trees.

- [ ] **Step 1: Variants.**
  - **A:** develop at 75048da7e: `git archive 75048da7e backend/src/Service/Curation/Trending` extracted over the
    working tree's `Trending` folder (as `2230/final-r8/at-rev.sh` does), restored afterwards by copying the saved tree
    back, never with `git checkout`. `resources/snowball-stopwords` can stay: A does not read it.
  - **B:** HEAD as committed.
  - After each swap run `php bin/console cache:warmup` and `php bin/console debug:container
    'App\Service\Curation\Trending\HeadlineFiller' --show-arguments`. Expected: one argument for A, two for B.
- [ ] **Step 2: Runs.** Pin `T = "2026-11-15 00:00:00"`. For each variant `V` in A, B and each window `W` in day,
  week: `/usr/bin/time -l php $KIT/simp-explain-at.php "$PWD" W "$T" > $S/2231-V-W.txt 2> $S/2231-V-W.time`. Run
  A and B back to back, one window per command so that no command runs longer than about 3 minutes. Compare the
  header lines: if the entry counts differ (the worker adds back-dated entries), run A again and use the pair with equal
  counts.
- [ ] **Step 3: Compare.** `python3 $KIT/compare.py A B` for each window. Record groups, grouped pairs (new, lost,
  net), wall time, peak RSS (`maximum resident set size`) and the header's peak PHP memory.
- [ ] **Step 4: Eye check.** The 10 largest new groups, the 10 most grown and the 10 largest splits, for the day and
  the week. For each, say whether it is a real story. Look in particular for:
  - a group joined through a word that the old seed list dropped and the new one keeps (fr *après, depuis, contre*;
    en *will, can, also, just*);
  - a story split because a Snowball seed now drops a word it needed (*Weg, Sein*, es *estado*);
  - a cross-language group that a deleted country row used to join.
- [ ] **Step 5:** Write the Outcome in the style of #2230's: a table per window (entries, groups, grouped pairs, new /
  lost / net, wall time, peak RSS, peak PHP memory), the eye-check examples, real and wrong, and whether the result
  matches the spec's combined check (week about +540, day about +30). **No remedy is registered:** a loss or a wrong
  merge is reported, never fixed by adding a word, a row or a value. A week net loss of real stories blocks the PR
  and the Outcome says so. Commit `docs(#2231): measure the news-independent tables`.

### Task 6: Gates

**Files:** whatever the gates point at. Touch nothing else.

- [ ] **Step 1:** `composer check` from `backend/` (`bin/console cache:warmup` first if stan needs it). Expected:
  phpcs, PHPStan and phptramp clean. If phptramp flags something unrelated, check `composer show
  larspohlmann/phptramp` first.
- [ ] **Step 2:** `rm -rf ~/.pdepend && composer md`. Expected: no finding in any touched `src` file.
- [ ] **Step 3:** PhpStorm `lint_files` on the changed PHP. Expected: no ERROR or WARNING.
- [ ] **Step 4:** `composer test:parallel` (SQLite) and `docker compose exec -T php composer test:parallel` (MySQL),
  both green. Before the MySQL leg, check that the container serves this checkout.
- [ ] **Step 5:** With everything committed, `composer infection:diff`. Expected: covered MSI at or above `minMsi`, every
  escaped mutant killed by a new assertion or explained. An initial-run OOM is the known flake: rerun.
- [ ] **Step 6:** Scan today's dev log:
  `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -r 'select(.level_name=="WARNING" or .level_name=="ERROR" or (.message|test("eprecat"))) | .message'`.
  Expected: nothing new from Trending.
- [ ] **Step 7:** Run `/simplify` on the branch, apply what it finds, rerun steps 1–5, and commit. Report to the
  planner: the Outcome's numbers, the eye-check verdicts, and the gate results. The PR is the controller's call.
