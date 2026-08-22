# Feed preview rendered like the reader — design (#519)

**Status:** approved (visual round settled 2026-08-22)
**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/519

## Problem

The add-feed dialog previews each candidate feed as a thin metadata summary:
a title, an item count, a few badges, and up to three plain-text item titles.
It tells the user *that* a feed has items, not *what* the feed will look like
once subscribed. The preview payload was built as a shape summary, so it drops
the very fields a real entry card reads (image, source, snippet).

## Goal

Show a real render of the sample entries in the add-feed dialog, so the user
sees what a feed carries before subscribing. The preview must *look* like the
reader's own entry row.

## Decisions (settled in the visual round)

1. **Look — the reader's entry row.** The preview shows each sample entry as
   the same visual card the reader uses for its search results and list layout:
   title, favicon + source, relative time, a four-line snippet, and an 88×66
   thumbnail when the entry has an image. This drops the ticket's original open
   question of "run the magazine planner over a sample vs. render a fixed block
   set" — there is no planner, just one simple row.

2. **Code — a decoupled duplicate component, not the shared row.**
   *(Lars's call, 2026-08-22.)* Rather than add a preview mode to the shared
   `app-entry-row`, the preview gets its own component, `app-preview-entry-row`,
   that starts as a visual copy of the reader row but is **inert by
   construction**: no click or keyboard target, no favorite / keep / read
   actions, no tag pills, no read dot, no outputs. The trade is a small amount
   of duplicated markup and style, bought deliberately so the preview is
   insulated from future changes to the reader's entry row and can never
   entangle preview concerns into it. The shared `app-entry-row`,
   `app-entry-actions` and `app-source-tags` are **not touched**.

3. **Many candidates — expand on select.** When discovery returns more than one
   feed, each stays a slim candidate row. The selected candidate expands into
   the preview rows. Only the expanded candidate's sample is fetched (lazily)
   and rendered. When discovery returns exactly one feed, it opens expanded.

4. **Sample size and dialog width.** Raise the preview sample from 4 to **8**
   entries. The dialog grows from 440px to a **520px** desktop variant so an
   88px thumbnail plus a four-line snippet sit comfortably. Record the variant
   in the design language.

5. **Mobile — full-screen on phones.** A 92vw card squeezes the row (title
   ~125px on a 375px screen). So on phones the add-feed dialog opts into the
   shared overlay panel's `fillOnMobile` mode and becomes full-screen, giving
   the preview rows the reader's mobile width (text column ~178px). The shared
   panel already caps its width at `min(--panel-w, 92vw)` and scrolls its body
   at 90dvh, so the 520px desktop variant can never overflow either.

## Backend

Enrich the preview item so it carries what the preview row shows. The payload
stays **plain data**: text plus one image URL. No HTML is shipped, so no
sanitizer is involved.

- Each preview item carries: `title`, `url`, `author`, a plain-text `summary`
  snippet (produced by the same `EntrySnippet` the ingest pipeline uses),
  `imageUrl` with declared `imageWidth` / `imageHeight`, and `publishedAt`.
- `imageUrl` is included only when the feed's image URL is `https://` — the SPA
  is https, so http / relative / data images are useless in an `<img>` and are
  dropped (mirrors the reader's `firstPreviewImage` rule).
- The source label and favicon are feed-level: `source` is the feed title; the
  favicon is not resolved on the preview path, so the row shows the shared
  favicon component's `rss_feed` fallback glyph. (Favicon discovery for the
  preview is a possible follow-up, out of scope here.)
- Keep everything else as is: the SSRF-guarded fetch, the `scraped` bypass
  guard, the content-tier verdict, and the candidate-level summary badges
  (item count, content tier, has-images, format).
- Raise the sample size from 4 to 8.
- The payload is plain JSON — a data shape, not a browser-only render contract
  (native iOS readiness).

## Frontend

- Add `app-preview-entry-row` (decision 2): a standalone, inert component that
  renders a `FeedPreviewItem` plus a feed-level `source` in the reader row's
  visual form. Its own sibling `.scss`. It reuses only shared leaf components
  (`app-favicon`) and shared pure helpers, never the reader's row.
- In the add-feed dialog, render the enriched sample through
  `app-preview-entry-row`, expand-on-select with a lazy per-candidate preview
  fetch (decision 3). No magazine planner.
- Widen the dialog to the 520px variant and set `fillOnMobile` on the overlay
  panel (decisions 4, 5). Record the variant in `docs/design-language.md`.

## Testing

- Backend: unit-test the enriched mapping — an entry with an https image
  serializes with `imageUrl` + dimensions; an http image is dropped; the
  summary is the plain-text snippet; the sample caps at 8. Update the controller
  JSON-shape test to the new item keys.
- Frontend (Jest): `app-preview-entry-row` renders title, source, snippet and
  an https thumbnail; omits the thumbnail with no image; exposes no interactive
  affordances (no button role, no action buttons). Update the add-feed dialog
  spec for expand-on-select and the lazy preview fetch.
- E2e: the existing `add-feed-mobile.spec.ts` stubs its own preview route — keep
  it owning its data; update the stub to the new item shape and keep the
  panel-fits-phone and body-scrolls assertions passing under `fillOnMobile`.

## Non-goals

- No magazine planner in the preview.
- No changes to the shared `app-entry-row`, `app-entry-actions` or
  `app-source-tags` (the decoupled duplicate is deliberate — decision 2).
- No HTML in the preview payload, so no HTML sanitizer on the preview path.
- No favicon discovery on the preview path (follow-up).
