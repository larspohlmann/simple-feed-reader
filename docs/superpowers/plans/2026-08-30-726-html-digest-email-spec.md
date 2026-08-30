# HTML digest email — magazine airy cards, CID-embedded images

**Goal:** Send the daily/weekly digest as HTML that echoes the reader's
"magazine airy" view — a white sheet of hairline-separated cards, each a
thumbnail beside a headline, source, time and excerpt — while keeping the
existing plain-text body as the `multipart/alternative` fallback. Images
(thumbnail + feed favicon) are fetched, resized and CID-embedded so the mail
carries no external requests and stays small.

**Spec:** [GitHub issue #726](https://github.com/larspohlmann/simple-feed-reader/issues/726)

## Design decisions (settled in the visual round)

- **Whole email in scope**, not just the card: branded header, saved-search
  group frame, footer.
- **Cards are links, no action icons.** The whole card points at the reader
  entry deep link (`?entry={id}`, the existing `DigestEntry->url`). Star /
  bookmark / check carry no state in mail, so they are dropped.
- **Grouping stays by saved search** (unchanged from the text renderer): a quiet
  kicker heading per term, up to 10 cards, then the existing `hasMore` /
  `moreUrl` "+N more" link at the group foot.
- **Absolute timestamps**, not relative. A batched, archived mail must not say
  "12 min. ago"; render `publishedAt` as a compact absolute in the user locale.
- **Feed-tag pills dropped.** The photo's "News de" is two feed tags, not a
  language; the mail is already grouped by search term, so a second taxonomy on
  every card adds noise. (Tags are not on the digest row today anyway.)
- **Unread dot dropped.** The digest holds only unread entries, so the dot would
  be "on" for every card — decoration, not signal.
- **Favicon kept, but CID-embedded** like the thumbnail (not a remote request).
- **Light theme only.** "Airy/light" is the brief; per-client dark-mode inversion
  is out of scope.
- **~30-entry overall cap** across the whole mail (Gmail clips HTML near 102 KB).
  Past the cap, remaining groups render heading + count-only "+N more →", no
  cards. The per-search cap of 10 is unchanged.
- **CID images, fetched and resized.** Strato's PHP has GD (bundled 2.1.0,
  JPEG/PNG/WebP) and Imagick — confirmed on the live host 2026-08-30. The repo
  lacks the extension; that is the only build gap.
- **Per-user format preference `digest_format` (`html` | `text`, default
  `html`).** A new preference beside `digest_enabled` / `digest_cadence`,
  carried in `UpdateDigestRequest` and `MeJson` with a migration, and a control
  in `email-section.component`. `html` sends `multipart/alternative` (HTML +
  text fallback); `text` sends the existing plain-text mail only. The default is
  `html`. `SendTestDigest` honours the same preference, so the test mail shows
  what will really arrive.

**Out of scope for v1:** feed-tag pills, dark mode, star/bookmark/check
deep-links, a language pill (no such field), hero/full-bleed images.

## Global constraints

- Branch `feature/726-html-digest-email`, off `develop`.
- Commits `type(#726): summary`. `composer check` + `composer md` before each
  backend commit; `npm run check` before each frontend commit.
- Keep controllers thin (`ThinControllerRule`); no browser-coupled endpoint —
  this is mail, so `application/problem+json`, Bearer, native-iOS constraints do
  not apply, but the Clean Code / immutability house style does.
- The HTML renderer is fed by the **same `DigestModel`** as the text renderer, so
  grouping / `hasMore` / cap logic is never duplicated.
- Reuse the existing SSRF-guarded fetch stack for every outbound image request —
  do not open a new HTTP path.

## Templating decision (the issue's main open question)

Render HTML **from PHP**, no Twig. The backend has no `templates/` directory and
renders HTML nowhere else; adding `twig/twig` for one mail body is a dependency
the tree does not otherwise want. A `final readonly` renderer that composes small
`string`-returning methods (mirroring `DigestTextRenderer`) keeps the markup in
one testable place. Inline every style attribute; keep markup to a table-safe
subset (nested `<table>` for the card, not fl: Outlook).

## Backend

### B1 — widen `DigestEntry`
- Add `?\DateTimeImmutable $publishedAt`, `?string $imageUrl`, `?int $imageWidth`,
  `?int $imageHeight`, `?string $faviconUrl` to the `final readonly` DTO. All
  nullable — a feed may carry no image and no resolved favicon.
- `DigestComposer::entry()` reads them off the already-hydrated `$row->entry`
  (`getPublishedAt()`, `getImageUrl()`/`getImageWidth()`/`getImageHeight()`,
  `getFeed()->getFaviconUrl()`) — no new query, no new join.
- The text renderer ignores the new fields; its output must not change.
- Test: composer maps the new fields; a null image / null favicon row maps to
  nulls; the text render is byte-identical to today for a fixed model.

### B2 — image fetch + resize + CID payload
- New `App\Service\Mail\Digest\DigestImageEmbedder` (`final readonly`) that, given
  a `DigestModel`, returns a value object of `EmbeddedImage`s (cid, bytes,
  content-type) plus a map from source URL → cid.
