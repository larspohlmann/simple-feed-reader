# #1544 Text: finish adopting HtmlEscape and one shared ellipsis clip

**Goal:** one home for "clip by character and add an ellipsis" (`Text\Support\ClippedText`) and for HTML escaping
(`Text\Support\HtmlEscape`), so no module writes either again.

## Rulings

- A pure refactor: every site keeps the clip it has. A character clip stays a character clip; no site moves to
  `WordBoundary::clipped()`. The digest summary line and the reader's error snippet are word-clip candidates, but the
  digest text is user-visible, so that switch needs Lars's yes — named in the PR body, not done here.
- `ClippedText` moves from `Recommendation/Support` to `Text/Support`, beside `WordBoundary`.
- `DigestComposer` and `HtmlPageFetcher` drop the head's trailing whitespace before the ellipsis (a cut after `Hello ` reads
  `Hello…`, not `Hello …`); `ClippedText::of()` does not, and changing it would alter the recommendation prompts.
  So `ClippedText` gains `trimmedOf()` for those two; `PhraseMarkers` uses `of()`.
- `HtmlEscape` adds `ENT_HTML5`, so an apostrophe escapes as `&apos;` instead of `&#039;`, and `HtmlItemExtractor`
  gains `ENT_SUBSTITUTE`. Both render the same in HTML5 and SVG; the issue asks for this adoption.
- `EntrySnippet` and `EntryExcerpt` stay two rules. `EntrySnippet` is the stored summary (character cut, at
  ingest); `EntryExcerpt` is the list row's preview cut from it at read time. Making the stored cut word-based would
  change stored summaries, and the digest, search and recommendations read them, so it is not a refactor.
- `HtmlPageFetcher`'s snippet is reworked by #1536 later; only its clip is pointed at the shared helper.
- `/api/entries/{id}/reader` output is unchanged, so `ReaderCacheService` VERSION stays.

## Files

- `backend/src/Service/Recommendation/Support/ClippedText.php` → `backend/src/Service/Text/Support/ClippedText.php`
- its test, moved alongside
- `RecommendationPromptBuilder`, `ProviderErrorReason`, `ScoringArticle` (imports)
- `DigestComposer`, `HtmlPageFetcher`, `ReaderAudit/PhraseMarkers` (clips)
- `Scraper/HtmlItemExtractor`, `Catalog/MonogramFavicon`, `ReaderAudit/Pass/AuditReportHtml` (escapes)

## Tasks

1. `git mv` `ClippedText` and its test; fix namespaces and imports.
2. Add `ClippedText::trimmedOf()` with a test; point the three hand-written clips at the helper.
3. Replace the three `htmlspecialchars` calls with `HtmlEscape::text()`.
4. Gates: cs, stan, tramp, md, tests (SQLite + MySQL), infection:diff.
