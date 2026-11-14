# #2225 Trending equivalence table + FR/ES seed stems Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Map spellings of one thing (abbreviations, cross-language names, multi-word names) to one canonical stem before Trending links entries, and add French/Spanish seed stems.

**Architecture:** A new injected service `HeadlineEquivalents` in the Trending module root turns a text into stems: case-sensitive abbreviations on the raw text, then `HeadlineStems`' fold/split, then multi-word phrases on the folded words, then the length rule and six-character cut, then stem-to-canonical mappings. `HeadlineWindow` asks it instead of calling `HeadlineStems::of`. Weighting, filler rules and link thresholds are untouched.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 12, dev MySQL for measurement.

**Spec:** `docs/superpowers/specs/2026-11-14-2225-trending-equivalence-design.md`

## Global Constraints

- Work in the worktree `.claude/worktrees/2225-trending-equivalence`, branch `feature/2225-trending-equivalence`; commits `type(#2225): …`, no attribution lines.
- No change to `HeadlineLinks` thresholds (14, 0.4), `HeadlineFiller` shares (3 %, 50 %, floor 1,500), the six-character cut, or the minimum word lengths for unmapped words.
- No fuzzy matching, no general synonyms, no language detection.
- Every canonical stem: at least 3 characters, already in `HeadlineStems` form (casefolded, no diacritics, at most 6 characters), not a seed stem, not itself a key of the stem table.
- An exact-word entry fires only when the text contains a lower-case letter and neither neighbouring word is all-caps (two or more letters).
- A stem entry goes in only after the collision check (Task 5); an entry whose canonical reaches the 3 % window share on the dev day or week window is left out.
- CLAUDE.md gates: `composer check`, `composer md` clean on touched `src` files, `php bin/phpunit`, `composer infection:diff`. PhpStorm inspections cannot see worktree files; say so instead of reporting them clean.

## Review Focus

- A hyphenated abbreviation (*U-Haft*) inside a compound or followed by a suffix (*U-Haft-Antrag*): treated as the whole word *U-Haft* only when bounded by non-letters; the boundary is a non letter/digit **other than a hyphen** on the right, so *U-Haft-Antrag* does not match. Test in Task 2.
- A phrase that overlaps another phrase or repeats in one text (*Vereinigte Staaten … Vereinigte Staaten*): longest match first, each occurrence consumed, the canonical counted once (stems are distinct). Test in Task 2.
- A phrase whose words are split by punctuation in the source (*États-Unis*, *Etats Unis*, *ÉTATS-UNIS*): matched alike, because phrases match on the folded word list, not on the raw text. Test in Task 2.
- An abbreviation at the very start or end of a text, or as the whole text (`EU`): neighbour check treats a missing neighbour as not all-caps; a whole-text `EU` has no lower-case letter, so it does not fire. Test in Task 2.
- Body text uses the same path as the headline: an equivalence in the lead links to a headline spelling at body weight. Test in Task 3.

---

