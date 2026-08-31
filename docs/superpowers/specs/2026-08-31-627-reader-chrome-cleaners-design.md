# #627 — Reader chrome cleaners (with the #744 audit findings)

## Problem

The reader's extract-and-clean pipeline leaves page furniture in front of the
article on a set of real pages. Two sources converge here:

- **#627**: a paywalled Substack post has no article prose for an anonymous
  fetch, so readability returns the page wrapper — an audio/video player's share
  menu and a paywall teaser — and it clears every length check.
- **#744 audit**: `app:reader:audit` swept 1000 sampled articles and, after the
  #746 false-positive calibration, left **17 findings across 9 feeds**. Every one
  is the same class of defect: chrome that survives the cleaners.

## The 17 findings, by shape

| # | shape | example | root cause |
|---|---|---|---|
| 7 | share button kept in the body | Canary Media `bsky.app/intent/compose?text=<page url>`, Nature `mailto:?body=<page url>`, 5 Magazine | `ShareWidgetRemover` matches six plugin **class tokens** only; a hand-rolled bar carries none |
| 5 | site menu above the article | Dissent `ul.side-nav`, Democracy Now `div#topics_nav` | `NavigationChromeTrimmer` needs a `<nav>`/role landmark; these are bare `ul`/`div` |
| 4 | headline repeated as a paragraph | Trancentral `<p><span>Chill Space Top Tracks July 2026</span></p>`, Nature | `LeadingTitleRemover` inspects `h1,h2,h3` only |
| 1 | ad label above the article | Groove `- Advertisement -` | no rule removes a standalone leading ad label |

## Goals

