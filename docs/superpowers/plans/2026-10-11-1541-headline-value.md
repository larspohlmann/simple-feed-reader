# #1541 — one headline value from parser to entity

**Goal:** "title, and is it derived" travels as one `App\Entity\Headline` value from `EntryTitle::of()` to the
`EntryHeadline` embeddable, instead of a string plus a `titleDerived` bool that is split and rejoined.

## Rulings

- **`ParsedTitleModel` becomes `App\Entity\Headline`** (`fromFeed()`, `derived()`, `untitledPost()`,
  `isUntitledPost()`), following `Discussion`/`EntryDiscussion` (architecture §8: the value an entity takes lives
  in `App\Entity`). "Untitled post" is defined once, on `Headline`; `EntryHeadline::isUntitledPost()` goes,
  `Entry::isUntitledPost()` asks the stored `Headline`.
- **`ParsedEntryModel::$title` becomes `ParsedEntryModel::$headline`** (same position); `titleDerived` and
  `parsedTitle()` go. The copy constructors pass the one value through.
- **`EntryHeadline::store(Headline)`** stores both columns and applies the 1024-character column limit;
  `read(): Headline` returns it. The factory's `TITLE_MAX` cut goes.
- **`Entry::setHeadline(Headline)`** replaces `markTitleDerived()` and the test-only `setTitle()`. The `Entry`
  constructor keeps taking a string (stored as a feed headline), avoiding churn in ~111 test files.
  `getTitle()`, `isTitleDerived()`, `isUntitledPost()` stay, so `EntryArticleReader`, `EntryJson`, backups and
  the share classifier are untouched.
- **Columns and property names unchanged** (`headline.title`, `headline.derived`): search DQL and the raw
  `title_derived` write in backup restore keep working; no migration. API responses unchanged, so no
  `ReaderCacheService` VERSION bump.
- **Scraped items** (`HtmlItemExtractor`) carry `Headline::fromFeed()`, as their `titleDerived` defaulted to false.
- **Deleted, not rewritten:** `ParsedEntryModelTest::testTheDerivedTitleFlagSurvivesEveryCopy`,
  `testParsedTitleSaysWhetherTheTitleWasDerived`, and the call-order cases in `EntryHeadlineTest`.
- **Optional step 2 (one derivation site) is out of scope:** it changes which body each rule derives from and
  needs `VideoPageEntryRule`'s exception; not a trivially small step.

## Files

- Create `backend/src/Entity/Headline.php`; delete `backend/src/Service/Parser/Model/ParsedTitleModel.php`
- Modify `EntryHeadline`, `Entry`, `ParsedEntryModel`, `EntryTitle`, the four parsers, `HtmlItemExtractor`,
  `BlueskyEntryRule`, `IngestedEntryFactory`, `EntryIngestor`/`FeedPreviewService` readers of the title
- Tests: the ~45 `new ParsedEntryModel(...)` sites, the `markTitleDerived()`/`setTitle()` sites,
  `EntryHeadlineTest`, `ParsedEntryModelTest`, a `HeadlineTest`

## Tasks

1. Add `Headline` + test; switch `EntryTitle` to it; delete `ParsedTitleModel`.
2. `ParsedEntryModel` holds a `Headline`; parsers, scraper, Bluesky rule and readers follow.
3. `EntryHeadline::store(Headline)`/`read()`, `Entry::setHeadline()`; factory stores the parsed headline.
4. Migrate the tests; delete the plumbing tests.
5. Gates: cs, stan, tramp, md, tests (SQLite + MySQL), infection:diff.
