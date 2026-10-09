# #1458 Plain text pasted into a `<p>` keeps its line breaks — Implementation Plan

**Goal:** A `<p>` whose text is pasted plain text (a blank line between two pieces of text) shows its line breaks,
in stored feed bodies and in reader extractions.

**Rule (measured, issue #1458):** a text node whose parent element is a `<p>` and whose text has a blank line
between two non-blank runs (`/\S[ \t]*\n[ \t]*\n\s*\S/`) is pasted text. In it, every newline becomes `<br>`,
and a run of blank lines becomes one extra `<br>` (so `a\n\nb` → `a<br><br>b`). Lines are trimmed; the node's
leading and trailing whitespace stays, so it still separates from a neighbouring `<img>` or `<a>`.
Not the signal: single newlines (hard-wrapped prose, 26/50 fixtures and ~1,300 stored bodies) and text outside a
`<p>` (DJ Mag's whitespace-formatted layout text). Text inside `pre`, `code`, `script`, `style`, `textarea`
never reaches the rule, since its parent is not a `<p>`.

**Architecture:**
- `backend/src/Service/Html/Support/PastedTextBreaks.php` (static, DOM-level, the one home of the rule):
  - `restoreIn(\Dom\Document $document): void` — rewrites every matching text node in place.
  - `inHtml(string $html): string` — the fragment form for a stored body: returns `$html` unchanged unless the
    regex prefilter hits; otherwise parses `<body>` + fragment, restores, returns `body->innerHTML`. Only a
    hitting body is reserialised (≈13 of 46,207 stored bodies).
- Feed bodies: `Service/Parser/Support/FeedBodyHtml::of(?string): ?string` =
  `PastedTextBreaks::inHtml` over `PlainTextBody::asHtml` (#1447), so the three parser call sites
  (`Rss1Parser`, `Rss2Parser`, `AbstractAtomParser::elementMarkup`) call one thing. Parser → Html is an existing
  module dependency (`ItemImageExtractor`, `PodcastArtwork`).
- Reader: a `BodyCleaningStep\PastedTextBreakRestorer` calling `PastedTextBreaks::restoreIn($pass->document)`,
  last in `ReaderBodyCleaner`'s list (it changes no `textContent`, so no step before it judges differently).
- Frontend: `ReaderCacheService.VERSION` 27 → 28, so cached extractions are rebuilt.
- Stored entries are not converted.

## Task 1: `PastedTextBreaks`

- [ ] Failing tests (`tests/Service/Html/Support/PastedTextBreaksTest.php`), through `inHtml()` unless noted:
  - `<p>a\n\nb\nc</p>` → `<p>a<br><br>b<br>c</p>`
  - `<p>\n  a\n\n  b  \n</p>`: lines trimmed, edge whitespace kept
  - `<p><img src="x">\n    Intro.\n\nTrack:\n1. One</p>` (the SoundCloud shape): the image stays, the text breaks
  - unchanged: single newlines only (`<p>hard\nwrapped</p>`); blank line only at the edges
    (`<p>\n\n  text\n\n</p>`); blank-line text outside a `<p>` (`<div>a\n\nb</div>`, top-level text);
    inside `<p><code>a\n\nb</code></p>`; a body with no newline at all (the exact input string comes back)
  - entities: `<p>Fish &amp; Chips\n\nPeas</p>` keeps one `&amp;`
  - `restoreIn()` on a parsed document rewrites the same way
- [ ] Implement; tests green.

## Task 2: Feed bodies

- [ ] `tests/Service/Parser/Support/FeedBodyHtmlTest.php`: a tag-free body still converts (#1447); a `<p>` with
      pasted text converts; plain HTML passes through untouched.
- [ ] One parser test (`Rss2ParserTest`): a CBC-shaped `<description>` (`&lt;p&gt;…\n\n(Photo…)&lt;/p&gt;`) comes
      out with `<br><br>`.
- [ ] Create `FeedBodyHtml`; switch the three parser call sites from `PlainTextBody::asHtml` to it.

## Task 3: Reader

- [ ] Fixture `backend/tests/Fixtures/reader/soundcloud-track-noscript.html` — synthetic, modelled on the
      SoundCloud no-JS fallback: `<noscript><article>` with header, `<p><img …>` + multi-line description with a
      blank line, footer.
- [ ] `ArticleExtractorTest`: extracting the fixture keeps the description's breaks (`<br>` between track lines).
      Confirms readability keeps the text node's newlines up to the cleaner.
- [ ] `PastedTextBreakRestorerTest` (step level, `ParsesHtml` + `BodyCleaningInputs`).
- [ ] Add the step last in `services.yaml`, `ReaderBodyCleanerTest::steps()` and `ReaderBodyCleanerWiringTest`.
- [ ] `frontend/src/app/reader/article/content/reader-cache.service.ts`: `VERSION` 27 → 28.

## Task 4: Gates and delivery

- [ ] `composer check`, `composer md` (touched files), `composer test:parallel`, MySQL leg
      (`docker compose exec php composer test`), `composer infection:diff`; frontend `npm run check` in Docker.
- [ ] Real run: `app:reader:audit --entries=575593` — the extracted body carries `<br>` in the track list; then
      Reload article on entry 575593 in the browser.
- [ ] Commit `fix(#1458): …`, push, PR into `develop` with `Closes #1458`.
