# #1527 Untitled Bluesky link embeds get a fetched link card — implementation plan

**Goal:** a Bluesky post whose `app.bsky.embed.external` came without a title (the posting client never fetched
the preview) gets its link card from the page itself, like a Mastodon link post, instead of the
"View embedded content on Bluesky" fallback.

**Architecture:** the AppView pass keeps rendering the embed, but an untitled external embed renders no fallback
and names its URL as *uncarded*. The writer makes sure the body shares that URL as a link and reports that the post
awaits link cards. The enricher then turns the entry's `post_enrichment` row from `appview` into `link_cards`
(the row's `entry_id` is unique, and Doctrine inserts before it deletes, so the row is changed, not replaced) and
releases its claim, so `PostLinkCards` fetches the card on the next completion call, exactly as for Mastodon.

## Tasks

1. **`RenderedEmbedModel::$uncardedLinks`** (`list<string>`), a `RenderedEmbedModel::uncarded(string $url)`
   factory, merged by `followedBy()`. Test in `RenderedEmbedModelTest`.
2. **`PostEmbedRenderer::linkCard()`**: a valid URL without a title returns `RenderedEmbedModel::uncarded($url)`;
   no URL still falls back. Tests: an untitled external renders no html and names its URL; inside
   `recordWithMedia` too.
3. **`FillOutcome`** enum in `Service/Bluesky/Model` (`Unfilled`, `Filled`, `AwaitingLinkCards`);
   `EntryEmbedWriterInterface::fill()` returns it (`FailingEmbedWriter` follows). The writer links bare URLs via
   `SharedLinks::of()` only when there are uncarded links, and appends `<p><a href>url</a></p>` for any uncarded URL
   the body does not already share. Tests: bare URL in the body is linked and no fallback; a URL missing from the
   body is appended as a link; existing outcomes return `Filled` / `Unfilled`.
4. **`PostEnrichment::awaitLinkCards()`** switches the kind; **`PostEnricher::settle()`** keeps and releases such
   rows instead of removing them. Tests in `PostEnricherTest`: the row survives as `link_cards`, unclaimed, and
   the entry is returned as filled.
5. **End to end:** `EntryCompletionTest` (or the enricher test followed by `PostLinkCards`) — an untitled embed
   ends with a stored page card.
6. Gates: `composer check`, `composer md`, `php bin/phpunit`, `composer infection:diff`; a real run against the
   dev stack on a klimareporter post.

## Generalised (after review)

The rule is not Bluesky's untitled embed but: **every link a post shares ends with a card** — cards its source
supplied count, the rest are fetched.

7. **`EntryEmbedWriter`** links every bare URL of the body (as `PostEnrichmentQueue` does for Mastodon), appends the
   uncarded embed link when the body does not share it, and reports `AwaitingLinkCards` whenever the body shares a
   link that got no card — the untitled embed's or any other in the text.
8. **`PostLinkCards`** fetches only the shared links that hold no card yet (`LinkCardRepository::urlsByEntryIds`
   replaces `entryIdsWithCards`), budgets by those, and appends their cards after the stored ones
   (`LinkCardRepository::add()` takes the first position). A post whose links are all carded is done without a fetch.

No backfill: the posts already stored keep their fallback (dev data only as far as known).
