# Lemmy Search Syntax (#2277) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Lemmy search subscription understands the query syntax the other networks have: `||`, `"a phrase"`, `-word`, `-from:name` and `( … )`.

**Architecture:** Lemmy's search knows none of these operators, so the reader does the work. A query is spelled out as its `||` alternatives (the existing `SearchQueryAlternatives`, cap 8); Lemmy is asked once per alternative for that alternative's longest term; the reader's own matcher (`SocialSearchMatch`) then judges every post, live and stored. The Mastodon-named query helper becomes network-dispatched, and `SocialNetwork::checksSearchResults()` disappears because every network is now checked.

**Tech Stack:** PHP 8.5 / Symfony 8.1 (`backend/`), Angular 20 + Transloco (`frontend/`).

**Spec:** GitHub issue #2277 and its comment of 2026-11-16 (measurements, phrase scope). This plan's "Measurements" and "Rulings" sections are the design.

## Measurements (lemmy.world, 2026-11-16, `GET /api/v3/search?type_=Posts&sort=New&listing_type=All&limit=50`)

| Query | Result | Reading |
|---|---|---|
| `rust` | 50, first hit "…publicly **trust**ed…" | substring match, not word match |
| `rust compiler` vs `compiler rust` | 50 each, different posts | words match as substrings **in the written order** |
| `"rust compiler"` | 0 | quotes are literal |
| `rust \|\| zig` | 0 | `\|\|` is literal |
| `rust OR zig` | 50, first hit "ZigZag…" | `OR` is just another ordered substring |
| `rust -compiler` | 6 | `-` is literal |

## Rulings

- **Syntax:** Lemmy takes the same syntax as Mastodon: all words, phrase, `||`, `-word`, `-from:name`, groups. *If wrong:* the frontend rule list is one array.
- **What Lemmy is asked:** per alternative, its one longest positive term, sent as the matcher reads it: the factor's words, lower-cased and joined by a space (`rss-reader` asks for `rss reader`; a factor without a word is no term; first on a tie). Besides negated terms, only `from:` words are left out; every other `name:value` word is a term like any other. Sending every word would make Lemmy require them in the written order and lose posts; one term only ever finds more, and the reader's check narrows it. *If wrong:* `LemmySearchTerms::of()` is the one place.
- **An alternative without a positive term** (`-gazette`, `from:ada`) is refused as too broad with the existing `social_search_too_broad` problem. Lemmy cannot be asked for "everything".
- **Matching:** the reader's matcher judges title and body (word start, last word may run on, phrase never spans the two). `rust` no longer finds "trusted". This applies to existing Lemmy search subscriptions from their next refresh.
- **Authors:** a Lemmy author goes by `name@host` and bare `name`, read from the profile URL `https://host/u/name`.
- **Cap:** 8 alternatives, shared with Mastodon (`SearchQueryAlternatives::MAX_ALTERNATIVES`).
- **No migration:** stored queries are plain strings and are re-read under the new syntax.

## Global Constraints

- `backend/CLAUDE.md` code style is mandatory: no abbreviations, `final`, guard clauses, no comment unless a reader would get the code wrong without it.
- No edits under `backend/tests/PhpStan/`, no `@phpstan-ignore`, no PHPMD suppression, no new class created to dodge a PHPMD count.
- HTTP statuses are `Response::HTTP_*` constants in `src` and tests.
- Copy changes land in `frontend/public/i18n/en.json`, `de.json`, `fr.json` and `es.json` together (German informal, French `vous`, Spanish `tú`).
- Commit format `type(#2277): subject`, no attribution lines.
- This worktree cannot run the Docker stack: backend runs `php bin/phpunit` natively (SQLite); the MySQL leg, Jest and the live check happen from the main checkout (Task 6).

## Review Focus

- A query whose alternatives share their longest term (`rss (reader || feed)`): Lemmy is asked once, not twice.
- The same post found by two alternatives: it appears once in the page.
- A stored Lemmy post whose match is only in its title survives a requery to a query that still matches it.
- A pure-negative Lemmy query is refused at subscribe, at requery and at preview with `social_search_too_broad`, never sent as `q=`.
- A phrase split across title and body does not match.

---

### Task 1: Network-dispatched search queries

