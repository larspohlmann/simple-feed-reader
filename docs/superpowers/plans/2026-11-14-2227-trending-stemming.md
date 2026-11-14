# Trending language-aware stemming (#2227) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stem each Trending entry with the Snowball stemmer for its feed's language, where the feed's language is declared by the feed or detected from its entries; feeds without one keep the six-character cut.

**Architecture:** A nullable `feed.language` (`App\Enum\FeedLanguage`) is recorded at ingest by an injected resolver (declared `<language>`/`dc:language`/`xml:lang`, checked and overruled by `patrickschur/language-detection` over the fetched entries). Trending reads it with each window entry and passes it to `HeadlineEquivalents`, which stems through an injected `wamania/php-stemmer` wrapper and applies per-language equivalence and seed tables.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine (SQLite + MySQL), `wamania/php-stemmer` ^4.0, `patrickschur/language-detection` ^5.3.

**Spec:** `docs/superpowers/specs/2026-11-14-2227-trending-stemming-design.md`

## Global Constraints

- Languages: exactly `de`, `en`, `fr`, `es`. Anything else → no language.
- Feeds whose `getSourceFormat()` is in `SourceFormat::SOCIAL` never get a language.
- A refresh without a verdict keeps the stored language; a declared language outside the four with no confident detection records `null`.
- Detection overrules a declared language of the four when it is confident and disagrees.
- Diacritics are stripped **after** stemming; phrase and exact-word matching behave as in #2225.
- Weighting, filler shares, link thresholds, `MIN_WORD_LENGTH` (3) and the number rule (≥ 2 digits) are unchanged.
- Migration version later than `Version20261114160000` (#2218's last); idempotent `skipIf` and `abortIfUnsupportedPlatform` like `Version20261114100000`.
- House rules (CLAUDE.md): Clean Code, `final readonly`, no comment unless a future reader gets it wrong, PHPMD-clean touched files, no PHPStan docblocks/ignores added on my own, `Response::HTTP_*`-style constants, tests mirror `src/`.
- Gates before PR: `composer check`, `composer md`, `composer test:parallel`, `composer infection:diff` (gate in `infection.json5`); MySQL leg runs in CI (worktree).

## Review Focus

1. A first fetch or a timeline page with one or two entries — the stored language must not flip (Task 4 test: one-entry page after a German verdict keeps `de`).
2. A German feed declaring `en-US` — detection must win only when confident; a two-entry page must not overrule (Task 3 tests).
3. Words with *ß*, umlauts, accents and *ñ* — stemmed with their diacritics, then folded, so *Großbritannien* and *grossbritannien* agree, and *élections* / *elections* agree (Task 5 tests).
4. A phrase whose words carry diacritics (*Europäische Union*, *Unión Europea*) — still collapses after the word-split change (Task 5 test).
5. A window mixing a language-less social entry and a German news entry on a short name (*Merz*, *Macron*) — still links, since short names stem identically (Task 6 test).

---

### Task 1: Feed language column

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock` (add both libraries)
- Create: `backend/src/Enum/FeedLanguage.php`
- Modify: `backend/src/Entity/Feed.php`
- Create: `backend/migrations/Version20261114170000.php`
- Test: `backend/tests/Enum/FeedLanguageTest.php`, `backend/tests/Entity/FeedTest.php` (extend)

**Interfaces:**
- Produces: `enum FeedLanguage: string { case German = 'de'; case English = 'en'; case French = 'fr'; case Spanish = 'es'; public static function fromTag(?string $tag): ?self }` — lowercases, takes the primary subtag split on `-` or `_` (`de-AT`, `en_US`, ` EN `→ trimmed), returns a case or null.
- Produces: `Feed::getLanguage(): ?FeedLanguage`, `Feed::recordLanguage(?FeedLanguage $language): void`, column `language` `length: 2, nullable: true, enumType`.

- [ ] **Step 1:** `composer require wamania/php-stemmer:^4.0 patrickschur/language-detection:^5.3` from `backend/`. Expected: both in `composer.lock`, `composer check` still green.
- [ ] **Step 2: Failing tests** — `FeedLanguageTest`: data provider `['de','de'],['de-AT','de'],['en_US','en'],[' EN ','en'],['fr-CA','fr'],['es-419','es'],['it',null],['',null],[null,null],['deu',null]` → `fromTag($tag)?->value`. `FeedTest`: a new feed's `getLanguage()` is null; `recordLanguage(FeedLanguage::French)` then `getLanguage()` is `French`; `recordLanguage(null)` clears.
- [ ] **Step 3:** Run `php bin/phpunit tests/Enum/FeedLanguageTest.php tests/Entity/FeedTest.php`. Expected: FAIL (class/method missing).
- [ ] **Step 4:** Implement the enum and the entity column next to `$platform`.
- [ ] **Step 5:** Migration `Version20261114170000`: description `Store each feed's language for Trending's stemming (#2227).`; `ALTER TABLE feed ADD COLUMN language VARCHAR(2) DEFAULT NULL`; `down` drops it; skip if present/absent.
- [ ] **Step 6:** Run the tests and `bin/console doctrine:migrations:migrate --no-interaction` against a scratch SQLite DB then `doctrine:schema:validate` (env `DATABASE_URL=sqlite:///<scratchpad>/m.db`). Expected: PASS, schema in sync.
- [ ] **Step 7:** Commit `feat(#2227): store a feed's language`.

### Task 2: Parsers carry the declared language

**Files:**
- Modify: `backend/src/Service/Ingestion/Parser/Model/ParsedFeedModel.php`, `FeedFormatParser/Rss2Parser.php`, `Rss1Parser.php`, `AbstractAtomParser.php`
- Test: `backend/tests/Service/Ingestion/Parser/FeedFormatParser/{Rss2,Rss1,Atom10,Atom03}ParserTest.php`

**Interfaces:**
- Produces: `ParsedFeedModel::$declaredLanguage` (`?string`, named constructor argument, default null), raw as the feed states it.

- [ ] **Step 1: Failing tests** — RSS 2.0 `<channel><language>de-DE</language>` → `'de-DE'`; no element → null. RSS 1.0 `<channel><dc:language>fr</dc:language>` → `'fr'`. Atom 1.0 and 0.3 `<feed xml:lang="es">` → `'es'`; missing → null.
- [ ] **Step 2:** Run the four parser tests. Expected: FAIL.
- [ ] **Step 3:** RSS 2.0: `$channel->text('language')`. RSS 1.0: `$channel->in(FeedNamespace::DublinCore)->text('language')`. Atom: the root element's `xml:lang` (`getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang')`, empty → null). WordPress JSON, Mixcloud and scraped HTML stay null.
- [ ] **Step 4:** Run the parser tests and the whole `tests/Service/Ingestion/Parser`. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2227): read the feed's declared language`.

### Task 3: Language resolver

**Files:**
- Create: `backend/src/Service/Ingestion/Language/FeedLanguageResolver.php` (injected service: the verdict is tuned)
- Create: `backend/src/Service/Ingestion/Language/Model/LanguageVerdictModel.php`
- Test: `backend/tests/Service/Ingestion/Language/FeedLanguageResolverTest.php`

**Interfaces:**
- Consumes: `FeedLanguage::fromTag`, `ParsedFeedModel::$declaredLanguage`, `$entries` (`headline`, `summary`).
- Produces: `FeedLanguageResolver::verdictFor(ParsedFeedModel $parsed): ?LanguageVerdictModel` — null means *no verdict, keep what is stored*. `LanguageVerdictModel` holds `public ?FeedLanguage $language`.

Rules (in this order):
1. Detection text: each entry's headline text and plain-text summary (`EntryPlainText::of`), joined, cut to `TEXT_LIMIT` characters (start at 4,000). Detection only when the text has at least `MINIMUM_WORDS` words (start at 40) — fewer means "not confident".
2. Detector restricted to `de, en, fr, es`; confident when the best score ≥ `MINIMUM_SCORE` and leads the runner-up by ≥ `MINIMUM_LEAD`. Calibrate both on the dev feeds (Step 5) and keep them as constants.
3. Declared one of the four: confident detection that disagrees → detected; otherwise → declared.
4. Declared something else (or unparsable but non-empty): confident detection → detected; otherwise → verdict `null` language.
5. Declared nothing: confident detection → detected; otherwise → no verdict (null).

- [ ] **Step 1: Failing tests** with fixture texts long enough to detect (several real-looking headlines + summaries per language):
  - declared `de`, German text → `German`; declared `en-US`, German text (≥ 40 words) → `German`; declared `en-US`, two short German headlines → `English` (not confident, declaration stands);
  - declared `it`, Italian text → verdict with `null`; declared `it`, French text → `French`;
  - nothing declared, Spanish text → `Spanish`; nothing declared, two entries → null verdict (no verdict); nothing declared, half English half German text → null verdict.
- [ ] **Step 2:** Run. Expected: FAIL (class missing).
- [ ] **Step 3:** Implement; the detector (`new LanguageDetection\Language(['de','en','fr','es'])`) is built once in the constructor as a promoted readonly property built by a Symfony factory, or wrapped in a thin injected `LanguageDetector` service — whichever keeps `NoCollaboratorDefaultRule` and "no `new` on a collaborator in a method" satisfied.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5: Calibrate.** A scratchpad script (not committed) loads every dev feed's last 20 entries from the dev MySQL (`DATABASE_URL='mysql://feedreader:feedreader@127.0.0.1:33306/feedreader?serverVersion=8.4&charset=utf8mb4' APP_ENV=dev`) and prints, per feed, title, declared language if known, best two scores. Set `MINIMUM_SCORE`/`MINIMUM_LEAD` so every eye-checked feed is right and mixed ones stay undecided. Record the coverage (feeds decided / total) in the ledger for the PR.
- [ ] **Step 6:** Commit `feat(#2227): resolve a feed's language from its declaration and its entries`.

### Task 4: Ingest records the language

**Files:**
- Modify: `backend/src/Service/Ingestion/Ingest/EntryIngestor.php` (`updateFeedMetadata`), `backend/tests/Support/EntryIngestors.php`
- Test: `backend/tests/Service/Ingestion/Ingest/EntryIngestorTest.php`

**Interfaces:**
- Consumes: `FeedLanguageResolver::verdictFor`, `Feed::recordLanguage`, `SourceFormat::SOCIAL`.

- [ ] **Step 1: Failing tests:** a German RSS page sets `de`; a following one-entry page keeps `de`; a page declaring `it` with Italian text clears it to null; a feed with `sourceFormat` `SourceFormat::MASTODON_API` and German text stays null; a Mastodon-profile RSS feed (`network` mastodon, format xml) with German text gets `de`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** In `updateFeedMetadata`: skip when `in_array($feed->getSourceFormat(), SourceFormat::SOCIAL, true)`; else record the verdict's language when the verdict is not null.
- [ ] **Step 4:** Run `tests/Service/Ingestion`. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2227): record a feed's language on every refresh`.

