# Trending link cards (#2229) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A social post's link card joins Trending's grouping by its title and description, in its own language; a
story is shown when two article feeds or two distinct article sites carry it; posts sharing a story's article are its
sources, and lead it when the reader follows no outlet that carries it.

**Architecture:**
- `TrendingEntryRepository` reads the first top-level card's title, site name and description, and an article entry's
  URL, into `Model/EntryLinksModel` and `Model/WindowLinkCardModel` (with the D2 story-card rule).
- `CardLanguage` guesses a long card's language with a four-language `LanguageGuess`; `TrendingGrouping` builds a
  `Model/SharedArticleModel`; `HeadlineWindow::add` stems it into the entry's headline and body stems.
- `trending_story_entry.article_site` is written at build; `TrendingStoryRepository` counts it in the threshold and
  returns posts with it as shown rows; `TrendingStoryCardModel` leads with an article row, else a post.
- Frontend: `EntryDuplicatesComponent` names a Trending story's sources by poster. Nothing else changes.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine DBAL, `patrickschur/language-detection` v5, PHPUnit 12, Infection;
Angular 20 (standalone, signals), Jest, Transloco.

**Spec:** `docs/superpowers/specs/2026-11-15-2229-trending-link-cards-design.md`. It is the binding authority. Read its
Design and Decisions D1–D10 before Task 1.

## Global Constraints