- Fetch through the existing guarded stack (`UrlGuard::assertSafe` +
  `FailoverRequestSender::send`, as `CatalogFaviconFetcher` already does). Do not
  hand-roll a new HTTP client; the 256 KB body cap and content-type allow-list
  come for free.
- Resize with GD: thumbnail → 176×132 (2× of 88×66) JPEG ~q80; favicon → 32×32
  PNG. **Bound source dimensions before decode** (guard against a small-bytes /
  huge-pixels image blowing the 512M web limit); skip and downgrade if the source
  is absurdly large.
- **Dedup by content:** one feed's favicon is fetched and attached once and
  referenced by every card's `cid:`. Key the embed cache by source URL.
- **Never fail the send:** any fetch or resize error drops that one image —
  thumbnail failure → text-only card, favicon failure → source name with no
  glyph. Log at debug, continue.
- Add `ext-gd` to `composer.json` `require` and to both `docker/php/Dockerfile`
  stages' `install-php-extensions` line (dev + prod), so CI/dev match the host.
- Tests: dedup returns one embed for a repeated favicon URL; a fetch failure
  yields a model position with no cid; resize output is the target dimensions and
  content-type; oversized-pixel source is skipped not fatal.

### B3 — `DigestHtmlRenderer`
- `App\Service\Mail\Digest\DigestHtmlRenderer` (`final readonly`), fed the
  `DigestModel` + the url→cid map from B2. Returns the full HTML string.
- Small composed methods: `page()`, `header()`, `group()`, `card()`, `footer()`.
  No method does two things; each reads as a sentence.
- Absolute time via the existing formatting path in the user locale (the mail
  runs under the recipient's locale like the text renderer's catalogue).
- Every colour / size inlined as literal values from the spec table below.
- Footer carries "Open in reader", a "turn off these emails" link to the digest
  settings, and the "you're getting this because" line.
- Tests: renders a card with and without an image; renders the group "+N more"
  only when `hasMore`; overflow groups past the cap render heading-only; the
  disable-digest link is present.

### B4 — overall cap
- A small `DigestCap` (or a method on the composer) that walks groups newest-first
  and stops adding **cards** once ~30 entries are placed, marking later groups as
  heading-only while keeping their `totalCount` for the "+N more" line.
- Applies to the HTML render only; the text render is unaffected (or applies the
  same cap — decide, but do not regress the text output silently).
- Test: a model with >30 entries places 30 cards; the 31st group is heading-only
  with a correct remaining count.

### B5 — wire the mailer to `multipart/alternative` + `related`
- `DigestMailer` sets both an HTML body (with the CID images attached as inline
  parts, `Content-ID` matching the `cid:` refs) and the existing text body as the
  alternative part — text stays the real fallback, not dead code.
- Add a `List-Unsubscribe` header pointing at the digest-settings URL.
- Test (functional, through the mailer, not a direct renderer call): the sent
  message is `multipart/alternative`, the HTML part is `multipart/related` with N
  inline images whose `Content-ID`s match the body's `cid:` refs, and the text
  part is present and unchanged.

### B6 — `SendTestDigest` exercises HTML
- The test-digest path must render and send the HTML (with real fetched+resized
  images) so the test mail shows exactly what a real digest will look like.
- Test: the test-digest command produces a `multipart/alternative` message.

## Frontend

There is no per-user format preference in this plan — the mail always sends
`multipart/alternative` (HTML + text), so every client already gets the body it
can render, and no setting is needed. (The issue floated a `digest_format`
toggle; with a proper alternative part it is unnecessary. Revisit only if a user
actually wants to force plain text.)

## Card spec — exact inlined values (light / graphite)

- Page bg `#f5f5f4`; content column `#fff`, `max-width:600px`, centered.
- Header text `#8f8f8b`, `#e4e4e2` hairline under.
- Group kicker: term, ~13px, `#8f8f8b`, `#e4e4e2` rule under.
- Thumbnail cell: 88×66, `border-radius:8px`, hard `width`/`height`; omitted when
  no image. 12px gap to text column.
- Kicker row: 13px `#8f8f8b`; favicon 16px · source name · `·` · absolute time.
- Headline (the link): 15px, weight 500, line-height 1.35, `#2a2a2a`.
- Excerpt: 13px, line-height 1.4, `#5f5f5c`, the existing 200-char
  `shortDescription`.
- Between cards: 24px vertical space with a centered 1px `#e4e4e2` hairline.
- Font stack: `system-ui, -apple-system, 'Segoe UI', roboto, sans-serif`.

## Verification

- `composer check` + `composer md` green; new `src` files PHPMD-clean.
- Frontend gate unaffected (no frontend change).
- `SendTestDigest` against the Docker stack produces a mail that renders in a real
  client (Apple Mail / Gmail web) with visible embedded thumbnails and favicons.
- Confirm the HTML body stays well under Gmail's ~102 KB clip at the 30-card cap.