**Files:**
- Modify: `backend/src/Service/Social/Social/Pass/SearchQueryAlternatives.php`
- Create: `backend/src/Service/Social/Social/Support/LemmySearchTerms.php`
- Rename: `backend/src/Service/Social/Social/Support/MastodonSearchQueries.php` → `NetworkSearchQueries.php` (`git mv`), and its test `backend/tests/Service/Social/Social/Support/MastodonSearchQueriesTest.php` → `NetworkSearchQueriesTest.php`
- Modify callers: `Support/PrivateFeedRules.php`, `SearchFeedEditor.php`, `TimelineSource/MastodonTimelineSource.php`, `SocialSearch/NetworkSocialSearch.php`, `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`
- Test: `backend/tests/Service/Social/Social/Support/LemmySearchTermsTest.php`, `NetworkSearchQueriesTest.php`, `PrivateFeedRulesTest.php`, `backend/tests/Service/Social/Social/Pass/SearchQueryAlternativesTest.php` (existing, wherever it lives)

**Interfaces:**
- Produces:
  - `SearchQueryAlternatives::factors(): list<list<string>>` (public): the alternatives as their factor lists, each factor a word, a `"quoted phrase"` or a `-negated` one of either; `queries()` is built on it and keeps its behaviour.
  - `LemmySearchTerms::of(list<list<string>> $alternatives): non-empty-list<string>`, `@throws SearchQueryTooBroadException`.
  - `NetworkSearchQueries::of(SocialNetwork $network, string $query): non-empty-list<string>`, `requireFits(SocialNetwork, string): void`, `fits(SocialNetwork, string): bool`.

- [ ] **Step 1: Failing tests for `LemmySearchTerms`** (input is `new SearchQueryAlternatives(SearchQueryTokens::of($query))->factors()`):

```php
#[DataProvider('queries')]
public function testEachAlternativeAsksItsLongestTerm(string $query, array $terms): void
// 'rss'                                  => ['rss']
// 'rss reader'                           => ['reader']
// '"rss reader" atom'                    => ['rss reader']
// 'rss || atom'                          => ['rss', 'atom']
// 'rss (reader || feed)'                 => ['reader', 'feed']
// 'feed (rss || atom)'                   => ['feed']            // shared longest term asked once
// 'Reader || reader'                     => ['reader']          // case-insensitive, lower-cased
// 'reader -gazette'                      => ['reader']
// 'reader -"daily gazette"'              => ['reader']
// 'reader from:ada lang:de'              => ['reader']
// 'atom feed'                            => ['atom']            // first on a tie

#[DataProvider('termless')]
public function testAnAlternativeWithoutATermIsTooBroad(string $query): void
// '-gazette', 'from:ada', 'rss || -gazette'
// expects SearchQueryTooBroadException, message 'Lemmy needs a word to search for in every alternative.'
```

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Social/Social/Support/LemmySearchTermsTest.php` — FAIL, class missing.
- [ ] **Step 3: Implement.** `factors()` exposes what the private `alternatives()` returns (rename the private one if the two names collide in reading). `LemmySearchTerms` is a static `Support/` helper: per alternative drop factors starting with `-`, drop words matching `/^\p{L}+:./u`, trim the surrounding `"`, lower-case, take the longest by `mb_strlen`; then `array_values(array_unique(...))`.
- [ ] **Step 4: Failing tests for `NetworkSearchQueries`:** the four renamed Mastodon tests now pass `SocialNetwork::Mastodon`; add

```php
public function testBlueskyRunsTheQueryAsWritten(): void      // of(Bluesky, 'a || b') === ['a || b']
public function testLemmyAsksOneTermPerAlternative(): void    // of(Lemmy, '"rss reader" || atom') === ['rss reader', 'atom']
public function testALemmyQueryWithoutATermDoesNotFit(): void // fits(Lemmy, '-gazette') === false
public function testNineLemmyAlternativesDoNotFit(): void     // fits(Lemmy, 'a || b || c || d || e || f || g || h || i') === false
```

