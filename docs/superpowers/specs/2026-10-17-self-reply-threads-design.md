# Self-reply threads in the list — design

**Status:** approved in brainstorming, awaiting spec review · **Date:** 2026-10-17

## Problem

Accounts like Techpresso (`?subscription=1847`) post a headline, then answer
themselves: a reply with more text, then a reply carrying the link card of the
source. Bluesky's and Mastodon's profile RSS leave replies out, so the entry is
only the headline: no share kind, no link card, nothing to read on. The link the
author shared, the whole point of the post, never reaches the reader.

Measured on Techpresso (2026-10-17): every post is a three-post self-thread,
written within ~1 s, the card always in the last post.

## Goal

When the author of a Bluesky or Mastodon RSS entry continued it in their own
replies, the entry carries that continuation, and the list shows it the way
Bluesky draws a thread, inside the one entry card (mockup **E**, below). Its link
cards show like any shared link's.

## Decisions taken

| Question | Decision |
|---|---|
| Shape | The author's chain is part of the root entry — one entry, not several. |
| Look | Mockup E: posts on a thin thread line with a dot per post, `n/N` counters, source · time once on top, pills and actions once at the bottom; a reply's link card sits under that reply. |
| Networks | Bluesky RSS and Mastodon RSS feeds. |
| Which replies | The author's unbroken chain from the root: each reply answers the previous post, by the root's author. Stops at the first post by anyone else, or after **3** replies. Of several self-replies to one post, the oldest continues the chain. |
| When | Once, when the entry is enriched. A chain finished after that is not caught (accepted: scripted threads arrive within a second). |
| Existing entries | No backfill. |
| Out of scope | Account-timeline feeds (`SourceFormat::BLUESKY_API`, Mastodon home timelines), which already list each self-reply as its own entry; a reply's link that the network sent without a card (no page fetch for thread posts in v1). |

## Mockup E

```
┌──────────────────────────────────────────────────────────┐
│ (◉) Techpresso · 1 hr. ago ●                             │
│  ●  Apple has updated its App Store guidelines to ban    │
│  │  apps that produce AI-generated … 'nudify' apps. 1/3  │
│  │                                                       │
│  ●  The rule extends Apple's existing prohibition on …   │
│  │  'bait-and-switch' apps. 2/3                          │
│  │                                                       │
│  ●  3/3                                                  │
│     ┌───────────────────────────────────────────────┐    │
│     │ [MacRumors image]                             │    │
│     │ macrumors.com                                 │    │
│     │ New App Store Rules Target AI Deepfakes …     │    │
│     └───────────────────────────────────────────────┘    │
│ [bsky]                                      ☆  ⌑  ✓      │
└──────────────────────────────────────────────────────────┘
```

The root post is post 1/N and stays the entry's own text (headline, summary,
content, its own cards and media), so an entry without a thread is unchanged.

## Data model

New entity **`ThreadPost`** (`thread_post`), the replies only, in
`App\Entity`:

| Column | Type | Note |
|---|---|---|
| `id` | int | |
| `entry_id` | FK → entry, `ON DELETE CASCADE` | |
| `position` | smallint | 1..3, unique with `entry_id` |
| `url` | varchar(2048) | the reply on the web (bsky.app / instance URL) |
| `content_html` | text | sanitized body, links linked, its embed (images, video, quote) rendered as for a root post |
| `published_at` | datetime, naive UTC | |

`link_card` gains a nullable **`thread_post_id`** (FK → thread_post,
`ON DELETE CASCADE`). A card with none belongs to the root, as today; the unique
`(entry_id, position)` stays, so cards keep one post-order sequence per entry. A
root's cards are those with no thread post. `contentsByEntryIds` and its
callers keep reading only the root's cards; a sibling query reads thread cards.

A reply's images and video are rendered into its `content_html` only. They don't
become `EntryMedium` rows, so the entry's lead image and media share stay the
root's.

Migration on both dialects. CI's migrate-from-empty leg covers it.

## Backend flow

### One fold, a source per network

