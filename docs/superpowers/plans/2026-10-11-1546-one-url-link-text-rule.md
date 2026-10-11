# #1546 One "link text reads as a URL" rule for own prose and shared links

**Goal:** `OwnProse` and `SharedLinks` stop disagreeing about which link text is a URL. Own prose is computed
from the DOM pass `SharedLinks` already makes, after bare URLs are linked, and the classifier parses an entry's
body once for both of its decisions.

## Rulings

- **One predicate**, `UrlLinkText::readsAsUrl()`: the anchor's trimmed, lower-cased text, a trailing `…` dropped,
  starts with `http://`/`https://`, or is a domain (`www.` optional) that ends there or goes on with `/ : ? #`.
  A bare `example.com` now reads as a URL (it did not for `OwnProse`).
- **`SharedLinks` keeps its stricter check on top:** a scheme-prefixed text, or the anchor's own host.
- **URL-looking text that points elsewhere** (`<a href="https://example.com/a">www.other.org/x</a>`) reads as a
  URL, so it is not own prose, and it is not the anchor's own host, so it is not a shared link either.
- **Bare URLs count as links for prose:** own prose is read after `SharedLinks` links them, so a bare
  `https://example.com/bare` no longer counts toward the 500 characters or the card text.
- **`SharedLinks::of()` always parses.** The "no `http` in the body" short-cut went, as own prose needs the
  DOM too; the body still comes back byte for byte when nothing was linked.
- **The classifier links a post's bare URLs** (moved from `ShareEnrichmentQueue::linkBareUrls`), from the one
  `SharedLinksModel` it already holds, under the same conditions as before: a title-derived link share, not
  awaiting an embed, with a body. The queue no longer parses the body.
- `OwnProse` stays a `Support` helper, now over the DOM (a clone of the body), not a regex over HTML.

## Files

- `backend/src/Service/Share/Support/UrlLinkText.php` (new), `OwnProse.php`, `SharedLinks.php`
- `backend/src/Service/Share/Model/SharedLinksModel.php` (+ `ownProse`)
- `backend/src/Service/Share/EntryShareClassifier.php`, `backend/src/Service/Completion/ShareEnrichmentQueue.php`
- `backend/src/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php` (model constructor)
- tests: `EntryShareClassifierTest` (the three table cases), `OwnProseTest`, `UrlLinkTextTest`,
  `ShareEnrichmentQueueTest`, `PostEnrichers`

## Tasks

1. Pin the three table cases in `EntryShareClassifierTest`; watch them fail.
2. Add `UrlLinkText`, rebuild `OwnProse` over the DOM, add `ownProse` to `SharedLinksModel`.
3. Classifier uses the one model for the prose verdict, the summary, the post's links and the bare-URL linking;
   the queue drops its own parse.
4. Re-run the verdicts over the dev database's entries before and after; record changed verdicts in the PR.
5. Gates, simplify pass, PR.