- [ ] **Step 5: Implement** `NetworkSearchQueries::of()` as a `match` over `SocialNetwork` (Bluesky `[$query]`, Mastodon `->queries()`, Lemmy `LemmySearchTerms::of(->factors())`). Update callers: `MastodonTimelineSource` and `NetworkSocialSearch` pass `SocialNetwork::Mastodon`; `SearchFeedEditor::refuseTooBroadFor` drops its Mastodon condition and calls `requireFits($network, …)`; `PrivateFeedRules` adds `&& NetworkSearchQueries::fits(SocialNetwork::Lemmy, …)` to the Lemmy search arm.
- [ ] **Step 6: Network-neutral wording.** `SearchQueryAlternatives::capped()` message becomes `'Each alternative is searched on its own; this query spells out more than %d.'`; the `SocialProblems` title becomes `'Search too broad'`; docblocks saying "when Mastodon cannot …" say "when the network cannot …". Update the tests asserting the old strings.
- [ ] **Step 7: `PrivateFeedRulesTest`:** add `testALemmySearchWithoutATermIsNotAllowed` (`PrivateFeedModel::search('-gazette')` → false) and `testALemmySearchWithAlternativesIsAllowed` (`'rss || atom'` → true).
- [ ] **Step 8: Run** `php bin/phpunit tests/Service/Social tests/Http` — PASS. Commit `refactor(#2277): dispatch a search's network queries by network`.

### Task 2: The reader judges Lemmy posts

**Files:**
- Create: `backend/src/Service/Social/Social/Support/LemmySearchedPost.php`
- Modify: `Model/StoredPostModel.php`, `backend/src/Repository/SocialFeedRepository.php` (`unprotectedPostsAfter`), `Support/StoredSearchedPost.php`, `SearchFeedEditor.php`, `backend/src/Enum/SocialNetwork.php`
- Test: `backend/tests/Service/Social/Social/Support/LemmySearchedPostTest.php`, `StoredSearchedPostTest.php`, `backend/tests/Service/Social/Social/SearchFeedEditorTest.php`, the repository's existing test for `unprotectedPostsAfter`

**Interfaces:**
- Consumes: `NetworkSearchQueries::requireFits()` (Task 1).
- Produces:
  - `LemmySearchedPost::of(?string $title, ?string $contentHtml, ?string $authorUrl): SearchedPostModel` — texts `[$title ?? '', PlainText::fromHtmlBlocks($contentHtml) ?? '']`, author names `[name@host, name]` from `https://host/u/name` (host lower-cased; none when the URL is null or has no `/u/` path).
  - `StoredPostModel` gains `public ?string $title` as its third constructor argument (`entryId, guid, title, contentHtml, authorUrl`).
  - `SocialNetwork::checksSearchResults()` no longer exists.

- [ ] **Step 1: Failing tests for `LemmySearchedPost`**, judged through `SocialSearchMatch::matcher()`:

```php
// of('RSS readers compared', '<p>Atom too</p>', 'https://Lemmy.Example/u/ada')
testAWordInTheTitleMatches            // 'rss' true
testAWordInTheBodyMatches             // 'atom' true
testWordsMayComeFromTitleAndBody      // 'atom rss' true
testAPhraseNeverSpansTitleAndBody     // '"compared atom"' false
testASubstringInsideAWordDoesNotMatch // 'eader' false
testTheAuthorGoesByNameAndAddress     // 'rss -from:ada' false; 'rss -from:ada@lemmy.example' false; 'rss -from:bob' true
testAPostWithoutAnAuthorUrlHasNoNames // of('rss', null, null): 'rss -from:ada' true
```

- [ ] **Step 2: Run, FAIL. Step 3: Implement** `LemmySearchedPost` (static `Support/` helper).
- [ ] **Step 4: Stored posts carry their title.** `unprotectedPostsAfter` also selects the entry's title (the `EntryHeadline` embeddable's title field) into `StoredPostModel::$title`; extend its repository test to assert the title. `StoredSearchedPost::of()` puts `LemmySearchedPost::of($post->title, $post->contentHtml, $post->authorUrl)` in the Lemmy arm: restructure so the Lemmy arm returns the whole `SearchedPostModel` rather than only author names. Replace `testALemmyPostIsNeverJudgedByTheReader` with `testALemmyPostIsJudgedByTitleAndBody` (title `'rss reader'`, body `'<p>atom</p>'`: matcher `'reader atom'` true).
- [ ] **Step 5: Delete `SocialNetwork::checksSearchResults()`** and its guard in `SearchFeedEditor::nonMatchingIds()`. In `SearchFeedEditorTest` replace `testRequeryingALemmySearchKeepsItsPosts` with:

```php
public function testRequeryingALemmySearchRemovesThePostsThatNoLongerMatch(): void
// two stored posts: title 'rss reader' / body '<p>news</p>', and title 'atom feeds' / body '<p>news</p>'
// requery to 'atom': the first is removed, the second kept
public function testRequeryingALemmySearchToAQueryWithoutATermIsRefused(): void
// requery to '-gazette' throws SearchQueryTooBroadException; feed and posts unchanged
```

