# Restore the reader lead image on the parsed document, not on re-parsed strings

Issue: [#684](https://github.com/larspohlmann/simple-feed-reader/issues/684).
Related: #681 (the feature this cleans up), #586 (the shared-document window),
#657 (the hero rule this replaced).

## Problem

`ReaderLeadImage::restore` (added in #681) takes HTML **strings** and parses them
again. This breaks the "parse once, never serialise-and-re-parse" discipline that
`FetchedPageNormalizer` and `ReaderBodyCleaner` are built around and advertise in
their docblocks.

`ArticleExtractor::extract` re-parses twice:

- **Body round-trip.** `ReaderBodyCleaner::clean` parses its shared
  `\Dom\HTMLDocument`, mutates in place, and serialises once. One statement later
  `ReaderLeadImage::restore` re-parses that exact string and re-serialises through
  `$body->innerHTML`.
- **Page round-trip.** `page->html` is already parsed by `FetchedPageNormalizer`
  (twice: `normalize` and `collapseWrapperChains`). `ReaderLeadImage::drawnOnPage`
  parses the raw page string a third time.

Because `drawnOnPage` reads the **raw** page — where lazy-load sources are still
unresolved — `ReaderLeadImage` re-derives lazy-image knowledge that
`LazyImageSources` already owns: its own `URL_ATTRIBUTES` list and srcset handling
to dig the real URL out of `data-*` attributes. That duplication exists only
because it reads the raw string instead of the normalised document, where every
lazy source is already promoted to a plain `src` and every `<picture>` is
flattened to its `<img>`.

## Goals

- `ReaderLeadImage` never parses or serialises HTML. It mutates the shared body
  document in place, like `LeadingTitleRemover` and `EdgeBoilerplateTrimmer`.
- The "which images does the page draw?" question is answered once, from the
  normalised page document, before Readability consumes it.
- `URL_ATTRIBUTES`, `renderedUrls` and the srcset digging leave `ReaderLeadImage`.
  The page scan becomes a plain `img@src` + `source@srcset` read.
- Behaviour stays identical. The #681 tests keep their assertions.

## Non-goals

- No change to the lead-image decision rule (opens-with-image, body-shows-lead,
  drawn-on-page, `ImageIdentity`). This is structure, not policy.
- No change to `LazyImageSources`, `ImageIdentity` or the normalise pass.
- No new persisted data, no migration, no API change. `restore` is internal to
  extraction.

## Architecture

New units, both in `backend/src/Service/Reader/`:

| Unit | Responsibility |
|---|---|
| `PageImageInventory` | `final readonly class`. The set of images the normalised page draws, as `ImageIdentity` fingerprints. `PageImageInventory::fromDocument(?HTMLDocument $page): self` scans `img@src` + `source@srcset` once. `draws(ImageIdentity $lead): bool` answers the drawn-on-page gate. A null document gives an empty inventory. |
| `LeadImageCandidate` | `final readonly class {?string $url, PageImageInventory $pageImages}`. Groups the two lead-restore inputs so `ReaderBodyCleaner::clean` stays at three parameters. |

Changed units:

- **`ReaderLeadImage`** — `restore(HTMLDocument $document, LeadImageCandidate $lead): void`.
  It reads `$document->body`, applies the same guards, and inserts the figure
  before `body->firstChild`. It drops `URL_ATTRIBUTES`, `drawnOnPage`,
  `renderedUrls`, `srcsetUrl` and the `HtmlDocumentParser` re-parse. The
  drawn-on-page gate becomes `$lead->pageImages->draws($leadIdentity)`. The four
  body-only helpers (`opensWithImage`, `bodyShowsLead`, `bodyHasImage`, `figure`)
  stay unchanged.
- **`ReaderBodyCleaner`** — gains a `ReaderLeadImage` constructor dependency and
  runs it as the third in-place step, after the title removal and the boilerplate
  trim, inside the one shared-document window:

  ```php
  public function clean(string $contentHtml, array $titleCandidates, LeadImageCandidate $leadImage): string
  {
      $document = HtmlDocumentParser::parseOrNull($contentHtml);
      if ($document === null) {
          return $contentHtml;
      }
      $this->titleRemover->removeFrom($document, $titleCandidates);
      $this->boilerplateTrimmer->trimIn($document);
      $this->leadImage->restore($document, $leadImage);

      return $document->saveHtml();
  }
  ```

  The restore runs last, so it sees the same post-clean body it sees today. The
  serialise moves from `body->innerHTML` (the current restore) to the existing
  `saveHtml()`. `saveHtml()` wraps the body in `<html><head></head><body>`, but
  `EntrySanitizer` strips that wrapper: `sanitize(saveHtml())` is byte-identical
  to `sanitize(innerHTML)` (verified against the injected-figure case), so the
  string the sanitiser receives is unchanged. Today the restore already returns
  `innerHTML` on the inject branch and the cleaner's `saveHtml()` on every
  no-inject branch, so both wrapper forms already pass through the sanitiser in
  production.
- **`ArticleExtractor`** — captures the inventory from the normalised document
  before Readability mutates it, and passes the candidate to the cleaner:

  ```php
  $normalized = $this->normalizer->normalize($page->html);
  $pageImages = PageImageInventory::fromDocument($normalized);
  $article = $this->richestArticle($normalized, $page);
  // ... content-length guards ...
  $leadImage = new LeadImageCandidate($article->image, $pageImages);
  $body = $this->bodyCleaner->clean($article->content, [$article->title, $entryTitle], $leadImage);
  ```

  `richestArticle` takes the pre-built conservative document so the inventory is
  captured from the exact bytes Readability then consumes:

  ```php
  private function richestArticle(?HTMLDocument $normalized, PageResponse $page): ?Article
  {
      $conservative = $this->parse($normalized, $page->finalUrl);
      $collapsed = $this->parse($this->normalizer->collapseWrapperChains($page->html), $page->finalUrl);

      return $this->richer($conservative, $collapsed);
  }
  ```

  `ArticleExtractor` drops its `ReaderLeadImage` dependency; the cleaner owns it
  now. The controller and the interface do not change.

### The care point

Readability consumes (mutates) each document it parses. The inventory therefore
has to be read from `$normalized` **before** `richestArticle` hands it to
`parse`. The order above does exactly that: `fromDocument` runs on the
still-untouched normalised document, then `richestArticle` consumes it. The
`collapseWrapperChains` document is a second, independent parse and is not read
for the inventory — the two share the same image set, because both run the same
`repair` pass.

## Behaviour equivalence

The page scan moves from the raw page HTML to the normalised page document. The
two sets are the same for identity purposes:

- A lazy `<img>` with a `data:` placeholder in `src` and the real URL in
  `data-src`: today the raw scan yields both the placeholder and the real URL
  through `URL_ATTRIBUTES`. In the normalised document `LazyImageSources` has
  promoted the real URL into `src` and dropped the placeholder, so the scan yields
  the real URL. The placeholder never matched a real lead, so the drawn-on-page
  answer is unchanged.
- A `<picture>` whose sources carry the renditions: today the raw scan yields the
  first srcset candidate; in the normalised document the picture is flattened to
  one `<img src>` and the scan yields that. `ImageIdentity` fingerprints the
  filename stem, and renditions of one photo share that stem, so both spellings
  match the same lead. A rendition whose filename stem differs is treated as a
  different photo by design (#657/#686), which is the safe direction: a miss only
  skips the restore.
- An image `LazyImageSources` removed for having no usable candidate is not drawn
  and correctly absent from the inventory.

`source@srcset` stays in the scan as a belt-and-braces read: after flattening
most pictures leave no `<source>`, but a `<source>` outside a flattened picture
is still counted, matching the current `renderedUrls` behaviour.

## Testing

Tests come first, per the repository's TDD rule.

- **`PageImageInventoryTest`** (new) — a plain `<img src>` is drawn; a
  `<source srcset>` head is drawn; a document with no images gives an empty
  inventory; `fromDocument(null)` gives an empty inventory; `draws` matches by
  `ImageIdentity` (a size/format variant of the same photo matches, an unrelated
  photo does not).
- **`ReaderLeadImageTest`** — carried to the new API. Each scenario keeps its
  assertion. The test now builds the document with `HtmlDocumentParser` and passes
  a `LeadImageCandidate` whose `PageImageInventory` is built from the
  page-showing-the-lead fixture. `testFindsALazyLoadedLeadByItsDataSource` becomes
  a proof that the inventory sees the resolved `src`: the page document is run
  through `LazyImageSources` (or built already resolved) so the inventory carries
  the promoted URL, since digging `data-src` is no longer this class's job. The
  restore asserts on the returned/serialised body as today.
- **`ReaderBodyCleanerTest`** — its constructor gains the `ReaderLeadImage`
  dependency. One new case proves the cleaner restores a lead into a text-only
  body inside the shared window, and that a null/non-http candidate leaves the
  cleaned body untouched.
- **`ArticleExtractorTest`** — the three lead-image integration tests
  (`testRestoresTheLeadIntoATextOnlyBody`, `testRestoresADistinctPageHeroAboveTheBodyPhoto`,
  `testRestoresLazyLoadedImagesInsteadOfLeavingEmptyFrames`) stay green with only
  the constructor wiring changed: `ReaderBodyCleaner` now takes `ReaderLeadImage`,
  and `ArticleExtractor` no longer does. These use the real pipeline and are the
  primary guard that behaviour is unchanged.

Gates before the pull request: `composer check`, `composer md`,
`php bin/phpunit` natively and in Docker, `composer infection:diff`, and PhpStorm
inspections on the changed PHP. No frontend change, so no `npm run check`.

## Acceptance

- `ReaderLeadImage` holds no `HtmlDocumentParser` call, no `URL_ATTRIBUTES`, and
  no srcset handling.
- The page image set is derived once, from the normalised document, before
  Readability consumes it.
- `ArticleExtractor::extract` re-parses neither the body string nor the page
  string for the lead restore.
- The three `ArticleExtractor` lead-image tests pass unchanged in intent.
- All gates pass.