`Service/Social` owns the chain (it already walks reply threads for #1616):

- **`SelfThreadSourceInterface`** in `Service/Social/SelfThreadSource/`:
  `supports(Entry)`, and `chainOf(Entry): SelfThreadModel` (throws a typed
  exception on a failed answer).
  - **`BlueskySelfThreadSource`**: `AppViewClient::thread($guid, depth: 3)`. It
    walks `replies` choosing at each level the oldest `threadViewPost` whose
    author DID equals the root's, renders each with `PostEmbedRenderer` and
    `BlueskyRichText`, sanitizes it, and keeps the embed's link cards.
  - **`MastodonSelfThreadSource`**: the public
    `MastodonStatusAddress::contextApiUrl()` call that
    `MastodonReplyThreadSource::inPublic` already makes. From `descendants` it
    follows `in_reply_to_id` from the root, author matched as
    `MastodonReplyThread::isByPostAuthor` does. `content`, `media_attachments`
    and `quote` are rendered with the existing `Mastodon*` support helpers, and
    `card` becomes the post's link card.
- **`SelfThreadModel`** (`Service/Social/Model/`): a list of
  **`SelfReplyModel`** (url, html, publishedAt, `list<LinkCardContent>`). The
  3-reply cap and the stop rule live in one support class,
  **`Support/SelfReplyChain`**, which both sources feed with
  `(author, parent, child candidates)`, so the rule exists once.
- **`ThreadPostFactory`** (`Service/Social/Factory/`) builds the `ThreadPost`
  rows and their `LinkCard`s from a model. A persisting service,
  **`SelfThreadFolder`**, appends them to the entry, sets `ShareKind::Link` when
  any reply carries a card and the entry had no share kind, and re-indexes the
  entry. Search covers thread text, see below.

### Where it runs

- **Bluesky RSS:** inside the existing AppView pass. `getPosts` already returns
  `replyCount`. `PostEnricher::settle` folds a post with `replyCount > 0` after
  `EntryEmbedWriter::fill`, one `getPostThread` per such post. Posts without
  replies cost nothing extra. The fold uses the same throttle check
  (`AppViewClient::isThrottled`). If a thread call fails, the post settles
  unfolded and a warning is logged. It is never requeued, because the root fill
  already succeeded.
- **Mastodon RSS:** a new `ShareEnrichmentKind::SelfThread`. At ingest,
  `ShareEnrichmentQueue::queueCreated` queues an entry whose guid or link is a
  `MastodonStatusAddress` and whose feed is a Mastodon profile RSS feed (new
  `PendingEmbedInterface`-style check, `MastodonPendingThread`), ahead of the
  `LinkCards` kind. A new pass (`Service/Social/SelfThreadEnricher`, called from
  `EntryCompletion` and the refresh like `PostEnricher`) claims rows, folds
  them, then hands an entry whose root still has uncarded links to `LinkCards`,
  as `PostEnricher` hands over today. One context call per post goes to its own
  instance, behind `HostThrottle`. Rows give up after the existing AppView
  give-up window.

### Search

`EntryIndexer` adds the thread posts' plain text to the indexed body, so a
search for "deepfakes" finds the Techpresso entry.

### Reader replies (#1616)

`SocialRepliesSource` drops the replies whose URL is one of the entry's thread
posts, along with their place in the tree. Their children move up a level, so
others' answers to reply 2/3 still show.

## API

`EntryJson` gains **`thread`**: a list, empty for nearly every entry, of

```json
{ "url": "https://bsky.app/…", "html": "<p>…</p>", "text": "plain snippet",
  "publishedAt": "2026-10-17T08:15:31Z", "linkCards": [ /* LinkCardJson */ ] }
```

`LinkCardJson` gains `position`, the stored one (see "Open in reader" below).

`entry.linkCards` keeps meaning the root's cards. Old clients and a future iOS
client ignore `thread` safely. It's plain JSON, bearer-auth, no browser inputs
(architecture §6). The thread rows load in one query per page, like link cards
(`ThreadPostRepository::byEntryIds`).

## Frontend

- `EntryDto.thread: ThreadPostDto[]`.
- **List, magazine:** a new block **`entry-thread`**
  (`list/magazine/blocks/entry-thread/`). `magazine-slot-fit` gives it to every
  entry with `thread.length > 0`, whatever slot it got, as `sharesLink` does
  for the link block (#1502). The thread rule is checked before `sharesLink`,
  since a folded entry with a card is also a link share. The block shows the
  kicker line, then a
  `thread-line` list:
  - the root's headline and dek;
  - each reply's `text`, clamped like a dek;
  - an `n/N` counter pill;
  - the reply's first card as `app-link-card layout="block"`;
  - pills and actions at the bottom.
  `magazine-block-height` estimates the block from its texts and cards.
- **List, rows layout (`entry-row`):** the same thread line, text only, plus a
  card for a reply that shares one. Thumbnails stay the root's.
- **Article view:** below the root's body, each reply's `html` and cards on the
  same thread line, so the reader sees the full continuation.
- The thread line, dot and counter are one shared component
  (`shared/thread-line`), styled from tokens (no hex, no ad-hoc px), listed in
  `docs/design-language.md`.

## "Open in reader" on a reply's card

Today the button works by index. The list block sends `position: 0`, the article
sends the card's `$index` in `entry.linkCards`. The API serves
`/entries/{id}/link-cards/{position}/reader` by the stored `link_card.position`,
and `ReaderShellComponent` resolves the open `?link=` card client-side as
`entry.linkCards[ref.position]`. That only works while an entry's cards are
exactly `0..n-1` of one list. Thread cards break this in three ways, and each
gets a fix:

1. **Index ≠ stored position.** Thread cards share the entry's position
   sequence, so a reply's card might be stored at 1 while being index 0 of its
   reply's list. Fix: `LinkCardJson` gains **`position`**, the stored value, for
   every card, root and thread. Every "open in reader" call site sends
   `card.position`, never an index. That covers the magazine link block, the
   new thread block, the rows layout and the article's `link-cards` and thread
   view.
2. **Client-side lookup misses thread cards.** `ReaderShellComponent` (and
   anything else that maps a `LinkCardRef` back to a card) resolves it through
   one helper, `linkCardAt(entry, position)`. The helper searches the root's
   cards and then every thread post's cards by `position`. Indexing
   `linkCards[...]` directly goes away.
3. **Position collisions on append.** The Mastodon pass folds the thread
   *before* the root's link cards are fetched, so `LinkCardRepository::append`
   (which numbers new cards from `count($stored)`, the root's cards only) would
   reuse a thread card's position and hit the unique `(entry_id, position)`.
   Fix: `append` numbers from the entry's highest stored position + 1, across
   root and thread cards. Thread cards are numbered the same way.

The backend reader endpoint and `contentAt` stay as they are. A lookup by
`(entry, position)` already finds a thread card, behind the same subscription
check. `?link=<entry>.<position>` keeps its shape, so shared links stay stable.

Tests: a Jest test for `linkCardAt` covering root and thread cards; the thread
block's button emitting the reply card's stored position; a backend functional
test opening `/link-cards/{position}/reader` for a thread card; and a repository
test where root cards are appended after thread cards, with no collision and
contiguous numbering. One Playwright smoke (stubbed list and reader routes) opens
a reply card in the reader from the list and checks the layer shows that card.

## Errors and limits

- Network failure or a throttled host means no fold and a logged warning; the
  entry stays what it is today.
- A reply deleted later stays folded. Edits aren't followed: the entry is ingested
  once, as for the root.
- At most 3 replies, 1 thread call per post, and only for Bluesky posts with
  replies.

## Testing

- `SelfReplyChain`: stops at another author, at the cap, at a gap; picks the
  oldest of sibling self-replies.
- Each source, from recorded answers (the real Techpresso thread for Bluesky; a
  recorded Mastodon context with a stranger reply in the middle).
- `PostEnricher`: a post with `replyCount: 0` makes no thread call; a failed
  thread call leaves the root filled.
- The Mastodon pass: queue → claim → fold → hand-off to `LinkCards`.
- `SocialRepliesSource` drops folded replies and lifts their children.
- Functional: an RSS refresh against stubbed AppView/instance answers yields an
  entry whose `thread` JSON holds 2 posts and the MacRumors card.
- Frontend: Jest for the slot-fit rule, the block and the height estimate.
  Playwright smoke with a stubbed list route (it owns its data, per CLAUDE.md).
- Both DB legs, migrations on both dialects, `infection:diff`.
