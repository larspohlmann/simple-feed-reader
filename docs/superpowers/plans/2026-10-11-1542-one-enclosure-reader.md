# #1542 Parser media: one enclosure reader and one MIME-type mapping

**Goal:** the image path recognises enclosures the way the media path does, and every "is this MIME type an
image, video or audio?" question goes through `FeedMediaClassifier::kindOfMimeType()`.

## Rulings

- **Enclosure predicate lives on `CoreElement`** (`isEnclosure()`, beside `isCore()`): `<enclosure>` or
  `<link rel="enclosure">` in the item's own namespace. `ItemMediaExtractor` and `ItemImageExtractor` both ask it.
- **`ItemImageExtractor::fromEnclosure(CoreElement)` classifies through `FeedMediaNode::kind()`**, the media path's
  verdict, and reads the URL through `FeedMediaNode::url()` (`url`, else `href`). So besides the shape widening the
  issue names, an enclosure without a `type` whose URL ends in an image extension now counts as an image too, as it
  already does in the media path. Pinned by a test.
- **`FeedItemImageSelector::fromRss2OrAtom(CoreElement, list<?string>)`** replaces `fromRss2()` and `fromAtom()`;
  RSS 2.0 passes its one body as a one-element list. `fromRss1()` stays.
- **`ItemImageExtractor`'s public API takes `CoreElement` throughout** (`fromMedia`, `fromCustomImageElement`).
- **Reader imports Parser for `kindOfMimeType()`**: no cycle (Parser depends only on Html, Image, Text, Url) and no
  `ServiceModuleBoundaryRule` entry forbids it, so the function stays put. `HtmlPageFetcher::isMediaFile()` keeps its
  name and becomes one line; #1536 moves it.
- `PodcastArtwork` keeps its `trim()` before asking `kindOfMimeType()`.
- `MediaImageClassifier` is deleted; its one caller asks `FeedMediaClassifier::kind()` directly.
- `/api/entries/{id}/reader` is untouched (feed parsing only), so no frontend `ReaderCacheService` bump.

## Files

- `backend/src/Service/Parser/Pass/CoreElement.php` — `isEnclosure()`
- `backend/src/Service/Parser/Pass/FeedMediaNode.php` — `toDeclaredImage()`
- `backend/src/Service/Parser/ItemImageExtractor.php`, `FeedItemImageSelector.php`, `ItemMediaExtractor.php`
- `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php`, `AbstractAtomParser.php`
- `backend/src/Service/Parser/Support/PodcastArtwork.php`; delete `Support/MediaImageClassifier.php`
- `backend/src/Service/Reader/HtmlPageFetcher.php`
- Tests: `ItemImageExtractorTest` (enclosure tests merged onto `fromEnclosure`, widening pinned),
  `FeedItemImageSelectorTest`, `EntryImageWriterTest`

## Tasks

1. Tests first: the merged enclosure tests plus the widening pins (RSS `<link rel="enclosure">`, Atom
   `<enclosure>`, type-less image-extension enclosure); see them fail.
2. Implement `isEnclosure`, `toDeclaredImage`, `fromEnclosure`, `fromRss2OrAtom`; switch callers.
3. MIME checks through `kindOfMimeType()`; delete `MediaImageClassifier`.
4. Gates, simplify pass, PR.
