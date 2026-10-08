# #1447 Plain-text feed bodies keep their line breaks — Implementation Plan

**Goal:** A feed body that is plain text (no markup) keeps its line breaks in the reader.

**Root cause:** The format parsers hand a plain-text `<description>` / Atom text construct through as
`contentHtml`; the sanitizer stores it verbatim and the SPA renders it as HTML, where `\n` is whitespace.

**Design:** One rule for every format, applied where a parser produces `contentHtml`: a body with no
markup and at least one line break is text. Blank-line-separated runs become `<p>`, single breaks `<br>`.
Only `<` and `>` are escaped: with no tag in the body, its entities are the feed's own (`&amp;` must stay
one entity), and a stray `<` must read as text. Atom's declared `type` is not used —
untyped Atom bodies carrying escaped HTML are common, and the shared rule handles both formats alike.
Stored entries are not converted (#1447).

**Files:**
- Create `backend/src/Service/Text/Support/PlainTextBody.php` — `PlainTextBody::asHtml(?string): ?string`.
- Create `backend/tests/Service/Text/Support/PlainTextBodyTest.php`.
- Modify `Rss2Parser`, `Rss1Parser` (`contentHtml:`), `AbstractAtomParser::elementMarkup` (text branch).
- Add one parser test each to `Rss2ParserTest`, `Rss1ParserTest`, `Atom10ParserTest`.

## Task 1: `PlainTextBody`

- [ ] Failing tests: null → null; markup body unchanged (`<p>a</p>\nb`); single-line text unchanged;
      `"a\nb"` → `<p>a<br>b</p>`; `"a\n\nb\nc"` → `<p>a</p><p>b<br>c</p>`; `\r\n` treated as `\n`;
      lines are trimmed, runs of blank lines collapse; entity `&amp;` survives; `a < b\nc` (a `<` that is
      no tag) converts with `&lt;`.
- [ ] Implement: tag test `/<[a-z\/!]/i`; normalise `\r\n?`; split on `/\n\s*\n/`; join lines with `<br>`.
- [ ] `php bin/phpunit tests/Service/Text/Support/PlainTextBodyTest.php` green.

## Task 2: Parsers use it

- [ ] Failing test per parser: a multi-line plain description/content comes out with `<br>`.
- [ ] Wrap `contentHtml` in `Rss2Parser`/`Rss1Parser` and the text branch of `elementMarkup` with
      `PlainTextBody::asHtml()`.
- [ ] Parser tests green.

## Task 3: Gates

- [ ] `composer check`, `composer md` on touched files, `composer test:parallel`, `composer infection:diff`.
- [ ] Commit `fix(#1447): …`, push, PR into `develop` with `Closes #1447`.
- [ ] Visual check of entry 569164 needs the branch in the main checkout (worktree can't run the stack) and
      a re-ingest; hand that to Lars.