- **No word, site, pattern or value comes from the current news window.** The D2 rule is structural (no path, an
  account handle, the site's own name). Tests use invented fixtures that exercise the shapes, never this week's titles.
- Unchanged: the weights (`HEADLINE_WEIGHT`, `BODY_WEIGHT`), filler shares, `HeadlineLinks` thresholds, the stemmer,
  the German splitter, `HeadlineEquivalents`, `StoryGroups`, `SharedUrlLinks` (it keeps hashing the first card's URL of
  any share kind), `noEarlierCopy()`, `storyIdContaining`, `hideStory`, `viewedStoryIds`, both endpoints' JSON shape.
- Out of scope (spec, Optional scope): storing the card language, resolving shorteners, a card-title headline variant,
  same-site dedup of article feeds. Do not build them.
- No new UI text. If a task finds it needs one, it adds the key to `frontend/public/i18n/{de,en,fr,es}.json` with real
  translations (French *vous*, Spanish *tú*, glossary in `docs/superpowers/plans/2026-11-13-2205-french-spanish-translations.md`)
  and records why in the spec.
- House rules (CLAUDE.md): `final readonly`, one role per folder (`Model/`, `Support/`, root services); at most three
  parameters, no boolean flags; queries only in `src/Repository`; no comment unless a reader would get the code wrong;
  every touched `src` file PHPMD-clean (`rm -rf ~/.pdepend` before `composer md`), no suppressions, no count-driven
  splits; no PHPStan ignores or docblocks to silence a finding, no edit to `tests/PhpStan`; tests mirror `src/`,
  invocation matchers on `$this`; HTTP statuses as `Response::HTTP_*`.
- Commit format `type(#2229): lower-case imperative summary`, no Claude attribution. Main checkout, branch
  `feature/2229-trending-link-cards`. Never push.
- Commands run in the foreground, each under 3 minutes where it can be split (the week explain run is about 85 s). No
  background jobs, no polling, no subagents. Dev MySQL is read-only for measurement: never run tests or
  `tests/bootstrap.php` against it.

## Review Focus

1. **The story-card rule (D2)** is exactly three structural tests and is the one predicate behind card text, the
   article site and shown posts. A card that fails it adds no stem, no site and no shown row.
2. **Stems in the card's language.** The article's title and body are stemmed with `SharedArticleModel::$language`,
   the post's own text with the post's. A stem held by a word in either place counts as held by a word.
3. **The threshold OR (D4).** Every story shown on develop is still shown (the first branch is today's rule
   unchanged); the second counts `COUNT(DISTINCT article_site)`, nulls not counted.
4. **The lead (D6).** An article row leads whenever the story has one the reader follows; a post leads only otherwise.
5. **`EntryLinksModel` (D10)** is a real home (`storyCard()`, `articleSite()`), not a parameter-count dodge.
6. **The migration** runs on MySQL and SQLite from empty (CI leg) and the schema validates.

---

### Task 1: Window links and the story-card rule

**Files:**
- Create: `backend/src/Service/Curation/Trending/Support/ArticleSite.php`,
  `backend/src/Service/Curation/Trending/Model/WindowLinkCardModel.php`,
  `backend/src/Service/Curation/Trending/Model/EntryLinksModel.php`
- Create tests: `backend/tests/Service/Curation/Trending/Support/ArticleSiteTest.php`,
  `backend/tests/Service/Curation/Trending/Model/WindowLinkCardModelTest.php`,
  `backend/tests/Service/Curation/Trending/Model/EntryLinksModelTest.php`

**Interfaces:**
- `ArticleSite::of(?string $url): ?string` — static, private constructor. `parse_url` host, `mb_strtolower`, a leading
  `www.` dropped; null for null, an unparsable URL or no host.
- `WindowLinkCardModel(string $url, string $title, ?string $description, string $siteName)`:
  - `namesAStory(): bool` — false when the path (`parse_url(PHP_URL_PATH)`, trimmed of `/`) is empty; when the title
    matches `/@[\p{L}\p{N}_]/u`; or when the title reduced to `[\p{L}\p{N}]` lower-case equals, reduced the same way,
    the site name, the site (`ArticleSite::of`) or the site's first label. Otherwise true. An empty reduced title
    never equals anything.
  - `site(): ?string` — `ArticleSite::of($this->url)`.
- `EntryLinksModel(?string $urlHash, ?string $articleUrl, ?WindowLinkCardModel $card)`:
  - `storyCard(): ?WindowLinkCardModel` — the card when it names a story, else null.
  - `articleSite(): ?string` — `ArticleSite::of($articleUrl)` when there is an article URL, else `storyCard()?->site()`.

- [ ] **Step 1:** Write the tests first, data providers with invented URLs and titles for every branch: a root URL with
  and without a trailing slash and with a query; a one-segment path (`https://short.example/AbC12`) that names a story;
  a handle in `Name (@name) on Network` form and in `@name@instance.example` form; a title equal to the site name
  (`Example News` / `example news`), to the host (`example.org`), to the first label (`- Example` on
  `www.example.com`), and a title that merely *contains* the label (`Example launches a rocket`, names a story);
  `articleSite()` preferring the article URL over the card; a post with a card that names no story has no site.
  Run `php bin/phpunit tests/Service/Curation/Trending/Support tests/Service/Curation/Trending/Model` and see them fail.
- [ ] **Step 2:** Implement the three classes. Run the tests green.
- [ ] **Step 3:** `composer cs`, `composer stan` (warm cache first), `rm -rf ~/.pdepend && composer md`.
- [ ] **Step 4:** Commit `feat(#2229): add the story-card rule and the article site of a window entry`.

### Task 2: Read the card and the article URL into the window

**Files:**
- Modify: `backend/src/Repository/TrendingEntryRepository.php`,
  `backend/src/Service/Curation/Trending/Model/WindowEntryModel.php`,
  `backend/src/Service/Curation/Trending/TrendingGrouping.php` (`urlHashesOf`), every `new WindowEntryModel(` in
  `backend/tests`
- Test: `backend/tests/Repository/TrendingEntryRepositoryTest.php`, `backend/tests/Service/Curation/Trending/TrendingGroupingTest.php`
  (create; the grouping is covered today through `TrendingExplainerTest` and `TrendingRebuildTest`)

**Interfaces:**
- `WindowEntryModel(int $id, int $feedId, ?FeedLanguage $language, string $title, bool $titleDerived, ?string $summary,
  \DateTimeImmutable $effectiveDate, EntryLinksModel $links)`.
- The chunk query joins the first top-level card once (`LEFT JOIN link_card card ON card.entry_id = e.id AND
  card.thread_post_id IS NULL AND card.position = (SELECT MIN(first.position) FROM link_card first WHERE
  first.entry_id = e.id AND first.thread_post_id IS NULL)`) instead of today's scalar subquery, selecting `card.url`,
  `card.title`, `card.site_name`, `SUBSTR(card.description, 1, 2000)`, and `CASE WHEN f.network IS NULL THEN e.url END
  AS article_url`. Both SQLite and MySQL.
- `urlHashesOf` reads `$entry->links->urlHash` and `$entry->links->card?->url`: unchanged behaviour.

- [ ] **Step 1:** Test first: a repository test (SQLite, fixtures through the entity manager) with an article entry,
  a social post with two top-level cards and one thread card, and a post without a card; assert the model's links:
  article URL only for the article entry, the lowest-position top-level card, description cut to 2,000 characters.
  Run it red.
- [ ] **Step 2:** Implement; update every test that builds a `WindowEntryModel`. Run
  `php bin/phpunit tests/Repository tests/Service/Curation/Trending` green; `TrendingGroupingTest`'s URL links must not
  change.
- [ ] **Step 3:** Gates as in Task 1. Commit `refactor(#2229): read a window entry's links and its first card's text`.

### Task 3: The card's language

**Files:**
- Create: `backend/src/Service/Curation/Trending/CardLanguage.php`,
  `backend/tests/Service/Curation/Trending/CardLanguageTest.php`
- Modify: `backend/src/Service/Ingestion/Language/Factory/LanguageDetectionFactory.php`, `backend/config/services.yaml`,
  `backend/tests/Service/Ingestion/Language/Factory/LanguageDetectionFactoryTest.php` (create if absent)

**Interfaces:**
- `LanguageDetectionFactory::createForFeedLanguages(): Language` — `new Language(array_map(fn (FeedLanguage $language)
  => $language->value, FeedLanguage::cases()))`.
- `services.yaml`: `app.trending.card_language_detection` (class `LanguageDetection\Language`, that factory method) and
  `app.trending.card_language_guess` (class `App\Service\Ingestion\Language\LanguageGuess`, argument the detection).
- `CardLanguage(#[Autowire(service: 'app.trending.card_language_guess')] LanguageGuess $languageGuess)`,
  `of(string $text, ?FeedLanguage $postLanguage): ?FeedLanguage` — `LONG_TEXT_LENGTH = 150`; returns `$postLanguage`
  when it is not null and `mb_strlen($text) < 150`; otherwise `bestFor($text) ?? $postLanguage`.

- [ ] **Step 1:** Tests first. `CardLanguageTest` with a real four-language `LanguageGuess` (the factory method): a
  long English text under a German post reads `en`; a long French text under an English post reads `fr`; a long
  Spanish text under a German post reads `es`; a short English title under a German post stays `de`; a short French
  title under a post with no language reads `fr`; a text with no letters under no language stays null. The factory test
  asserts the detector names only `FeedLanguage` values (detect a long Italian text: the top key is one of the four).
  Write the texts yourself, in each language, on no current story. Red.
- [ ] **Step 2:** Implement and wire. `bin/console debug:container app.trending.card_language_guess` shows it; the
  default `LanguageGuess` still gets every profile (`SocialEntryLanguage` unchanged). Green.
- [ ] **Step 3:** Gates (also `composer tramp`). Commit `feat(#2229): guess a long link card's language among the four`.

### Task 4: Card text in the headline index

**Files:**
- Create: `backend/src/Service/Curation/Trending/Model/SharedArticleModel.php`
- Modify: `backend/src/Service/Curation/Trending/Pass/HeadlineWindow.php`,
  `backend/src/Service/Curation/Trending/Pass/TrendingRun.php`, `backend/src/Service/Curation/Trending/TrendingGrouping.php`
- Test: `backend/tests/Service/Curation/Trending/Pass/HeadlineWindowTest.php`, `TrendingRunTest.php`,
  `backend/tests/Service/Curation/Trending/TrendingGroupingTest.php` (from Task 2)

**Interfaces:**
- `SharedArticleModel(string $title, string $body, ?FeedLanguage $language)`.
- `TrendingGrouping` gains `CardLanguage $cardLanguage`; private `sharedArticleOf(WindowEntryModel $entry):
  ?SharedArticleModel` — null without `$entry->links->storyCard()`; else body `EntryText::body($card->description)`,
  language `$this->cardLanguage->of($card->title . "\n" . $body, $entry->language)`.
- `TrendingRun::add(WindowEntryModel $entry, array $urlHashes, ?SharedArticleModel $article): void` also records
  `$entry->links->articleSite()`; `articleSiteOf(int $entryId): ?string`.
- `HeadlineWindow::add(WindowEntryModel $entry, string $body, ?SharedArticleModel $article): void` — headline stems
  are the union of `stemIdsOf($entry->title, $entry->language)` and `stemIdsOf($article->title, $article->language)`;
  body stems the union of the body's and `stemIdsOf($article->body, $article->language)`; a stem's "held by a word"
  is true when either holds it as a word. Everything after the union is unchanged.

- [ ] **Step 1:** Tests first:
  - `HeadlineWindowTest`: a German post whose card carries an English headline shares weight with an English article
    of the same headline, and does not without the card; a card stem counts as headline (shares a headline, passes
    `sharedWeightsWithEarlier`'s headline gate); a card description stem counts as body; a card title equal to the post
    title adds no weight twice (`totalWeight` equal with and without the card). One case each in French and Spanish
    (a French post with a Spanish card, a Spanish post with a French card) on invented headlines.
  - `TrendingGroupingTest`: a post whose card names no story (root URL) adds no stem and no site; a post with a story
    card gets the card's site as `articleSiteOf`; an article entry gets its own site.
  Red.
- [ ] **Step 2:** Implement. Green: `php bin/phpunit tests/Service/Curation/Trending`.
- [ ] **Step 3:** Gates (`composer check`, `composer md`). PHPMD on `HeadlineWindow` must stay clean: extract the
  union into a private static helper rather than lengthening `add()`. Commit
  `feat(#2229): stem a story card's title and description into the post's headline and body`.

### Task 5: The article site in the snapshot

**Files:**
- Create: `backend/migrations/Version20261115120000.php`
- Modify: `backend/src/Entity/TrendingStoryEntry.php`, `backend/src/Service/Curation/Trending/Model/StoryMemberModel.php`,
  `backend/src/Service/Curation/Trending/TrendingGrouping.php` (`storyOf`),
  `backend/src/Repository/TrendingSnapshotRepository.php` (`insertStory`), every `new StoryMemberModel(` in `backend/tests`
- Test: `backend/tests/Repository/TrendingSnapshotRepositoryTest.php`, and the builder test under
  `backend/tests/Service/Curation/Trending/SnapshotBuilder/`

**Interfaces:**
- Column `trending_story_entry.article_site VARCHAR(255) DEFAULT NULL`; the migration follows `Version20261114180000`
  (skip if present, MySQL and SQLite only, not transactional, a `down`). The entity maps it as
  `#[ORM\Column(name: 'article_site', length: 255, nullable: true)] public readonly ?string $articleSite`, set from a
  constructor argument the builder supplies; `TrendingStoryEntry` stays built only by tests (the insert is DBAL).
- `StoryMemberModel(int $entryId, int $feedId, ?string $articleSite)`.
- A site longer than 255 characters is cut with `mb_substr` before the insert (hosts are at most 253).

- [ ] **Step 1:** Tests first: a snapshot written for a story with an article entry, a carded post and a post without
  a story card stores the two sites and a null. Red.
- [ ] **Step 2:** Implement. Run the migration from empty on SQLite
  (`DATABASE_URL=sqlite:///%kernel.project_dir%/var/migrate-check.db bin/console doctrine:migrations:migrate -n` then
  `doctrine:schema:validate`, then delete that file) and on the Docker MySQL test database
  (`docker compose exec -T php bin/console doctrine:migrations:migrate -n --env=test`), then
  `doctrine:schema:validate` there. Do not migrate the dev database here (Task 9 does).
- [ ] **Step 3:** Gates. Commit `feat(#2229): store each story member's article site`.

### Task 6: Who is shown, and what a story shows

**Files:**
- Modify: `backend/src/Repository/TrendingStoryRepository.php`, `backend/src/Service/Curation/Trending/TrendingStories.php`,
  `backend/src/Service/Curation/Trending/Model/TrendingStoryCardModel.php`
- Test: the tests that pin today's threshold and rows (`backend/tests/Service/Curation/Trending/TrendingStoriesTest.php`,
  `TrendingInclusionTest.php`, `Pass/StoryCardSourceTest.php`; `grep -rn minimumArticle backend/tests` finds the rest),
  `backend/tests/Service/Curation/Trending/Model/TrendingStoryCardModelTest.php` (create),
  `backend/tests/Controller/Api/TrendingControllerTest.php`, `TrendingHiddenControllerTest.php`,
  `SubscriptionTrendingCountsTest.php`

**Interfaces:**
- `MINIMUM_ARTICLE_FEEDS` → `MINIMUM_ARTICLE_SOURCES`, parameter `minimumArticleSources`; `VISIBLE_COVERAGE` as in the
  spec's "Who is shown".
- `articleEntryIdsByStory` → `shownEntryIdsByStory(int $userId, array $storyIds): array<int, list<int>>`: members of
  included followed feeds where `member_feed.network IS NULL OR shown.article_site IS NOT NULL`.
- `TrendingStoryCardModel::fromRows` leads from the rows whose feed has no network; when there are none, from the posts,
  ordered newest first with link shares (`ShareKind::Link`) whose first `enrichment->linkCards` entry has an image
  first, then other link shares, then the rest. The other rows stay sources, one per feed, as today.

- [ ] **Step 1:** Tests first:
  - Repository: a story carried by one article feed and a post whose site differs is shown; one article feed and a post
    on the same site is not; two article feeds of one site are (today's branch); a post-only story with two sites is;
    a story whose posts have null sites and one article feed is not. `shownEntryIdsByStory` returns article entries and
    carded posts, not posts with a null site.
  - Card model: an article row leads over a newer post with an image; with no article row, the newest link share with a
    card image leads over a newer link share without one and over a newer quote post.
  - Functional (`TrendingControllerTest`, through the kernel and the real snapshot tables): a card-led story — two posts
    from two followed social feeds sharing articles on two sites, no article feed — appears in `GET /api/trending` with
    a social lead whose `linkCards[0]` is the card and the other post in `duplicates`; `POST /api/trending/hidden` with
    the lead's id returns `204` (`Response::HTTP_NO_CONTENT`) and hides both posts (the story is gone from the next
    `GET`); the unopened count (`TrendingUnopenedJson` endpoint) counts the story until the lead is marked viewed. An
    article-led story with a carded post lists the post in `duplicates` and keeps the article as lead.
  Red.
- [ ] **Step 2:** Implement. Green: `php bin/phpunit tests/Repository tests/Service/Curation/Trending tests/Controller/Api`.
- [ ] **Step 3:** Gates. Commit `feat(#2229): show stories two article sites carry, with the posts that share them`.

### Task 7: Frontend: name a story's posts by their poster

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/entry-duplicates.component.ts`
- Test: `frontend/src/app/reader/list/magazine/entry-duplicates.component.spec.ts`,
  `frontend/src/app/reader/list/magazine/blocks/entry-link/entry-link.component.spec.ts` (create if absent),
  `frontend/src/app/core/i18n/i18n-dictionaries.spec.ts`

**Interfaces:**
- `EntryDuplicatesComponent.named`: `feed` is `sourceNameOf(copy, this.rows)` when `this.entry().story` is set, else
  `this.rows.find(copy.subscriptionId)?.title ?? copy.source` as today.

- [ ] **Step 1:** Read `docs/design-language.md` (`<app-more-sources>`, `<app-entry-duplicates>`). Tests first:
  - `entry-duplicates`: on a Trending lead (`story` set), a social copy with a `poster` reads as the poster's name; the
    same copy on a non-Trending entry reads as its feed title.
  - `entry-link`: a Trending lead that is a social link share renders its post text, `app-link-card` with the card's
    title and site, the sources line ("4 sources", via `app-story-source-count`) and `app-more-sources` naming the
    other posts by poster.
  - `i18n-dictionaries`: a case that the keys this surface uses — `reader.trendingSources`, `reader.alsoCoveredBy`,
    `reader.moreSources`, `reader.moreSourcesOne`, `reader.hideStory` — exist and are non-empty in de, en, fr and es.
  Run `docker compose exec -T frontend npx jest <files>`; red where behaviour changes.
- [ ] **Step 2:** Implement. Green.
- [ ] **Step 3:** `docker compose exec -T frontend npm run check`. Commit
  `feat(#2229): name a trending story's posts by their poster`.

### Task 8: Measurement

**Files:** none in the repository; scratch under
`/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/5bb3d5ca-28c2-427b-85d0-779b6950f4b5/scratchpad/2229/final/`.
Modify: the spec's *Outcome* section.

- [ ] **Step 1:** Pin one "now" (a time in the past hours of the run day). For A, swap in from `git archive f5e4b51af`
  the spec's file set; `bin/console cache:clear` and `cache:warmup`; run day and week with
  `/usr/bin/time -l php simp-explain-at.php <backend> <window> "<now>"` (DATABASE_URL and APP_ENV=dev exported);
  restore B's files from saved copies (not `git checkout`), clear and warm, run B back to back. Equal entry counts in
  the headers, or run A again.
- [ ] **Step 2:** `compare.py` and the session's `2229/langpairs.py` (lines from a read-only dump of the window): groups,
  grouped pairs new / lost / net for all and per de–de, en–en, fr–fr, es–es, mixed; explain time, peak PHP, peak RSS.
- [ ] **Step 3:** French-only and Spanish-only day and week: a throwaway copy of `TrendingEntryRepository` with
  `AND COALESCE(e.language, f.language) = '<fr|es>'`, for A and B, restored after.
- [ ] **Step 4:** Card facts per language from read-only SQL (R1's table, refreshed), and the `CardLanguage` verdicts on
  the window's cards grouped as post → card language, with every fr/es case (fr/es post → other card, de/en post →
  fr/es card) checked by eye.
- [ ] **Step 5:** Visibility: a throwaway script groups the pinned window with B's `TrendingGrouping`, and for the dev
  account (user 2) applies A's rule and B's (followed feeds, D4's OR, top 15 by outlets then newest); list every story
  shown only under B — card-led or not, French and Spanish named — and check each by eye.
- [ ] **Step 6:** Eye check: the largest new, grown and split groups; every group holding a card with a generic short
  title (two or more cards with one title of three words or fewer); fr and es groups that change. A wrong merge or a
  week loss is reported, not tuned.
- [ ] **Step 7:** Write the *Outcome*: tables as in the spec's R3/R4, the eye check, and findings. Commit
  `docs(#2229): record the measured outcome`.

### Task 9: Live check on :4200

**Files:** none; the Outcome gains a short "Live check" paragraph.

- [ ] **Step 1:** Apply the migration to the dev database
  (`docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`), restart `worker` so it runs the
  new code, and let the day snapshot rebuild on the worker's next tick (every 10 minutes; there is no rebuild command).
  Check `trending_snapshot.built_at` once after that, not in a loop.
  Restart nginx if `/api` answers 500 after a php restart.
- [ ] **Step 2:** In the built-in browser at http://localhost:4200, signed in as the dev account, open Trending (day,
  then week). Find a card-led story (`GET /api/trending` in the network panel: a story whose lead has `social` set).
  If none is shown, say so in the Outcome and check a social source in an article-led story instead.
- [ ] **Step 3:** Screenshot it in the magazine layout at desktop and at phone width (375 px) and in the list layout:
  the post, the card (title, site, image), "N sources", the sources named by poster.
- [ ] **Step 4:** Open the story's lead: the post opens; back in Trending the unread dot is gone and the sidebar's
  Trending badge dropped by one. Open the card: the article opens in the link reader.
- [ ] **Step 5:** Hide the story (Hide in the action row): it leaves the list, does not come back on reload, and the
  badge stays right. Leave the account as found: un-hiding is not offered by the UI, so pick a story the measurement
  does not depend on, and note its id in the Outcome.
- [ ] **Step 6:** Switch the UI language to French and Spanish once each and confirm the sources line and "Also covered
  by" render translated (no raw key). Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) with `jq` for
  errors and deprecations. Commit the Outcome paragraph `docs(#2229): record the live check`.

### Task 10: Gates

- [ ] **Step 1:** Backend, from `backend/`: `composer check`, `rm -rf ~/.pdepend && composer md`,
  `composer test:parallel` (SQLite), and from the root `docker compose exec -T php composer test:parallel` (MySQL);
  the two legs may run at the same time. `composer infection:diff` meets `minMsi`; kill escaped mutants with tests,
  never by lowering it.
- [ ] **Step 2:** PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file: no ERROR or WARNING.
- [ ] **Step 3:** Frontend, from the root: `docker compose exec -T frontend npm run check`.
- [ ] **Step 4:** Run `/simplify` over the branch diff and apply what it finds; rerun the affected gates.
- [ ] **Step 5:** Report every gate's result. Commit any fixes as `fix(#2229): …` or `refactor(#2229): …`.