- [ ] **Step 6: Run** `php bin/phpunit tests/Service/Social tests/Repository` — PASS. `composer stan` — clean. Commit `feat(#2277): the reader judges a Lemmy search's stored posts`.

### Task 3: A Lemmy search feed asks each alternative

**Files:**
- Modify: `backend/src/Service/Social/Social/LemmyPostEntries.php`, `TimelineSource/LemmyTimelineSource.php`, `TimelineSource/TimelineSourceInterface.php` (docblock: "a search once per `||` alternative on a network without `||`")
- Test: `backend/tests/Service/Social/Social/LemmyPostEntriesTest.php`, `TimelineSource/LemmyTimelineSourceTest.php`

**Interfaces:**
- Consumes: `NetworkSearchQueries::of(SocialNetwork::Lemmy, …)`, `LemmySearchedPost::of()`.
- Produces: `LemmyPostEntries::searchPage(SocialAccount $account, array $answers, string $query): TimelinePageModel` (`@param list<ApiResponseModel> $answers`, one per asked term).

- [ ] **Step 1: Failing tests for `searchPage`** (build answers inline as JSON; post ids 1–4):

```php
testOnlyPostsTheQueryMatchesBecomeEntries   // query 'rust compiler'; answer holds 'Rust compiler news', 'Trusted compilers' (substring), 'Compilers written in Rust' → entries 1 and 3
testAPhraseIsCheckedByTheReader             // query '"rss reader"'; 'A reader for RSS' dropped, 'My RSS reader' kept
testAnExcludedWordDropsThePost              // query 'rss -gazette'
testAPostFoundByTwoAlternativesAppearsOnce  // two answers both holding post 2 → one entry
testThePageIsNewestFirstAcrossAnswers       // answers [1, 3] and [2, 4] → entry guids in order 4, 3, 2, 1
testTheNewestSeenIdCoversEveryAnswer        // newest id '4', also when post 4 does not match
testAnAnswerWithoutPostsIsUnreachable       // SocialNetworkUnreachableException 'The Lemmy instance answered without posts.'
```

- [ ] **Step 2: Run, FAIL. Step 3: Implement.** Collect the post views of every answer keyed by `post.id`, order by id descending, build entries with the existing `entryOf()`, and keep an entry when the matcher accepts `LemmySearchedPost::of((string) $entry->headline…, $entry->contentHtml, $entry->authorUrl)` (read the title from `Headline` the way `Entry` stores it, so live and stored judge the same text). `page()` keeps its behaviour; share the post-view reading between the two.
- [ ] **Step 4: Failing `LemmyTimelineSourceTest` changes:**

```php
testASearchFeedAsksOnceForEachAlternative   // remoteId '"rss reader" || atom' → 2 requests, q=rss%20reader and q=atom
testASearchPageKeepsOnlyWhatTheQueryMatches // pageOf with two answers → matching entries only
testATooBroadSearchFeedIsUnreachable        // remoteId '-gazette' → SocialNetworkUnreachableException (as MastodonTimelineSource does)
```
  Keep `testASearchFeedAsksTheSearch` for the one-term case; `testAPageIsReadFromItsOneAnswer` stays for timelines.
- [ ] **Step 5: Implement** in `LemmyTimelineSource`: `requests()` maps the search terms to `authorizedGet(SocialSearchPaths::lemmy($term))`; `pageOf()` calls `searchPage()` for `SocialFeedKind::Search`.
- [ ] **Step 6: Run** `php bin/phpunit tests/Service/Social` — PASS. Commit `feat(#2277): a Lemmy search feed asks each alternative and checks what it finds`.

### Task 4: The preview asks each alternative

**Files:**
- Modify: `backend/src/Service/Social/Social/SocialSearch/NetworkSocialSearch.php`
- Test: `backend/tests/Service/Social/Social/SocialSearch/NetworkSocialSearchTest.php`

**Interfaces:**
- Consumes: `NetworkSearchQueries::of()`, `LemmyPostEntries::searchPage()`, `LemmyAccountApi::authorizedGet()`, `LemmyApiClient::accepted()` / `answerOf()`.

- [ ] **Step 1: Failing tests:**

