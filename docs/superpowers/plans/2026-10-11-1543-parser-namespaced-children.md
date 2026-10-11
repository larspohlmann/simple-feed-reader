# #1543 Parser dialects: one way to read a namespaced child, one home for namespaces

**Goal:** one API for reading a namespaced child (`CoreElement`), every feed namespace in one enum, and `Rss2Parser`
shaped like its siblings. Pure refactor: the namespace-decoy tests from #1484–#1492 pass unchanged.

## Rulings

- **One home:** `Service/Parser/Model/FeedNamespace` (string-backed enum, the module's enums live in `Model/`). It takes
  every namespace the parser names: Atom 1.0/0.3, RSS 1.0, RDF, Dublin Core, content, wfw, Media RSS, iTunes,
  podcastindex, Google Play. `Atom10Parser::NAMESPACE`/`Atom03Parser::NAMESPACE` and the `XmlHelper::*_NAMESPACE` and
  parser `*_NS` constants go.
- **Extension view:** `CoreElement::in(FeedNamespace)` re-reads the same element in a named extension namespace
  (`$item->in(FeedNamespace::DublinCore)->text('date')`), so an extension read still names its namespace (cc5ef35c0).
  Support code that holds a raw element uses `CoreElement::extension($element, FeedNamespace)`; signatures of
  `MediaDescription`, `PodcastArtwork`, `FeedMediaNode` stay as they are (#1542 owns the media side).
- `XmlHelper::childText/childElement/childHttpUrl` and the `*_NAMESPACE` constants are deleted; `firstText/firstHttpUrl/firstElement` move into
  `CoreElement` as private helpers (their only caller). `XmlHelper::childElements/isElement/isRoot` stay.
- No per-extension helper class for `content:encoded`/`dc:creator`/`dc:date`: after `in()` each read is one expression
  naming namespace and element, and the fallbacks around them differ per dialect.
- `Rss2Parser`: private `core()`, one `channelOf()` lookup shared by `isEntry()` and `parseFeed()`, and `isEntry()`
  derives the root from `$parent` instead of `$element->ownerDocument`.
- `Rss1Parser`: the `rdf:about` read becomes one `about()` helper returning `?string`.

- `XmlHelper::isElement/childElements` keep taking `?string` (CoreElement feeds them its own URI); their few direct
  callers pass `FeedNamespace::X->value`. `AbstractAtomParser::namespaceUri(): string` stays.
- Pre-existing literal namespace URIs in `FeedImageExtractorTest` and `StreamedFeedDocumentTest` stay: XML fixtures,
  outside this refactor.

## Files

- new `backend/src/Service/Parser/Model/FeedNamespace.php`
- `backend/src/Service/Parser/Pass/CoreElement.php`, `Support/XmlHelper.php`
- `FeedFormatParser/Rss2Parser.php`, `Rss1Parser.php`, `AbstractAtomParser.php`, `Atom10Parser.php`, `Atom03Parser.php`
- callers: `ItemMediaExtractor`, `ItemImageExtractor`, `Pass/FeedMediaNode`, `Support/MediaDescription`,
  `Support/PodcastArtwork`, `Support/ItemCategoryExtractor`, `Support/MediaRssSlot`
- tests: `CoreElementTest` (cover `in()`/`extension()`), `XmlHelperTest`, `tests/Support/FeedItemFixtures.php`

## Tasks

1. Add `FeedNamespace`; add `in()`/`extension()` to `CoreElement` with tests; move the `first*` helpers in.
2. Delete the `XmlHelper` wrappers and constants; migrate every caller.
3. Reshape `Rss2Parser` and `Rss1Parser`.
4. Gates: cs, stan, tramp, md, test:parallel, infection:diff, MySQL leg; simplify pass.
