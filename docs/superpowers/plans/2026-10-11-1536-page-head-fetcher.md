# #1536 — Split the head read out of HtmlPageFetcher

**Goal:** `HtmlPageFetcher` fetches a whole page again and nothing else. The head read LinkCard uses moves to its own
Reader root service, `PageHeadFetcher`, and both land through one shared collaborator, `PageLanding`.

## Rulings

- **Undeclared and `text/plain` still count as markup.** No `Content-Type`, any `text/*`, `application/xhtml+xml` and
  `application/xml` are read for a head, as today; such a page still gets a card. Nothing in the code argues for a
  change, and a page served without a type is common enough to keep.
- **Any other declared type is a file and is not read.** The head fetcher cancels the response and returns only the
  final URL and the type. Today only `image/*`, `audio/*` and `video/*` skip the read; a PDF or a zip is streamed up to
  1 MB and parsed as HTML. The result is the same — a file's head carries no title, so it never made a card — but no
  body is downloaded. Pinned by a test.
- `PageResponseModel` drops `contentType`; the head fetcher returns `PageHeadModel` (final URL, head, the file's type
  or null). `PageCardReader` reads the type from that model instead of re-deriving "is a file" from an empty head.
- LinkCard's budget reads `PageHeadFetcher::MAX_DURATION_SECONDS`. Moving "can N more fetches start" onto
  `LinkCardBudgetModel` stays with #1545.
- The 'challenge', 'HTTP 404 Not Found' and 'out of time' messages stay. `/api/entries/{id}/reader` is untouched, so the
  frontend cache VERSION stays.

## Files

- New `backend/src/Service/Reader/PageLanding.php`: redirect landing with `RedirectChainException` mapping, request
  headers (Accept, `Accept-Encoding: identity`, User-Agent), the success check with its error-body snippet, the
  charset transcode, the challenge check, and the challenge-scan length both fetchers share.
- New `backend/src/Service/Reader/PageHeadFetcher.php` and `backend/src/Service/Reader/Model/PageHeadModel.php`.
- `HtmlPageFetcher`, `PageResponseModel`, `PageCardReader`, `LinkCardBudgetModel`, `ShareLinkCards`.
- Tests: the `testAHead…` cases move to `PageHeadFetcherTest`; wiring in `PageCardReaderTest`, `ArticleExtractorTest`,
  `ShareLinkCardsWiring`, `LinkCardBudgetModelTest`, `OutboundUserAgentWiringTest` (the agent now lives in
  `PageLanding`).

## Tasks

1. Extract `PageLanding`; rewire `HtmlPageFetcher` onto it.
2. Add `PageHeadFetcher` + `PageHeadModel`; move the head tests; add the "a declared file is not read" test.
3. Point `PageCardReader`, `LinkCardBudgetModel`, `ShareLinkCards` at the new fetcher; drop `contentType`.
4. Gates: cs, stan, tramp, md, test:parallel, infection:diff, MySQL leg; simplify pass.
