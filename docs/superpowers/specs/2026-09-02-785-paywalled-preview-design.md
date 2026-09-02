# #785 — Detect a paywalled article and say the body is the free preview

Source: [issue #785](https://github.com/larspohlmann/simple-feed-reader/issues/785).
The issue is the design; this document restates it as the binding spec and
records the decisions the issue left open.

## Problem

Many short reader bodies from the 2026-09-02 render sweep are the free preview
of a paywalled article. The reader shows the preview as if it were the whole
piece. It should detect the paywall and say so under the body, so the reader
knows the text stops because of a paywall, not because of a broken extraction.

Examples (all extract "ok" with 260–430 characters):

| entry | host | signal on the page |
|---|---|---|
| 457216 | lastweekin.ai (Substack) | `div.paywall-cta` + `h2.paywall-title` |
| 482061 | joedolce.substack.com | same |
| 481913 | psychedelicpress.substack.com | same |
| 482682 | riffreporter.de | JSON-LD `hasPart.isAccessibleForFree: false`, `.paywall` |
| 331138 | sueddeutsche.de | JSON-LD `hasPart.isAccessibleForFree: "False"` (string) |
| 495251 | zeit.de | JSON-LD `isAccessibleForFree: "False"` (string), `.duv-paywall-preview` |
| 495258 | heise.de | JSON-LD `false` on the article beside `true` on other nodes, `.paywall-delimiter` |
| 491202 | jungle.world | no declaration, no `paywall` class: `.subscription-only` wraps body and CTA, `.subscription-only-block` is the CTA |
| 481899 | charleseisenstein.substack.com | none — the free negative case |

## Signals

Two host-agnostic signals. No host list.

1. **schema.org paywall markup**: any JSON-LD node with `isAccessibleForFree`
   equal to boolean `false` or the strings `"false"`, `"False"`,
   `http(s)://schema.org/False`. Usually on the article node or under
   `hasPart: { "@type": "WebPageElement", "isAccessibleForFree": false }`.
   Any `false` anywhere in any block means paywalled. A `true` with no `false`
   means free. No key at all means "no declaration".
2. **A paywall block in the DOM**: an element whose `class` attribute contains
   one of the fragments `paywall`, `subscription-only`, `subscriber-only`,
   `subscribers-only` (any case, any position in the token: `paywall-cta`,
   `duv-paywall-preview`, `subscription-only-block`), with non-empty text,
   outside `aside`, `nav`, `footer`. `subscribe` alone is a newsletter form,
   never a wall. The vocabulary is a rule about words, not hosts; a new word
   joins it with a measured page.

Signal 1 decides alone when present. Signal 2 is the fallback for pages with
no declaration.

## The DOM rule, decided

The issue says: take the DOM block only from the region below the last
extracted paragraph, or from an element that is gone from the extraction, so
an article that merely mentions a paywall in its prose is not flagged. The
precise rule:

- All text is compared **squeezed** (every whitespace removed), so source
  indentation and serializer line breaks never decide a match.
- The anchor is the last `<p>` of the **cleaned** body whose text is not part
  of a paywall block, located in the page text (`body.textContent` of the
  shared normalised document, captured before readability mutates it).
- When a gated wrapper encloses every paragraph (jungle.world), no anchor
  exists and the fallback below decides: the wrapper counts because its call
  to action is text the body lacks; a wrapper that adds nothing does not.
- A paywall block counts when its text stands at or after the anchor in the
  page text.
- When the anchor cannot be located in the page text (a cleaner rewrote the
  last paragraph), fall back to the "gone from the extraction" arm: the block
  counts when its text is absent from the cleaned body.
- A block that stands before the anchor never counts (a site-wide "support us"
  banner classed `paywall-*` above a free article; a mid-article promo box).

A paywalled Substack **video** post loses its DOM block before detection:
`SubstackGatedVideoPlaceholder` removes the `[data-testid="paywall"]` region in
the normaliser. That post already shows a poster linking to the source; it is
out of scope here.

## Where detection runs

- The JSON-LD blocks are read from the **raw fetched source** with a regex
  over `<script type="application/ld+json">` blocks, because
  `FetchedPageNormalizer` strips every script before the shared parse. No
  second DOM parse of the page.
- The DOM blocks and the page text are read from the **shared normalised
  document** (`FetchedPageNormalizer::normalize()`), right after
  `PageImageInventory::fromDocument()` and before readability consumes it —
  the #684 pattern.
- The verdict is computed once the cleaned, sanitised body exists.

## What to build

- Backend: `ExtractionResult` gets `public bool $paywalled` (always `false` on
  a `failed` result; `ok()` defaults it to `false`). `ReaderJson` emits
  `paywalled: bool` on the `ok` branch. Nothing browser-only: a plain boolean
  on the existing JSON keeps the native iOS client viable.
- The audit (#744) gains a `paywalled` metric (`0`/`1`) on every finding, so
  the next sweep separates paywall previews from over-trims. It is a metric,
  not a marker: a paywall is not a cleaner defect and must not raise the score.
- Frontend: when `paywalled` is true and the reader view is active, a line
  under the body says, in the reader's own words, that this is the free
  preview of a paywalled article and the rest is on the publisher's site, with
  a link to the source. i18n keys `reader.paywalled` and `reader.paywalledLink`
  in `de` and `en`. The note reuses the existing `.reader-note` class and adds
  **no CSS** to `reader-view.component.scss`: that file compiles to 7.97 kB
  against an 8 kB `anyComponentStyle` error budget.
- The IndexedDB reader cache version is bumped (10 → 11): cached articles carry
  no `paywalled` field and would never show the note.
- Do not suppress the preview and do not fall back to the feed body; the feed
  body is the same teaser.

## Acceptance

- Fixtures through the full `ArticleExtractor` pipeline: JSON-LD boolean
  `false`, JSON-LD string `"False"`, DOM-only `paywall-cta` block, a free
  Substack-shaped post (negative), a `paywall-*` banner above a free article
  (negative), and a JSON-LD `true` beside a paywall block (negative: signal 1
  decides alone).
- Live: `app:reader:audit --entries=…` over the nine entries reports
  `paywalled: 1` for the eight paywalled ones and `0` for 481899.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, and `npm run check` (tests in the Docker frontend container) are green.
