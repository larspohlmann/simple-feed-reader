# Share a text selection as a quote (#1970) — design

Under #1962, after #1968 (link post). Visual round 2026-11-02; Lars took every recommendation.

## What

Selecting text in an article's body offers to share it as a quote together with the article's link: on a
connected Bluesky or Mastodon account through the existing composer, and to the other share destinations
through the share menu.

**The composer always opens.** Every network choice — bubble, phone bar, share menu — opens #1968's composer
prefilled with the quote. The reader adds their own text there; nothing is posted until **Send**.

## Decisions

1. **Desktop bubble.** A small bubble appears under the last line of a selection inside the article body,
   ~150 ms after the selection settles, when the selection holds at least 3 words. It shows one button per
   network with a connected account, and a share button that opens the share menu in quote mode. It closes
   when the selection collapses, leaves the article body, or scrolls out of view. The selection's own
   highlight is the visual feedback; the bubble confirms it can be shared.
2. **Share menu.** Without a selection, a muted hint sits under the network entries: “Select text in the
   article to share it as a quote” — only when at least one network entry exists. With a selection, the
   menu switches to quote mode: a snippet of the quote heads the menu, network entries read “Quote on
   Bluesky/Mastodon”, the system entry reads “Share quote…”, copy becomes “Copy quote with link”, and
   destinations that cannot carry text show dimmed with “(link only)” and share the plain link.
3. **Phone.** On a coarse pointer no bubble (Safari's own callout owns that spot). A bar docks at the bottom
   of the article while a qualifying selection exists: word count, network buttons, share button. The last
   qualifying selection is remembered, so the share button still opens in quote mode when tapping it
   collapsed the selection. Needs a live check on the iPhone.
4. **Post format.** The quote in typographic quotes, `“…”`, and nothing else; the caret sits before the
   quote so a comment can go first. Bluesky: text `“quote”`, the link in the card. Mastodon:
   `“quote”\n\nurl`. The title is dropped in quote mode — the card (Bluesky) or the link preview
   (Mastodon) carries it.
5. **Text-fragment link.** For a quote from a single block, the shared URL gets `#:~:text=` so the link
   opens scrolled to the quote and highlighted. A quote spanning blocks, and an entry that is itself a
   social post, share the plain URL. Long quotes use the `start,end` form (first and last few words).
6. **Long quote.** The prefill trims the quote at a word boundary with `…` so the starting text fits the
   network's limit (Bluesky 300 graphemes; Mastodon the account's maximum, the URL counted as Mastodon
   counts it). The composer's counter stays as it is for what the reader adds.
7. **Plain text.** A selection across paragraphs, links or media collapses to plain text, whitespace
   normalised, paragraph breaks kept as one line break.
8. **Other destinations.** The share targets are templates; quote mode changes what `{text}` and `{url}`
   hold: `{url}` is the fragment URL, `{text}` is `“quote” url`. A target whose template has no `{text}`
   is link-only. E-mail moves to `body={text}` (outside quote mode that is title and URL — an improvement,
   not a change of meaning). The system share passes the quote as `text`.

## Shape

- **Frontend**
  - `ArticleSelection` (article-scoped): watches `selectionchange` inside the article body and exposes
    `quote(): SelectedQuote | null` — the plain text, its word count, whether it stays inside one block, and
    the last qualifying selection kept for the phone.
  - Selection bubble (desktop) on a CDK connected overlay at the selection's last client rect; phone bar in
    the article's bottom chrome. Both reuse the share menu's network items and its quote mode.
  - Share menu: quote mode and hint as above; the action sheet carries the same items on phones.
  - `quoteShareUrl(url, quote)` builds the fragment URL; `shareUrl` fills `{text}` per mode.
  - `PostActions.postLink` takes an optional quote; the composer places the caret before the quote.
- **Backend**
  - `GET /api/social/link-composer` takes an optional `quote`; `SharedLinkModel` carries it;
    `ComposerStartingText::sharing` writes the quote form and trims it to the network's limit.
  - Sending is unchanged: the draft text is what the reader sent.

## Out of scope

- Markdown or rich quote formatting (no network supports a blockquote; Mastodon forks only).
- Quoting from list views, comments or the magazine.
- Sharing a selection as an image.

## Verification

- Unit tests for selection-to-text, fragment URL, template filling per mode, starting text and trimming.
- Live checks on desktop (`:4200`) and on the iPhone: bubble placement, phone bar vs Safari's callout,
  remembered selection, the composer prefill on both networks. No live post without Lars's go.
