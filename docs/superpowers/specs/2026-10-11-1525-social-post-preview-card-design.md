# #1525 — A quoted social-media post gets a preview card

When a post quotes another post, the magazine list shows only the quoting post's own words. For example,
dev subscription 1745 (`@protip.altgov.info`, Bluesky) has entry 580567, which reads "Well, it definitely
lived up to its name", with no sign of what it refers to. This spec shows a quoted post as a link card,
the same card a link post already shows (#1502), in the reader and in the magazine.

## Decisions (agreed 2026-10-11)

| Question | Decision |
|---|---|
| Card shape | Reuse the link card. Title "Name (@handle)", description the post text, image the post's first media image, site the host. No card kind, no schema change. |
| Bluesky quote in the body | The card replaces `figure.quote-post`. The reader and the list show the same card. |
| A post with its own media and a quote card | Today's rule is unchanged: any card makes it a link post, so the quote card wins in the magazine. |
| Posts already stored | Convert the local Docker install once with a throwaway script. No migration. |

## Measured

- Dev DB, title-derived posts: 17 hold `figure.quote-post` (Bluesky) and 9 open with a Mastodon `RE: <a>` line.
  Feed 553 has no `link_card` row.
- The list headline of a post is its excerpt, which comes from `summary`.
  - Bluesky builds `summary` from the body before the embed, so the quote never reaches the list.
  - Mastodon's `summary` starts with the quote line, e.g. 580144 reads "RE: https://mastodon.social/@Gargron/117327932994028225 Reposting for the morning crew".
- Open Graph tags on post pages, read with the app's user agent:
  - bsky.app: `og:title` "The Washington Post (@washingtonpost.com)", `og:description` the post text, and `og:image` the media (or the avatar).
  - Mastodon (digitalcourage.social): `og:title` "Laszlo (@Laszlo@digitalcourage.social)", `og:description` "Attached: 1 image\n\nRötelmaus…", and `og:image` the attachment.
  - X: `og:title` "Elon Musk (@elonmusk) on X" and `og:description` the text.
  - So the existing `PageCardReader` already gives a usable card for a post URL, without per-site code.

## Design

### 1. Bluesky: a quote is a card, built at ingest

`PostEmbedRenderer::quote()` returns a card instead of HTML:

- **url:** `AtPostUri::webUrl(record.uri)`
- **title:** `authorName(record.author)` (unchanged rule: "Name (@handle)", "@handle", the name, or "Bluesky")
- **description:** `record.value.text`, or null when blank. `StorableLinkCard` clips it to 300 characters.
- **image:** the quoted post's first media image. Taken from `record.embeds[0]`:
  - `images#view`: `images[0].thumb`
  - `video#view`: `thumbnail`
  - `external#view`: `external.thumb`
  - `recordWithMedia#view`: the same rules applied to its `media`
  - anything else: none
  - The URL goes through `HttpsImageUrl::orNull`, as every other Bluesky image does.
- **site:** `HostKey::forUrl(url)`, i.e. `bsky.app`
- **lead image:** none. A quote card never becomes the entry's lead image, so it never displaces the post's own media.

The card goes through `StorableLinkCard::of`. A quote that does not render (gone, blocked, not a post) still
falls back to the "View embedded content on Bluesky" paragraph.

`RenderedEmbedModel` holds a list of cards (`linkCards`), so the card a factory builds becomes a one-card list.
`followedBy` concatenates the lists. A `recordWithMedia` with a quote and an external link therefore gives
two cards, quote first. `EntryEmbedWriter` strips each card's URL from the body's end (`TrailingUrl`, a no-op
for the quote) and stores all the cards in order.

### 2. Mastodon: the `RE:` link is a shared link

- `SharedLinks` no longer skips the quote link. The URL joins `urls` in post order; it comes first, since the
  `RE:` line opens the post. `SharedLinksModel` also carries `quoteUrl` (or null).
  - A post whose only link is the quote is now queued for link cards too.
- The quote test (the `<p>`'s text before the anchor is `RE:`) moves to a new `LinkCard/Support/QuoteLine`,
  next to `QuoteLine::removedFrom(html, url)`. That method:
  - removes the `RE:` text and the quote anchor,
  - removes the paragraph if nothing but whitespace is left,
  - returns the HTML unchanged when it finds no quote of that URL.
- `PostLinkCards` builds the card through the existing `PageCardReader`. When it stores a row's cards and one
  card's URL is the row's `quoteUrl`, it also:
  - sets the entry's `contentHtml` to `QuoteLine::removedFrom(...)`,
  - re-derives `summary` with `EntrySnippet::from`.
- When the quote page fails, no card exists, and the line stays, so the link is never lost. This is the
  `TrailingUrl` rule of #1502.
- Mastodon's "Attached: N image" prefix in `og:description` stays. It is site-specific text, and the reader
  pipeline's host-agnostic rule applies.

### 3. Plain links to a post

No change. A bsky.app, Mastodon or X post URL is a shared link like any other and gets its card from Open Graph.

### 4. Frontend

No change.

- The reader's `app-link-cards` and the magazine's `entry-link` block already render `linkCards`.
- `isLinkPost` already claims a post with a card.
- The `.quote-post` styles in `reader-view.component.content.scss` stay, because entries already stored on
  prod still contain the figure.

### 5. Converting the local Docker install (one-off, not committed)

A throwaway PHP script, run once in the php container and deleted afterwards:

- **Bluesky:** for each entry holding `figure.quote-post`:
  - Cut `contentHtml` at its first embed figure (`quote-post`, `post-images` or `post-video`) or fallback
    paragraph, so the body is back to the post's own text.
  - Clear its media and image.
  - Read the post from the AppView, then run `EntryEmbedWriter::fill`.
- **Mastodon:** for each title-derived entry whose body opens with a `RE:` quote:
  - Delete its cards.
  - Queue its LinkCards row again (`done_at` and `claimed_at` null).
  - Run `PostLinkCards::complete` on it.

## Testing

- **`PostEmbedRendererTest`:**
  - A quote is a card with author title, text description, no HTML and no lead image.
  - The card image comes from each quoted embed kind.
  - `recordWithMedia` gives quote then external, with the external's lead image.
  - A hostile name or text stays plain text in the card.
  - The fallback still renders for an unrenderable quote.
- **`EntryEmbedWriterTest`:** a quote leaves no figure in the body and is stored as a card. Quote plus external
  is stored as two cards in that order.
- **`SharedLinksTest`:** the `RE:` link is shared and reported as `quoteUrl`.
- **`QuoteLineTest`:** removal with a plain space or `&nbsp;`; a paragraph that keeps other text keeps it; no
  quote of that URL gives the HTML byte for byte.
- **`PostLinkCardsTest`:** a carded quote loses its line and its summary is re-derived. A failed quote page
  keeps both.
- **Real run:** subscription 1745 and the two Mastodon feeds in the browser, in the reader and the magazine.