```php
testALemmySearchPreviewAsksEachAlternative     // 'rss || atom' → two requests (q=rss, q=atom), posts of both, each once
testALemmySearchPreviewChecksAPhrase           // '"rss reader"' → q=rss%20reader sent; a post reading 'reader for rss' is not previewed
testALemmySearchPreviewWithoutATermIsTooBroad  // '-gazette' → SearchQueryTooBroadException, no request sent
```
  Adjust `testALemmySearchPreviewsThePostsFound` if its fixture posts do not match `rss reader` under the reader's check (assert on what the fixture really holds; do not edit the fixture to fit).
- [ ] **Step 2: Run, FAIL. Step 3: Implement.** Turn `mastodonAnswers()` into one private method that sends a list of `ApiRequestModel` through `TimelineRounds::slots()` and `ConcurrentRequestEngine::sendAll()` and accepts each outcome with a closure the network arm passes; the Lemmy arm accepts with `LemmyApiClient::accepted(LemmyApiClient::answerOf($outcome))`, which keeps the errors `LemmyAccountApi::get()` raised before. No new constructor parameter.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Social tests/Controller` — PASS. Commit `feat(#2277): the Lemmy search preview asks each alternative`.

### Task 5: Search form copy

**Files:**
- Modify: `frontend/src/app/reader/feeds/social-picker/social-search-form.component.ts`, `.html`, `.spec.ts`; `frontend/public/i18n/{en,de,fr,es}.json`

- [ ] **Step 1: Spec first** (`social-search-form.component.spec.ts`): for `network = 'lemmy'` the syntax list shows the operators `rss reader`, `"rss reader"`, `rss || atom`, `-gazette`, `-from:handle`, `( … )`; the combined example renders; the alternatives note renders with "Lemmy"; the Mastodon full-text note does not. For `mastodon` the alternatives note renders with "Mastodon".
- [ ] **Step 2: Implement.** `SEARCH_SYNTAX.lemmy` is the Mastodon list (share one constant); `COMBINED_EXAMPLES.lemmy = COMBINED_EXAMPLE`; `EXAMPLE_QUERIES.lemmy = OPERATOR_EXAMPLE`. The template shows the alternatives note for `mastodon` and `lemmy` with the network's display name as a Transloco parameter, and a Lemmy-only note below it.
- [ ] **Step 3: Copy.** Delete `syntaxLemmyPlain` and `syntaxMastodonAlternatives`; add in all four files:

| Key | en | de | fr | es |
|---|---|---|---|---|
| `syntaxAlternativesApart` | `{{network}} has no \|\|: each alternative is searched on its own, at most 8 per query.` | `{{network}} kennt kein \|\|: jede Alternative wird einzeln gesucht, höchstens 8 pro Suche.` | `{{network}} ne connaît pas \|\| : chaque alternative est recherchée séparément, 8 au maximum par requête.` | `{{network}} no admite \|\|: cada alternativa se busca por separado, como máximo 8 por consulta.` |
| `syntaxLemmyNote` | `Matches post titles and bodies. Every alternative needs at least one word to search for.` | `Trifft Titel und Texte von Beiträgen. Jede Alternative braucht mindestens ein Wort, nach dem gesucht wird.` | `Trouve les titres et les textes des publications. Chaque alternative doit contenir au moins un mot à rechercher.` | `Coincide con títulos y textos de las publicaciones. Cada alternativa necesita al menos una palabra que buscar.` |

- [ ] **Step 4:** `npm run check` from `frontend/` in the main checkout's container is not available here; run `./node_modules/.bin/eslint`, `prettier --check` and `tsc` natively on the touched files, and leave Jest to Task 6. Commit `feat(#2277): the search form offers Lemmy the full syntax`.

### Task 6: Gates and live check

- [ ] `rm -rf ~/.pdepend`, then from `backend/`: `composer check`, `composer md`, `php bin/phpunit`, `composer infection:diff`. Fix findings by design, never by threshold.
- [ ] Scan today's `backend/var/log/test-*.log` / dev log for deprecations.
- [ ] `/simplify` over the branch.
- [ ] From the main checkout once it is free (or with Lars's go to switch it): MySQL leg (`docker compose exec php composer test:parallel`), Jest in the frontend container, and the live check on `http://localhost:4200` with the dev Lemmy account: preview `"rss reader"`, `rust || zig`, `rust -compiler`, and `-gazette` (expect the too-broad message); subscribe one and refresh.
- [ ] PR into `develop`, body `Closes #2277`, naming what was and was not verified.
