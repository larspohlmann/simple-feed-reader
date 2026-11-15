# Trending German decompounding (#2230) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trending splits a German compound into its parts, each a stem next to the whole compound, and joins a German
verb's *-t* and *-et* forms to its infinitive. Both use a lexicon generated from news-independent book data.

**Architecture:**
- `GermanCompoundSplitter` chooses a split by frequency-scored recursion (Koehn & Knight): linking elements by rule,
  the head must be a noun, and the unsplit word competes on its own frequency.
- `GermanVerbStems` maps the *-t* and *-et* forms, with a noun guard.
- Both read `GermanLexiconInterface`. Its bundled implementation lazily requires
  `backend/resources/german-lexicon/lexicon.php`, which a committed script generates from the Google Books Ngram v3
  German 1-grams (CC BY 3.0), and drops it on `reset()`.
- `HeadlineEquivalents::wordStemsOf` returns the whole stem plus the part stems. `HeadlineWindow` posts all of them at
  the word's place weight.

**Tech Stack:** PHP 8.5, Symfony 8.1, `wamania/php-stemmer` ^4 (already installed by #2227), PHPUnit 12, Infection.

**Spec:** `docs/superpowers/specs/2026-11-15-2230-german-decompounding-design.md`

## Global Constraints

- **No word, part, split or value comes from the current news window.** The window validates the mechanism only. The
  lexicon is generated, never edited by hand. If an example compound does not split, that is a finding.
- The fixed values are in the spec, decided before measuring:
  - `MIN_PART_LENGTH = 3` and `MAX_PARTS = 4`;
  - the linking variants `'' s es n en er e ens +e`;
  - the years 1950–2019 and the 6 MB file budget;
  - the remedies' 1 % part floor and the half place weight.
  None is tuned after the measurement.
- Only German is split, and only German gets the verb rule. A word with `language === null` keeps the six-character
  cut. English, French and Spanish are unchanged.
- `HeadlineStemmer::stem(string $word, ?FeedLanguage $language): string` keeps its signature. `HeadlineFiller` and the
  #2225/#2227 tables are untouched.
- The weights (`HEADLINE_WEIGHT`, `BODY_WEIGHT`), the filler shares and the `HeadlineLinks` thresholds are unchanged,
  unless the registered dilution remedy fires in Task 7.
- The data's licence is CC BY 3.0, with attribution in `backend/resources/german-lexicon/NOTICE.txt`. No other
  third-party data is added.
- House rules (CLAUDE.md):
  - `final readonly`, and one role per folder: the interface folder `GermanLexicon/`, `Model/`, `Pass/`, `Exception/`;
  - no comment unless a future reader would get the code wrong;
  - every touched `src` file is PHPMD-clean, with no suppressions and no count-driven splits;
  - no PHPStan docblocks or rule edits on my own initiative;
  - tests mirror `src/`, and invocation matchers are called on `$this`.
- Commit format `type(#2230): …`, with no Claude attribution. Branch `feature/2230-german-decompounding`, cut from a
  freshly pulled `develop` **after PR #2232 has merged**.
- Gates before the PR:
  - `composer check`, `composer md` and `composer test:parallel` (SQLite);
  - `docker compose exec php composer test:parallel` (MySQL);
  - `composer infection:diff` (covered MSI ≥ 80 % on the touched files; commit first, because the diff ignores
    untracked files).

## Review Focus

1. A frequent simple word that looks splittable (*Aktion = Akt + Ion*, *Urinstinkt*) must return no parts (Task 3
   tests). The unsplit candidate's own frequency has to win.
2. A part that is common in the window (*tag*, *land*) must not link two entries alone (Task 4 test with the filler
   floor; Task 7 eye check).
3. The noun guard in the verb rule: *Bett* and *beten*, *Macht* and *machen* stay apart, while *verhandelt* and
   *verhandeln* join (Task 5 tests).
4. `BundledGermanLexicon` loads only on the first lookup and reloads after `reset()` (Task 2 test). The data must
   never load for a run without German words, or in a web request.
5. The licence trail: `NOTICE.txt` names the source, the licence and the changes, `SOURCE` pins every shard by
   SHA-256, and `generate.php` reproduces `lexicon.php` byte for byte (Task 1 check).

---

### Task 1: Lexicon generator, data and licence notice

**Files:**
- Create: `backend/resources/german-lexicon/generate.php`, `lexicon.php` (generated), `SOURCE`, `NOTICE.txt`,
  `LICENSE.txt` (CC BY 3.0 Unported legal code)
- Create: `docs/superpowers/specs/2026-11-15-2230-german-decompounding-design.md` and
  `docs/superpowers/plans/2026-11-15-2230-german-decompounding.md` (copied from the scratchpad)

**Interfaces:**
- Produces `lexicon.php`: `return ['nouns' => array<string, int>, 'others' => array<string, int>, 'verbs' =>
  array<string, string>];`
  - The keys are casefolded forms with diacritics kept (the `verbs` keys are bare present stems).
  - The `int` values are frequency classes `floor(log2(maxCount / count))`, where 0 is the most frequent.
  - The `verbs` values are `HeadlineStems::bare(Snowball German stem of the infinitive)`.
  - `nouns` and `others` share no key.
- `generate.php <shard dir>` writes `lexicon.php` next to itself and prints N, the file size and the noun, other and
  verb counts.

- [ ] **Step 1:** `git switch develop && git pull`, confirm `git log --oneline -1` contains the #2232 merge, then
  `git switch -c feature/2230-german-decompounding`. Check that no other session is mid-edit in the checkout first
  (CLAUDE.md).
- [ ] **Step 2:** Read the Ngram datasets page (`https://storage.googleapis.com/books/ngrams/books/datasetsv3.html`,
  section 20200217, German 1-grams). Write down:
  - the shard URLs;
  - the line format, expected to be `ngram\tyear,match,volume\t…`;
  - whether POS-tagged tokens (`haus_NOUN`) are present.
  Download the shards **into a new, empty scratchpad directory**, outside the repo. Record each file's size and
  SHA-256 in `SOURCE`.
- [ ] **Step 3:** Write `generate.php` in the style of `resources/feed-directory/snapshot.php`
  (`declare(strict_types=1)`, `require vendor/autoload.php`). It:
  - streams each shard with `gzopen`/`gzgets`;
  - keeps the years 1950–2019 and the tokens matching `^\p{L}+$` with at least 3 letters;
  - casefolds with `mb_convert_case(…, MB_CASE_FOLD)` and NFKC;
  - sums the counts per form, overall and per tag;
  - classifies:
    - noun: the `_NOUN` count is at least half the tagged count; without tags, the capitalised spelling makes up at
      least half;
    - other: every remaining form;
    - verb: a `_VERB` form ending in *-en* that is not a noun, with at least 3 letters before the ending. Its present
      stem is the form minus *-en*, or minus *-n* after *-el* and *-er*, made bare. Its value is `bare(Snowball
      stem)`, from `Wamania\Snowball\StemmerFactory::create('de')` and `App\Service\Curation\Trending\Support\HeadlineStems::bare`;
  - keeps the most frequent N forms, with N the largest multiple of 10,000 whose output is at most 6 MB;
  - writes the keys sorted with `var_export`-safe quoting, so the output is deterministic.
- [ ] **Step 4:** Run `php resources/german-lexicon/generate.php <shard dir>` from `backend/`. Expected: it prints
  N, the size (≤ 6,291,456 bytes) and the three counts. Then run it a second time and `cmp` the two outputs. Expected:
  identical.
- [ ] **Step 5:** Measure the load cost and record it in `SOURCE`:
  `php -d opcache.enable_cli=0 -r '$t=hrtime(true);$m=memory_get_usage();$l=require "resources/german-lexicon/lexicon.php";printf("%.0f ms %.1f MB\n",(hrtime(true)-$t)/1e6,(memory_get_usage()-$m)/1048576);'`
  Expected: ≤ 500 ms and ≤ 40 MB. If not, lower N by 10,000 steps until it fits, and record the final N.
- [ ] **Step 6:** Write `NOTICE.txt`. It covers:
  - the source: "Google Books Ngram Viewer dataset, version 20200217, German 1-grams, © Google";
  - the licence: CC BY 3.0 Unported, with its URL;
  - the changes: "aggregated per casefolded form over 1950–2019, filtered to the N most frequent forms, reduced to
    frequency classes, verb stems derived";
  - the line "lexicon.php is licensed CC BY 3.0; the repository's code stays MIT".
  Copy the CC BY 3.0 legal code into `LICENSE.txt`. Make `SOURCE` follow the `feed-directory/SOURCE` pattern: URLs,
  hashes, fetched date, years, N, the noun rule used, and the load measurement.
- [ ] **Step 7:** Copy the spec and this plan into `docs/superpowers/`. Commit:
  `feat(#2230): generate a German lexicon from the Google Books Ngram data`.

### Task 2: The lexicon behind an interface, loaded lazily

**Files:**
- Create: `backend/src/Service/Curation/Trending/GermanLexicon/GermanLexiconInterface.php`,
  `GermanLexicon/BundledGermanLexicon.php`, `Model/GermanLexiconModel.php`,
  `Exception/UnknownVerbStemException.php`
- Create: `backend/tests/Fixtures/german-lexicon/lexicon.php` (a small fixture of general words, below)
- Test: `backend/tests/Service/Curation/Trending/GermanLexicon/BundledGermanLexiconTest.php`,
  `backend/tests/Service/Curation/Trending/GermanLexicon/BundledLexiconDataTest.php`

**Interfaces:**
- Produces `interface GermanLexiconInterface`, with these methods:
  - `isNoun(string $form): bool`
  - `isKnown(string $form): bool`
  - `frequencyClassOf(string $form): int`: an unknown form gives the rarest class + 1.
  - `isVerbStem(string $presentStem): bool`
  - `infinitiveStemOf(string $presentStem): string`: throws `UnknownVerbStemException` for a stem that is not a verb
    stem.
- Produces `final class BundledGermanLexicon implements GermanLexiconInterface, ResetInterface`:
  - its constructor takes `#[Autowire('%kernel.project_dir%/resources/german-lexicon/lexicon.php')] string $path`;
  - it requires `$path` on the first call of any method;
  - `reset()` drops the loaded model.
- Produces `final readonly class GermanLexiconModel`:
  - `__construct(array $nouns, array $others, array $verbs)`, typed as in Task 1;
  - `static fromFile(mixed $loaded): self` validates the shape and throws `\UnexpectedValueException` on a bad file;
  - it holds the same five lookups and computes the rarest class once.
- The fixture covers:
  - nouns: `polizei 9, einsatz 10, einsätze 12, spionage 13, verdacht 11, kinder 6, arzt 9, praxis 9, bund 7, rat 8,
    arbeit 6, amt 9, schule 8, bus 9, aktion 11, akt 14, ion 17, urin 15, instinkt 13, stau 12, becken 13, staub 12,
    ecken 13, tag 5, haus 6, russland 8, reise 9, bett 10, macht 7, nominierung 12`;
  - others: `groß 4, vor 2, ur 9`;
  - verbs: `verhandel => verhandeln, kundig => kundig, arbeit => arbeit, nominier => nominier, bet => bet`.

- [ ] **Step 1: Failing tests.** `BundledGermanLexiconTest` runs on the fixture:
  - `isNoun('polizei')` is true;
  - `isNoun('groß')` is false;
  - `isKnown('groß')` is true;
  - `isKnown('nirgendwo')` is false;
  - `frequencyClassOf('akt')` is 14;
  - `frequencyClassOf('nirgendwo')` is 18, the rarest (17) + 1;
  - `isVerbStem('verhandel')` is true;
  - `infinitiveStemOf('verhandel')` is `'verhandeln'`;
  - `infinitiveStemOf('laufen')` throws `UnknownVerbStemException`.

  The lazy and reset test:
  1. Write a temporary lexicon file A with `nouns: ['eins' => 1]` and look it up.
  2. Overwrite it with B (`['zwei' => 1]`). `isNoun('zwei')` is still false.
  3. After `reset()`, `isNoun('zwei')` is true.

  The bad-file test: a file returning `['nouns' => 'x']` throws `\UnexpectedValueException`.

  `BundledLexiconDataTest` runs on the real file:
  - `filesize ≤ 6_291_456`;
  - every key is a string of at least 3 characters;
  - every class is an int ≥ 0;
  - `array_intersect_key(nouns, others) === []`;
  - every `verbs` value is a non-empty string.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Curation/Trending/GermanLexicon`. Expected: FAIL (the classes are
  missing).
- [ ] **Step 3:** Implement. Give `BundledGermanLexicon` the `ResetInterface`, autoconfigured under `kernel.reset`.
  Expected from `bin/console debug:container --tag=kernel.reset | grep BundledGermanLexicon`: one line.
- [ ] **Step 4:** Run the tests again. Expected: PASS. Then run `composer stan` and `composer md` on the new files.
  Expected: clean.
- [ ] **Step 5:** Commit `feat(#2230): a German lexicon loaded on first use`.

### Task 3: The compound splitter

**Files:**
- Create: `backend/src/Service/Curation/Trending/GermanCompoundSplitter.php`,
  `Pass/CompoundSplitSearch.php`, `Model/CompoundSplitModel.php` (if the search needs one)
- Test: `backend/tests/Service/Curation/Trending/GermanCompoundSplitterTest.php`

**Interfaces:**
- Produces `final readonly class GermanCompoundSplitter`:
  - `__construct(GermanLexiconInterface $lexicon)`;
  - `partsOf(string $word): list<string>`. The word is casefolded with its diacritics kept. The result is the
    resolved lexicon forms in order, or `[]`.
  - Its constants: `MIN_PART_LENGTH = 3`, `MAX_PARTS = 4`, and the linking variants `LINKING_SUFFIXES = ['s', 'es',
    'n', 'en', 'er', 'e', 'ens']` plus the restored `e`.
- Produces `final class CompoundSplitSearch` in `Pass/`, built with `new` per word. It memoises the best split per
  suffix offset and enforces `MAX_PARTS`.
- Consumes `GermanLexiconInterface` (the fixture from Task 2, through `BundledGermanLexicon` on the fixture path).

- [ ] **Step 1: Failing tests** (data provider `word => expected parts`):
  - `polizeieinsatz` gives `['polizei', 'einsatz']`;
  - `polizeieinsätze` gives `['polizei', 'einsätze']` (an inflected head);
  - `kinderarzt` gives `['kinder', 'arzt']`;
  - `bundesrat` gives `['bund', 'rat']` (*-es-*);
  - `arbeitsamt` gives `['arbeit', 'amt']` (*-s-*);
  - `schulbus` gives `['schule', 'bus']` (the restored *-e*);
  - `kinderarztpraxis` gives `['kinder', 'arzt', 'praxis']` (recursion);
  - `staubecken` gives `['stau', 'becken']` (equal mean class 12.5, so the tie goes to the longer head);
  - `aktion` gives `[]` (unsplit class 11 against the parts' mean 15.5);
  - `urinstinkt` gives `[]` (*ur* is under 3 letters, and *stinkt* is no noun);
  - `hausgroß` gives `[]` (the head must be a noun);
  - `xyzhaus` gives `[]` (unknown modifier);
  - `tagra` gives `[]` (shorter than 6);
  - `polizei` gives `[]` (no split into known parts).

  Separately, `tagtagtagtagtag` gives `[]`: it needs five parts, one more than `MAX_PARTS`. And `tagtagtagtag` gives
  `['tag', 'tag', 'tag', 'tag']`, which is exactly `MAX_PARTS`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Curation/Trending/GermanCompoundSplitterTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement as the spec says:
  - enumerate the split points from the left, so the head is the longest suffix tried first;
  - the head must be a noun form, and a modifier resolves to its most frequent known linking variant;
  - recurse on the modifier prefix;
  - the score is the mean frequency class, lowest first; ties go to fewer parts, then to the longer head;
  - the unsplit candidate is `frequencyClassOf($word)` with one part;
  - return `[]` when the unsplit candidate wins.
- [ ] **Step 4:** Run the test. Expected: PASS. Then run `composer md`. Expected: no finding in the new files.
- [ ] **Step 5:** Commit `feat(#2230): split German compounds by part frequency`.

### Task 4: Parts become stems in Trending

**Files:**
- Modify: `backend/src/Service/Curation/Trending/HeadlineEquivalents.php`, `Pass/HeadlineWindow.php`
- Modify: `backend/tests/Support/TrendingWiring.php`. It builds `GermanCompoundSplitter` on the fixture lexicon,
  through a new `TrendingWiring::germanLexicon()` that returns one `BundledGermanLexicon` on
  `tests/Fixtures/german-lexicon/lexicon.php`.
- Test: `backend/tests/Service/Curation/Trending/HeadlineEquivalentsTest.php`,
  `backend/tests/Service/Curation/Trending/Pass/HeadlineWindowTest.php`,
  `backend/tests/Service/Curation/Trending/TrendingContainerWiringTest.php`

**Interfaces:**
- `HeadlineEquivalents::__construct(HeadlineStemmer $stemmer, GermanCompoundSplitter $splitter)`
- `HeadlineEquivalents::wordStemsOf(string $word, string $bare, ?FeedLanguage $language): list<string>` replaces
  `stemOf()`.
  - The whole stem comes first, then each part through `HeadlineStemmer::stem($part, German)` and the German
    equivalence map. Duplicates are removed.
  - Parts apply only for `FeedLanguage::German`.
  - A canonical bare word gives `[$bare]`.
- `HeadlineEquivalents::stemsOf(string $text, ?FeedLanguage $language): list<string>` flattens `wordStemsOf` and keeps
  only distinct stems.
- `HeadlineWindow::$stemsByWord` becomes `array<string, list<string>>`. `idsOf` posts every stem that is no seed.

- [ ] **Step 1: Failing tests.**

  `HeadlineEquivalentsTest`:
  - `stemsOf('Polizeieinsatz', German)` equals, ignoring order, `[stem('polizeieinsatz'), stem('polizei'),
    stem('einsatz')]`, where `stem` is `TrendingWiring::stemmer()->stem($w, German)`;
  - `stemsOf('Polizeieinsatz', English)` has one element;
  - `stemsOf('Polizeieinsatz', null)` is `['polize']`;
  - `stemsOf('Russlandreise', German)` contains `'russia'`;
  - `stemsOf('Aktion', German)` has one element.

  `HeadlineWindowTest`, with German entries:
  - Entry 1 "Polizei räumt Camp" and entry 2 "Großer Polizeieinsatz am Bahnhof" (different feeds):
    `sharedWeightsWithEarlier(2)` has key 1.
  - Entries 3 and 4 both say "Polizeieinsatz am Bahnhof". Their shared weight is greater than the 1–2 pair's.
  - Entry 5 "Polizei: Polizeieinsatz" has a total weight equal to the sum of three distinct stems' rarities, so
    *polizei* counts once.
  - A part stem in at least 3 % of a floored window weighs nothing. Use the existing filler pattern with 45 entries
    holding `tag` through *Bundestag* and *Sonntag*: two entries sharing only the part `tag` are not in each other's
    `sharedWeightsWithEarlier`.

  `TrendingContainerWiringTest`: the container builds `HeadlineEquivalents` with `GermanCompoundSplitter`, and
  `GermanLexiconInterface` resolves to `BundledGermanLexicon`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Curation/Trending`. Expected: the new tests FAIL. Also report any
  existing test that now fails, with the reason. A German example in an existing test may gain parts, and its expected
  value is updated only if the new stems are right.
- [ ] **Step 3:** Implement. Bind `GermanLexiconInterface` to `BundledGermanLexicon`, by autowiring the single
  implementation or with an alias in `services.yaml` if autowiring cannot pick it.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Trending tests/Command`. Expected: PASS. Then run
  `composer md` on `HeadlineEquivalents.php` and `HeadlineWindow.php`. Expected: no finding. If PHPMD fires, fix the
  design, without a suppression and without moving methods into a class just to get under the count.
- [ ] **Step 5:** Commit `feat(#2230): a German compound's parts are Trending stems`.

### Task 5: German *-t* verb forms join their infinitive

**Files:**
- Create: `backend/src/Service/Curation/Trending/GermanVerbStems.php`
- Modify: `backend/src/Service/Curation/Trending/HeadlineStemmer.php`, `backend/tests/Support/TrendingWiring.php`
- Test: `backend/tests/Service/Curation/Trending/GermanVerbStemsTest.php`,
  `backend/tests/Service/Curation/Trending/HeadlineStemmerTest.php`

**Interfaces:**
- `final readonly class GermanVerbStems`:
  - `__construct(GermanLexiconInterface $lexicon)`;
  - `stemOf(string $stem, string $word): string`. Here `$stem` is the bare Snowball German stem and `$word` is the
    casefolded word, used for the noun guard.
- `HeadlineStemmer::__construct(SnowballStemmerFactory $stemmerFactory, GermanVerbStems $verbStems)`. `stem()` keeps
  its signature and applies the verb rule for German only.

- [ ] **Step 1: Failing tests.**

  `GermanVerbStemsTest`, on the fixture:
  - `stemOf('verhandelt', 'verhandelt')` is `'verhandeln'`;
  - `stemOf('kundigt', 'kündigt')` is `'kundig'`;
  - `stemOf('arbeitet', 'arbeitet')` is `'arbeit'`;
  - `stemOf('verhandeln', 'verhandeln')` is `'verhandeln'`;
  - `stemOf('bett', 'bett')` is `'bett'` (the noun guard; `bet` is a verb stem);
  - `stemOf('macht', 'macht')` is `'macht'` (the noun guard);
  - `stemOf('rot', 'rot')` is `'rot'`.

  `HeadlineStemmerTest`:
  - `stem('verhandelt', German)` is the same as `stem('verhandeln', German)`;
  - `stem('nominiert', German)` is the same as `stem('Nominierung', German)`;
  - `stem('voted', English)` stays as before, `'vote'`;
  - `stem('verhandelt', null)` is `'verhan'`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Curation/Trending/GermanVerbStemsTest.php
  tests/Service/Curation/Trending/HeadlineStemmerTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement the rule as the spec says. The order is `s`, then `s−et`, then `s−t`, the match maps to
  `infinitiveStemOf()`, and the rule is skipped when `isNoun($word)`.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Trending`. Expected: PASS, including
  `HeadlineFillerTest`, whose seeds go through the same stemmer.
- [ ] **Step 5:** Commit `feat(#2230): German -t verb forms stem like their infinitive`.

### Task 6: Gates

**Files:** whatever the gates point at. Touch nothing else.

- [ ] **Step 1:** `composer check` from `backend/` (run `bin/console cache:warmup` first if stan needs it).
  Expected: phpcs, PHPStan and phptramp all clean, and phptramp shows no new chain ≥ 3 hops over 2 classes. If
  phptramp flags the `language` thread, check `composer show larspohlmann/phptramp` before blaming the code.
- [ ] **Step 2:** `composer md`. Expected: no finding in any touched `src` file.
- [ ] **Step 3:** Run PhpStorm `lint_files` on the changed PHP. Expected: no ERROR or WARNING.
- [ ] **Step 4:** Run `composer test:parallel` and `docker compose exec php composer test:parallel` at the same time.
  Expected: both green. Before running the MySQL leg, check that the container serves this checkout.
- [ ] **Step 5:** Commit everything, then `composer infection:diff`. Expected: `Covered Code MSI` ≥ 80 %, with
  every escaped mutant either killed by a new assertion or explained in the PR. If the initial run OOMs, rerun it, as
  the known flake requires.
- [ ] **Step 6:** Scan today's dev log for deprecations:
  `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -r 'select(.level_name=="WARNING" or .level_name=="ERROR" or (.message|test("eprecat"))) | .message'`.
  Expected: nothing new from Trending or the lexicon.
- [ ] **Step 7:** Run `/simplify` on the branch, apply what it finds, rerun steps 1–5, and commit.

### Task 7: Measurement, written into the spec's Outcome

**Files:**
- Modify: `docs/superpowers/specs/2026-11-15-2230-german-decompounding-design.md` (the Outcome section)
- Scratch only (never committed): the A/B outputs, and the `lang-pairs.py` helper in the scratchpad.

The kit is at `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/5bb3d5ca-28c2-427b-85d0-779b6950f4b5/scratchpad/measure-kit/`.
Its rules: read-only against the dev DB, run natively from `backend/`, and the dev MySQL is at 127.0.0.1:33306.

- [ ] **Step 1: Preconditions.**
  - The dev DB carries the #2227 migrations.
  - `php bin/console app:trending:explain --window=week | head` shows language coverage ≥ 95 %. If not, report the
    coverage and go on. Do not fill languages by hand.
  - Pick one pinned time `T`, the current hour (for example `"2026-11-16 12:00:00"`), and use it for every run.
- [ ] **Step 2: The three variants**, swapped file by file as in #2227. Keep the branch copies in the scratchpad and
  restore them with `cp`, never with `git checkout`:
  - **A:** `git show develop:backend/src/Service/Curation/Trending/{HeadlineEquivalents.php,HeadlineStemmer.php,Pass/HeadlineWindow.php}`
    in place of the branch files;
  - **B1:** the branch `HeadlineEquivalents.php` and `Pass/HeadlineWindow.php`, with develop's `HeadlineStemmer.php`
    (no verb rule);
  - **B2:** the branch as committed.

  After each swap, run `php bin/console debug:container 'App\Service\Curation\Trending\HeadlineEquivalents'
  --show-arguments`. Expected: the arguments match the variant, so the dev container was rebuilt.
- [ ] **Step 3: Runs**, for each variant `V` and each window `W` in `day` and `week`:
  `/usr/bin/time -l php $KIT/simp-explain-at.php "$PWD" W "$T" > $S/2230-V-W.txt 2> $S/2230-V-W.time`
  Expected: six outputs, each `.time` file with a "maximum resident set size" line.
- [ ] **Step 4: Compare.** Run `python3 $KIT/compare.py` for A→B1, B1→B2 and A→B2, for each window.
  - Record the groups and the grouped pairs (new, lost, net).
  - Write `lang-pairs.py` (an adaptation of `t6-classify.py`). It maps member headlines to their language through a
    read-only `SELECT` on `entry`/`feed`, then splits the new and lost pairs into de–de, de–other, other.
  - Record the de–de net for each comparison.
  - Count the near-miss lines containing `share of the lighter entry`, in A and in B2.
- [ ] **Step 5: Eye check.** Take the 10 largest new groups, the 10 most grown and the 10 largest splits in A→B2,
  for the day and the week. For each, write whether it is a real story. Flag every group whose new members share only
  a compound part with the rest (the anti-goal). Run `t6-splits.py` (adapted) on A→B2's lost week pairs, and report
  how many lost bridges are compounds now, against the #2227 heuristic count (255 week, 35 day).
- [ ] **Step 6: Registered remedies only.**
  - If step 5 flags a part-only wrong merge, apply the 1 % part floor (spec, "Remedies"):
    - generator: `'commonPartClass'`;
    - splitter: a part at or below that class gives no stem;
    - a test for each;
    - rerun B2 → B3, and report.
  - If the de–de or de–other net is negative and the share-of-lighter near misses rose by more than the pairs gained,
    apply half the place weight for parts once, rerun, and report the better variant.
  - If B1→B2 is net negative on the week, or shows a wrong merge through the verb rule, revert Task 5 and record it.
  - Never change a part, a word or any other value.
- [ ] **Step 7:** Write the Outcome section in the style of #2227's:
  - a table per window: entries, coverage, groups, grouped pairs (new, lost, net) for A→B1, B1→B2 and A→B2, de–de
    net, wall time, and peak RSS;
  - the lexicon's file size, load time and memory (from Task 1);
  - the eye-check examples, real and wrong;
  - the compound and *-t* bridge counts against #2227's;
  - any remedy that fired and why.
  The anti-goal check: the week must not lose real stories net. A part-only wrong merge left standing blocks the PR,
  and the Outcome says so. Then commit with `docs(#2230): measure German decompounding`.
- [ ] **Step 8:** Report to the planner: net pairs (overall and de–de, day and week), run time, peak RSS, the largest
  new, grown and split groups with verdicts, and any remedy. The PR itself is the controller's call.