### Task 1: Expose `HeadlineStems`' word-level pieces

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Support/HeadlineStems.php`
- Modify: `backend/tests/Service/Curation/Trending/Support/HeadlineStemsTest.php`

**Interfaces:**
- Produces: `HeadlineStems::words(string $text): list<string>` — casefolded, diacritics stripped, split on non-letter/digit (the current private `words`, made public). `HeadlineStems::ofWords(list<string> $words): list<string>` — keeps words passing the length rule, cuts each to six characters, distinct, reading order. `of(string)` is deleted (its only `src` caller moves to `HeadlineEquivalents` in Task 3).

- [ ] **Step 1:** In `HeadlineStemsTest`, replace every `HeadlineStems::of($text)` with a private helper `self::stems(string $text): array` returning `HeadlineStems::ofWords(HeadlineStems::words($text))`; add `testWordsAreFoldedAndSplitButNotCut`: `self::assertSame(['vereinigte', 'staaten', 'etats', 'unis'], HeadlineStems::words('Vereinigte Staaten, États-Unis'))`.
- [ ] **Step 2:** Run `cd backend && php bin/phpunit tests/Service/Curation/Trending/Support/HeadlineStemsTest.php` — expect errors (`words` private, `ofWords` undefined).
- [ ] **Step 3:** Make `words` public, add `ofWords`, delete `of`; `HeadlineWindow` keeps compiling by calling `HeadlineStems::ofWords(HeadlineStems::words(...))` until Task 3.
- [ ] **Step 4:** Run the test file and `tests/Service/Curation/Trending` — expect PASS.
- [ ] **Step 5:** Commit `refactor(#2225): expose the word-level steps of HeadlineStems`.

### Task 2: `HeadlineEquivalents` service with the three tables

**Files:**
- Create: `backend/src/Service/Curation/Trending/HeadlineEquivalents.php`
- Test: `backend/tests/Service/Curation/Trending/HeadlineEquivalentsTest.php`

**Interfaces:**
- Consumes: `HeadlineStems::words`, `HeadlineStems::ofWords` (Task 1).
- Produces: `final readonly class HeadlineEquivalents` with `public function stemsOf(string $text): array` (`list<string>`, distinct; order not significant). Private constants `EXACT_WORDS` (`array<string, string>`: case-sensitive raw whole word → canonical; abbreviations, and long forms whose six-letter stem would collide), `PHRASES` (`array<string, string>`: folded words joined by one space → canonical), `STEMS` (`array<string, string>`: cut stem → canonical).

Starting table (Task 5 grows it):
- `EXACT_WORDS`: `US`, `USA`, `EEUU` → `usa`; `EU`, `UE` → `europa`; `UN`, `UNO`, `ONU` → `onu`; `WM` → `weltme`; `U-Haft`, `Untersuchungshaft` → `uhaft` (the stem `unters` would also catch *unterstützen*).
- `PHRASES`: `united states`, `vereinigte staaten`, `etats unis`, `estados unidos`, `ee uu` → `usa`; `united nations`, `vereinte nationen`, `nations unies`, `naciones unidas` → `onu`; `europaische union`, `european union`, `union europeenne`, `union europea` → `europa`.
- `STEMS`: `europe` → `europa`; `german`, `allema`, `aleman` → `deutsc`; `zelens` → `selens`.

- [ ] **Step 1: Write the failing tests** (use `assertEqualsCanonicalizing` for stem lists):
  - `testCrossLanguageNamesShareOneStem`: `stemsOf('Germany')`, `stemsOf('Deutschland')`, `stemsOf('Allemagne')`, `stemsOf('Alemania')` all equal `['deutsc']`.
  - `testAPhraseIsConsumedIntoOneStem`: `stemsOf('United States tariffs')` equals `['usa', 'tariff']` (no `united`, no `states`).
  - `testPhrasesMatchAcrossPunctuationAndCase`: `stemsOf('États-Unis')`, `stemsOf('ETATS UNIS et Chine')` contain `usa` and not `etats`/`unis`.
  - `testARepeatedPhraseCountsOnce`: `stemsOf('Vereinigte Staaten und Vereinigte Staaten')` equals `['usa', 'und']` (seeds are dropped later, in `HeadlineWindow`).
  - `testTheSpanishAbbreviationWithDotsIsAPhrase`: `stemsOf('Los EE. UU. anuncian')` contains `usa`.
  - `testAShortAbbreviationIsKeptAndMapped`: `stemsOf('EU to ban AI')` equals `['europa', 'ban']`.
  - `testALowerCaseWordIsNotAnAbbreviation`: `stemsOf('Tell us more')` contains no `usa`.
  - `testAnAbbreviationInAllCapsTextDoesNotFire`: `stemsOf('LET US GO')` and `stemsOf('EU')` contain no `usa`/`europa`.
  - `testAnAbbreviationNextToAnAllCapsWordDoesNotFire`: `stemsOf('Breaking: WHO US NEWS today')` contains no `usa`.
  - `testAHyphenatedAbbreviationMatchesOnlyAsAWholeWord`: `stemsOf('Richter verhängt U-Haft')` contains `unters`; `stemsOf('Der U-Haft-Antrag scheitert')` does not; `stemsOf('Wir unterstützen die Untersuchungshaft')` contains `uhaft` and `unters`, and `stemsOf('Wir unterstützen')` contains no `uhaft`.
  - `testUnlistedWordsAreStemmedAsBefore`: `stemsOf('Ukraine Ukrainian talks')` equals `['ukrain', 'talks']`.
  - `testEveryCanonicalStemIsInStemForm`: read the three constants via `ReflectionClassConstant` (`EXACT_WORDS`, `PHRASES`, `STEMS`); for every value assert `mb_strlen >= 3`, `<= 6`, equals `HeadlineStems::ofWords(HeadlineStems::words($value))[0]`, `!(new HeadlineFiller())->isSeed($value)`, and `!isset(STEMS[$value])`.
  - `testEveryPhraseKeyIsInFoldedForm`: each `PHRASES` key equals `implode(' ', HeadlineStems::words($key))` and has at least two words.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Curation/Trending/HeadlineEquivalentsTest.php` — expect FAIL (class missing).
- [ ] **Step 3: Implement `stemsOf`.** Steps in order: (a) exact words: one `preg_replace_callback` over an alternation of the escaped keys, longest first, bounded by `(?<![\p{L}\p{N}-])` and `(?![\p{L}\p{N}-])`; the callback replaces with ` <canonical> ` only when the text contains `\p{Ll}` and neither neighbouring word (nearest letter/digit run left and right of the match) is all-caps with two or more letters, else returns the match unchanged. (b) `HeadlineStems::words` on the result. (c) Phrases: walk the word list; at each position try the longest phrase first (index phrases by first word; the longest phrase length bounds the look-ahead); a match appends its canonical and skips its words. (d) `HeadlineStems::ofWords` on the unconsumed words. (e) Replace each stem found in `STEMS`. (f) Exact-word canonicals came through (b)–(d) as ordinary words; they already satisfy the stem form and pass the length rule. Return distinct values.
- [ ] **Step 4:** Run the test file — expect PASS. Run `composer md` on the new file — expect clean; if PHPMD flags the class, split the design along a real seam (e.g. a `Pass/` phrase walker), not to dodge the count.
- [ ] **Step 5:** Commit `feat(#2225): map abbreviations, phrases and cross-language names to one stem`.

### Task 3: Wire `HeadlineEquivalents` into the window

**Files:**
- Modify: `backend/src/Service/Curation/Trending/Pass/HeadlineWindow.php` (constructor, `add`)
- Modify: `backend/src/Service/Curation/Trending/TrendingGrouping.php` (constructor, `collect`)
- Modify: `backend/tests/Support/TrendingWiring.php`, `backend/tests/Support/TrendingRuns.php`
- Test: `backend/tests/Service/Curation/Trending/Pass/HeadlineWindowTest.php`

**Interfaces:**
- Consumes: `HeadlineEquivalents::stemsOf` (Task 2).
- Produces: `new HeadlineWindow(HeadlineFiller $filler, HeadlineEquivalents $equivalents)`; `TrendingGrouping` gains constructor parameter `HeadlineEquivalents $equivalents` after `$filler`.

- [ ] **Step 1:** In `HeadlineWindowTest`, build the window with `new HeadlineWindow(new HeadlineFiller(), new HeadlineEquivalents())` and add `testEquivalentSpellingsShareWeight`: entries 1 (`'Germany wins the final'`) and 2 (`'Deutschland gewinnt das Finale'`) in different feeds, plus enough filler entries as the existing tests do → `sharedWeightsWithEarlier(2)` has key `1`. Add `testAnEquivalentInTheBodyCountsAtBodyWeight`: headline `'Zelensky meets Macron'` vs headline `'Macron empfängt Gast'` with body `'Selenskyj reist nach Paris'` → shared weight with `1` exceeds the weight of `macron` alone.
- [ ] **Step 2:** Run the test file — expect FAIL (constructor arity / no shared weight).
- [ ] **Step 3:** `HeadlineWindow::add` takes stems from `$this->equivalents->stemsOf($headline)` / `($body)`; `TrendingGrouping::collect` passes `$this->equivalents`; update both test supports.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Trending` and `TrendingContainerWiringTest` — expect PASS (autowiring picks up the new service).
- [ ] **Step 5:** Commit `feat(#2225): stem Trending headlines through the equivalence table`.

### Task 4: French and Spanish seed stems

**Files:**
- Modify: `backend/src/Service/Curation/Trending/HeadlineFiller.php` (`SEED_STEMS`)
- Test: `backend/tests/Service/Curation/Trending/HeadlineFillerTest.php`

- [ ] **Step 1:** Add `testFrenchAndSpanishFunctionWordsAreSeeds` over a data provider: `les, pour, que, dans, une, sur, avec, est, pas, qui, aux, par, plus, del, los, las, para, una, por, con, como, mas, sus, entre` (cut form) → `isSeed` true. Add `testAWordThatCarriesMeaningInAnotherLanguageIsNotASeed`: `son`, `sin`, `fin` → `isSeed` false. Add `testEverySeedIsInStemForm`: each key equals `HeadlineStems::ofWords(HeadlineStems::words($key))[0]`.
- [ ] **Step 2:** Run — expect FAIL.
- [ ] **Step 3:** Add French and Spanish function words (articles, prepositions, conjunctions, pronouns, auxiliaries) in cut form; leave out any word that is a content word in DE/EN/FR/ES (`son`, `sin`, `fin`, `car`, `mal`).
- [ ] **Step 4:** Run `tests/Service/Curation/Trending` — expect PASS.
- [ ] **Step 5:** Commit `feat(#2225): add French and Spanish seed stems`.

### Task 5: Curate the table against the dev data

**Files:**
- Modify: `backend/src/Service/Curation/Trending/HeadlineEquivalents.php` (tables only)
- Throwaway, not committed: scripts in the session scratchpad.

- [ ] **Step 1:** Write a scratchpad script (booted kernel, `APP_ENV=dev`, `DATABASE_URL=mysql://feedreader:feedreader@127.0.0.1:33306/feedreader?serverVersion=8.4&charset=utf8mb4`) that reads the day and week window entries through `TrendingEntryRepository::windowEntries` and prints, for every key of `STEMS` and every canonical of all three tables, the distinct folded words producing that stem with counts (a canonical produced by an ordinary word, e.g. Spanish *usa* = "uses", is a collision too), and for every canonical its document frequency as a share of `max(n, 1500)`.
- [ ] **Step 2:** Run it; drop or move to `PHRASES`/`EXACT_WORDS` any stem entry whose words include an unrelated word; drop any entry whose canonical reaches 3 % in either window.
- [ ] **Step 3:** Grow the table from (a) the near misses of `app:trending:explain` (both windows, natively with the env above) that an abbreviation, phrase or name mapping would rescue, and (b) recurring news names across the four languages (G7 and EU-neighbour countries, NATO/OTAN, EU institutions, heads of government in the window). Re-run Step 1 for every added stem entry.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Curation/Trending` — expect PASS (invariant tests guard every new entry).
- [ ] **Step 5:** Commit `feat(#2225): curate the equivalence table from the dev windows`.

### Task 6: Measure before/after and pass the gates

**Files:** none committed; results go in the PR body draft `.superpowers/pr-body-2225.md` in this worktree.

- [ ] **Step 1:** `git worktree add --detach .claude/worktrees/2225-before origin/develop` from the main checkout; `cp -R` this worktree's `backend/vendor` into it.
- [ ] **Step 2:** Back to back, in each worktree's `backend/`, with the env from Task 5: `/usr/bin/time -l bin/console app:trending:explain --window=day > <scratch>/<side>-day.txt`, same for `week`. Before first, then after, immediately.
- [ ] **Step 3:** Scratchpad compare script: from the group listings, pairs of entries grouped together after but not before (**new**), before but not after (**lost**); near-miss totals; time and max RSS from `time -l`.
- [ ] **Step 4:** Eye-check every new or grown group; any wrong merge sends its entry back to Task 5 (remove, re-measure).
- [ ] **Step 5:** Gates: `composer check`, `composer md`, `php bin/phpunit`, `composer infection:diff` — all green; MySQL leg `composer test:parallel` cannot run from the worktree's code inside Docker (the container mounts the main checkout) — run it after the branch is checked out there, or report it as not run.
- [ ] **Step 6:** Remove the `2225-before` worktree; write the numbers into the PR body draft.