1. Clear all 17 findings with **generalized, host-agnostic** rules.
2. Resolve #627's paywalled case: no dead player chrome; show the clean teaser.
3. Keep every one of the **25 confirmed-good articles** (#746) clean.
4. No regression on the broader corpus (verified by re-sweep).

## Non-goals

- No new API failure reason and no frontend change. A gated/teaser-only post
  shows its cleaned teaser inline (decision: 2026-08-31). `ExtractionResult` and
  the Angular `ReaderFailure` type are untouched.
- No embedding of gated media. The Substack full episode is not reachable
  anonymously; the preview thumbnail is signed and 403s; a bespoke Mux/Substack
  media adapter would be per-host and would break the reader's TTL-less
  IndexedDB cache when the signed URL expires.

## Design

Five units. Four are generalized cleaners (Part A); one is a deliberate,
interface-backed Substack one-off (Part B). Each is small, single-purpose, and
independently testable.

### A1 — `ShareIntentLinkRemover` (new)

Runs in `FetchedPageNormalizer::repair()`, beside `ShareWidgetRemover`, **before
readability**, so the removed buttons never influence scoring or reach the body.

A link is a share control when its `href`:

- points at a known **share endpoint** — host (minus `www.`) + path prefix in a
  fixed list (`facebook.com/sharer`, `x.com/intent`, `twitter.com/intent`,
  `bsky.app/intent`, `threads.net/intent`, `linkedin.com/sharing`,
  `reddit.com/submit`, `pinterest.com/pin/create`, `tumblr.com/share`,
  `getpocket.com/save`, `api.whatsapp.com/send`, `t.me/share`,
  `telegram.me/share`, …), **or** is a `mailto:`; **and**
- **carries an absolute `http(s)` URL in its query** (plain or percent-encoded),
  which is the page it shares.

The page-URL test is the crux. A share button always hands the endpoint *this
page's address*; an editorial contact link does not. POLITICO's
`api.whatsapp.com/send?phone=…&text=Hey Zoya and crew!` has no page URL, so it
is **kept** (confirmed by Lars, 2026-08-31).

Removal target is the **cluster**, not the anchor: climb to the outermost
ancestor that holds share controls only and ≤ 60 characters of other text (a
"Share this article" label), stopping at `main`/`article`/`body`. So the bar's
label leaves with its icons.

### A2 — `NavigationChromeTrimmer` (extend)

Add a second anchor beside the existing landmark climb: a **leading
menu-shaped list**.

A `ul`/`ol` is menu-shaped chrome when **all** hold:

- it sits **before the first substantial paragraph** (≥ 120 chars of text, the
  audit's calibrated prose bar) in document order;
- it is **not** inside `main`/`article` (the existing in-content guard);
- its items are **outbound-link-dominated**: link text ≥ 0.6 of the list's text,
  and every link **leaves the page** (`href` non-empty and not starting `#`, so
  an in-page table of contents is spared);
- it carries **≥ 4** such links.

Remove the list and its single-purpose wrapper (reuse the existing
outermost-link-dominated-ancestor climb). The position bound is what keeps an
in-body link list (a "further reading" block mid-article) safe — the existing
landmark path stays position-independent, only the new list anchor is
leading-only.

### A3 — `LeadingTitleRemover` (extend)

Today it removes the first `h1/h2/h3` when it equals a title candidate. Extend
the inspection to the **first text-bearing block**, heading **or paragraph**:
if the first block's normalized text equals a normalized title candidate, remove
it. Normalization and candidate handling are unchanged. Only the *first* block
is ever considered, so a paragraph that merely mentions the title later stays.

### A4 — `EdgeBoilerplateTrimmer` (change)

1. **Remove the `EDGE_FRACTION` cap.** The edge stays defined by position
   (before the first / after the last substantial paragraph). Spike over 500
   real articles: byte-identical output on every page that has real structure;
   the cap only ever disabled the stage on 2–3-block wrapper fallbacks. Clears
   the latent no-op documented in #627.
2. **Add a standalone ad-label signal.** A block whose entire collapsed text is
   an ad label — `advertisement`, `anzeige`, `werbung`, `sponsored`,
   surrounding dashes/spaces tolerated, ≤ ~20 chars — is removed on its own when
   it lies in the leading edge. Unlike the two-signal boilerplate rule, an ad
   label is unambiguous and needs no corroboration. Fixes Groove.

### B — Substack gated-media placeholder (new, interface-backed one-off)

A deliberate host-specific rule, structured so a second host generalizes into a
tagged strategy set (the house pattern, per
`Service/Refresh/FeedBodyParser.php`). Interface `GatedMediaPlaceholder` with one
Substack implementation; `ReaderBodyCleaner` runs the keyed set after the
generic cleaners.

Detection (all required, so free posts are untouched):

- a paywall landmark is present — `[aria-label="Paywall"]` or
  `[data-testid="paywall"]`;
- a podcast/video player region is present — the `podcast-post`/`shows-post`
  article class or the player container.

Action:

- remove the dead player-control region (the "Playback speed × / Share post / …
  / 0:00 / Preview" fragments) and the paywall CTA block;
- insert, at the player's position, a **poster-image placeholder**: the post's
  `og:image` (public), rendered as
  `<a href="{source}"><img src="{ogImage}" width="{w}" height="{h}"
  alt="Video — open the original article to watch"></a>`, sized to the video's
  aspect ratio (from `videoUpload.width`/`height` in the embedded JSON, default
  16:9). All attributes survive `EntrySanitizer`;
- keep the teaser prose below it.

The poster URL and dimensions come from the **normalized page document**, not
readability's output (which discards them) — built once and passed into
`ReaderBodyCleaner::clean()` the same way `LeadImageCandidate` /
`PageImageInventory` already are.

## Data flow

`ArticleExtractor::extract`:

```
fetch → normalize ──┬─► PageImageInventory (existing)
                    ├─► GatedMediaCandidate  (NEW: paywall+player+ogImage+dims)
                    └─► ShareIntentLinkRemover runs inside normalize() (NEW)
      → richestArticle (readability)
      → ReaderBodyCleaner::clean(content, titles, leadImage, gatedMedia)   ← +1 arg
            NavigationChromeTrimmer   (extended)
            LeadingTitleRemover       (extended)
            EdgeBoilerplateTrimmer    (changed)
            ReaderLeadImage           (existing)
            SubstackGatedVideoPlaceholder via keyed GatedMediaPlaceholder set (NEW)
      → EntrySanitizer (unchanged)
```

`ReaderBodyCleaner::clean` gains one parameter (`GatedMediaCandidate`). If a
threaded-parameter chain trips phptramp, the candidate becomes a field on a
per-pass collaborator, not a longer signature — same remedy the tree already
uses.

## Testing

- **Unit, per unit, TDD.** New: `ShareIntentLinkRemoverTest`,
  `SubstackGatedVideoPlaceholderTest`. Extended: `NavigationChromeTrimmerTest`,
  `LeadingTitleRemoverTest`, `EdgeBoilerplateTrimmerTest`. Each new rule gets a
  boundary case on each side of its threshold and a "must NOT fire" case drawn
  from a confirmed-good shape (POLITICO contact link; in-page TOC; a legitimate
  short post; a free Substack post).
- **`ConfirmedGoodArticlesTest`** (audit side) extended with any new confirmed
  shape; all 25 stay clean.
- **Re-sweep**: `app:reader:audit --entries=<17 findings + 25 confirmed-good>`;
  every finding clears, every confirmed-good stays clean. Then a fresh
  stratified sweep to catch second-order effects.
- **Gates**: `composer cs`, `composer stan`, `composer md`, `composer tramp`,
  `php bin/phpunit`, `composer infection:diff` (minMsi 80). PhpStorm inspections
  on every changed PHP file (block on ERROR/WARNING).

## Risks

- **Share-endpoint list drift.** A publisher on an unlisted share host is missed
  (a kept share button, the pre-#627 status quo) — never a false removal,
  because the page-URL test gates every match. New hosts are one-line additions.
- **Menu-list over-reach.** The four-way guard (leading, outbound-only,
  link-dominated, ≥4) plus the in-`main`/`article` exemption is calibrated to
  the audit corpus; the re-sweep is the check.
- **Substack markup change.** The one-off breaks silently if Substack renames
  its paywall landmark; it fails *closed* (no placeholder, teaser still shown),
  never destroying content.

## Delegation

Subagent-driven, per CLAUDE.md tiers, `model` set explicitly on every call:

- `sonnet`, one per Part-A unit (A1–A4): well-specified, TDD, clear acceptance.
- `opus` for Part B: embedded-JSON read, aspect ratio, interface + keyed set.

The parent integrates, threads the new `clean()` parameter, runs all gates and
the re-sweep, and fixes cross-unit issues.