### Task 5: Language-aware stems

**Files:**
- Create: `backend/src/Service/Curation/Trending/HeadlineStemmer.php` (injected; holds one Snowball stemmer per `FeedLanguage`, built once)
- Modify: `Support/HeadlineStems.php`, `HeadlineEquivalents.php`, `HeadlineFiller.php`, `Pass/HeadlineWindow.php`, `TrendingGrouping.php`, `Model/WindowEntryModel.php`, `backend/src/Repository/TrendingEntryRepository.php`, `tests/Support/TrendingRuns.php`, `tests/Support/TrendingWiring.php`
- Test: `HeadlineStemmerTest.php`, `HeadlineStemsTest.php`, `HeadlineEquivalentsTest.php`, `HeadlineFillerTest.php`, `Pass/HeadlineWindowTest.php`, `tests/Repository/TrendingEntryRepositoryTest.php`

**Interfaces:**
- Produces: `HeadlineStems::words(string $text): list<string>` now casefolded **with** diacritics; `HeadlineStems::bare(string $word): string` strips them; `HeadlineStems::cut(string $word): string` (bare + six characters); `HeadlineStems::isKept(string $word): bool` public. `ofWords` is deleted if nothing uses it.
- Produces: `HeadlineStemmer::stem(string $word, ?FeedLanguage $language): string` — Snowball then `bare`; null language → `HeadlineStems::cut`.
- Produces: `HeadlineEquivalents::stemsOf(string $text, ?FeedLanguage $language): list<string>`; phrase matching compares `bare` words.
- Produces: `HeadlineFiller::isSeed(string $stem, ?FeedLanguage $language): bool`. Seeds per language are **word lists** (`SEED_WORDS[de|en|fr|es]`, folded words as a reader writes them), stemmed once at construction through `HeadlineStemmer`; the null-language set is today's `SEED_STEMS` unchanged. The per-language lists start from today's set split by language, plus the words #2225 dropped because they name something in another language (*con, sin, son, como, esa* in es; *plus, sans, contre, durant, ces* in fr; *contra* in es).
- Produces: `WindowEntryModel::$language` (`?FeedLanguage`, read by the repository joining `feed.language`); `HeadlineWindow::add(int $entryId, int $feedId, ?FeedLanguage $language, string $headline, string $body)` — or the entry model itself if that keeps parameters at three; seeds are checked with the entry's language.
- `STEMS` becomes `STEMS_BY_LANGUAGE` (per `FeedLanguage` value) plus `CUT_STEMS` (today's map, for null). Task 5 ports today's entries mechanically: each key's word run through that language's stemmer (e.g. `russland`, `russie`→`russ`, `rusia`→`rusi`; `ucrani…` → `ucran`); Task 6 curates.

- [ ] **Step 1: Failing tests:**
  - `HeadlineStemmerTest`: `de` *Bundestag*/*Bundesliga*/*Bundesregierung* → three distinct stems; `de` *Russland*/*Russlands* equal; `fr` *élections* → `elect` (spike: Snowball gives `élect`, folded after); `es` *Unión* and *union* equal; `de` *Großbritannien* = *grossbritannien*; null language *Bundesregierung* → `bundes`.
  - `HeadlineStemsTest`: `words('Äpfel élu')` → `['äpfel','élu']`; `bare('élu')` → `elu`; `cut('Bundesregierung')` → `bundes`.
  - `HeadlineEquivalentsTest`: *Europäische Union* (de), *Unión Europea* (es), *European Union* (en) → `eunion`; *EU-Kommission* still `eunion`; every #2225 behaviour test now runs with `null` and still passes, plus one per language.
  - `HeadlineFillerTest`: `isSeed(stem of 'con', Spanish)` true, `isSeed(stem of 'con', English)` false, `isSeed('con', null)` false; every null-set seed in cut form (existing invariant).
  - `HeadlineWindowTest`: a German entry *Bundesregierung beschließt Haushalt* and a German entry *Bundesliga: Bayern gewinnt* share no weight; Review Focus 5: a null-language *Macron besucht Merz* and a German *Merz empfängt Macron in Berlin* share weight.
  - `TrendingEntryRepositoryTest`: a window entry of a feed with `language = 'fr'` carries `French`.
- [ ] **Step 2:** Run those tests. Expected: FAIL.
- [ ] **Step 3:** Implement; wire `HeadlineStemmer` into `HeadlineEquivalents` and `HeadlineFiller` (both stay injected services); update `TrendingRuns`/`TrendingWiring`.
- [ ] **Step 4:** Run `tests/Service/Curation/Trending tests/Repository/TrendingEntryRepositoryTest.php`, then `composer test:parallel`. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2227): stem Trending's words with their feed's Snowball stemmer`.

### Task 6: Curate the per-language tables by measurement

**Files:**
- Modify: `HeadlineEquivalents.php`, `HeadlineFiller.php`, their tests; `src/Command/TrendingExplainCommand.php` (+ explainer/model as needed)
- Test: `HeadlineEquivalentsTest.php` invariants, `tests/Command/TrendingExplainCommandTest.php`

- [ ] **Step 1: Failing test:** `app:trending:explain` prints a line `Languages: de N, en N, fr N, es N, none N` (window entries by feed language).
- [ ] **Step 2:** Implement, run, PASS; commit `feat(#2227): show Trending's language coverage in explain`.
- [ ] **Step 3: Invariants (extend #2225's):** every canonical in every map is no seed and no key of any map; every per-language key equals `HeadlineStemmer::stem(key, language)` (keys are stems); exact words letters-only; no phrase a prefix of another. Run, PASS.
- [ ] **Step 4: Measure.** Migrate the dev DB (temporary `SocialNetwork::Lemmy` + `SourceFormat::LEMMY_API` cases on both sides until #2218 merges, backed up and restored afterwards, never committed); let the worker refresh every dev feed (or run the refresh command once); run `app:trending:explain --window=day|week` on develop and branch back to back; compare with `scratchpad/compare.py` (grouped pairs gained/lost) and `scratchpad/collisions.php` (words behind each stem, per language). Also count lost develop-grouped pairs with one member from a language-less feed.
- [ ] **Step 5: Curate** from what Step 4 shows, one commit per ruling class: demonym and capital pairs Snowball keeps apart (*ukraine/ukrainian*, *rusia/ruso*, …), #2225 exact words now redundant under Snowball (*Schweiz*, *Niederlande*, *Großbritannien*, *Austria* — keep each only if a null-language feed still needs it), seeds that show up as top shared stems. Re-measure after each; eye-check every new, grown or split group.
- [ ] **Step 6:** Record the outcome (coverage, day/week pairs, cross-boundary losses, rulings) in the spec under "Outcome"; commit `docs(#2227): record the measured outcome`.

### Task 7: Gates and PR

- [ ] `composer check`, `composer md`, `composer test:parallel`, `composer infection:diff` — all green; fix survivors with tests, not by lowering anything.
- [ ] `/simplify`, then the whole-branch review; fix Critical/Important.
- [ ] Push, open the PR (`Closes #2227`), measurement table in the body.
