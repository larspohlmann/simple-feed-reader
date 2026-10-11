# #1540 — a Reader service decides whether an entry has a page to read

**Goal:** `EntryReaderController::reader()` stops deciding post / no-URL / extract inline. A Reader root
service, `EntryArticleReader`, takes an `Entry` and returns the `ExtractionResultModel`, and exposes the one
predicate the limiter uses, `hasPageToFetch()`.

## Rulings

- **One predicate.** `hasPageToFetch()` and `read()` share a private `pageUrl()` (null for a post or a
  URL-less entry), so the limiter and the outcome can no longer disagree.
- **URL-less entries stop spending reader budget** (intended by the issue); pinned by a controller test.
- **Outcome order kept:** `FeedBodyIsPost` before `NoUrl`.
- **Coverage gate only on the extracted result.** `ExtractionCoverageGate::verify()` returns a failed result
  unchanged, so gating only the extract branch is behaviour-preserving.
- **`ReaderAuditRepository` SQL stays as it is.** Queries live in repositories and a SQL fragment shares no
  constant with a PHP predicate; a shared constant would not fit trivially.
- **`OriginalHeroResolver` stays in the controller** — a plain delegation for the response, not the decision
  this issue moves.
- `isTitleDerived()` is used as today; #1541 replaces it.
- Response shape unchanged → no frontend `ReaderCacheService` VERSION bump.

## Files

- Create `backend/src/Service/Reader/EntryArticleReader.php`
- Create `backend/tests/Service/Reader/EntryArticleReaderTest.php`
- Modify `backend/src/Controller/Api/EntryReaderController.php`
- Modify `backend/tests/Controller/Api/EntryReaderControllerTest.php` (URL-less entry answered on a spent budget)

## Tasks

1. Service test for the three outcomes (post, no URL incl. empty string, extracted + gated) and the predicate.
2. Implement `EntryArticleReader`.
3. Controller test: a URL-less entry is answered when the reader budget is spent.
4. Thin the controller to lookup → conditional limiter → service → `ReaderJson::one()`.
5. Gates: cs, stan, tramp, md, tests (SQLite + MySQL), infection:diff.
