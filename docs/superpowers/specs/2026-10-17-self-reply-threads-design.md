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
| Out of scope | Search over thread text; a quoted post inside a reply; account-timeline feeds (`SourceFormat::BLUESKY_API`, Mastodon home timelines), which already list each self-reply as its own entry; a reply's link that the network sent without a card (no page fetch for thread posts in v1). |

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

New entity **`ThreadPost`** (`thread_post`) in `App\Entity`, holding the replies only:

| Column | Type | Note |
|---|---|---|
| `id` | int | |
| `entry_id` | FK → entry, `ON DELETE CASCADE` | |
| `position` | smallint | 1..3, unique with `entry_id` |
| `url` | varchar(2048) | the reply on the web |
| `published_at` | datetime, naive UTC, nullable | |
| `content_html` | text | sanitized body, as the replies view (#1616) renders it |
| `media` | json, nullable | the reply's pictures and video, `EntryMedium` JSON as `entry.media` |

`link_card` gains a nullable **`thread_post_id`** (FK → thread_post,
`ON DELETE CASCADE`). A card with none belongs to the root, as today. A reply has
at most one card: the one card both networks give a post. The unique
`(entry_id, position)` stays, so an entry's cards, root and replies together,
keep one numbering.

A quoted post inside a reply isn't stored in v1. The reply's text and its link
still show.

`Entry` gets no collection. Thread posts load per page by entry id, as link
cards do. Migration on both dialects, timestamped after the ones #1666 adds
(`Version20261017160000`).

## Backend flow

The fold reuses what the article's replies view (#1616) already has: each
network's `ReplyThreadSourceInterface` fetches the public thread (`inPublic`)
and reads it into `ReplyNode` trees of `EntryCommentModel`, each marked
`byEntryAuthor`. No new network parsing.

- **`Support/SelfReplyChain`** (`Service/Social`): from the root's `ReplyNode`s,
  take the oldest node whose comment is `byEntryAuthor`, then the oldest such
  node among its replies, at most 3. This one place holds the chain rule for
  both networks.
- **`Factory/ThreadPostFactory`** builds the `ThreadPost` rows and their
  `LinkCard`s from the chain. **`SelfThreadFolder`** persists them, and sets
  `ShareKind::Link` when a reply carries a card and the entry shared nothing.
- **One queue kind for both networks**, `ShareEnrichmentKind::SelfThread`:
  - **Bluesky RSS:** the AppView pass (`PostEnricher::settle`) already gets each
    post's `replyCount`. A post with replies is not dequeued: its row turns into
    `SelfThread` (`ShareEnrichment::awaitSelfThread()`), as it turns into
    `LinkCards` today. `PostEnricher` (module `Bluesky`) never calls `Social`,
    because `Social` already depends on `Bluesky`.
  - **Mastodon RSS:** at ingest, `ShareEnrichmentQueue` queues `SelfThread` for
    a post on a Mastodon profile RSS feed. It asks a
    `Share/PendingThread/PendingThreadInterface`, implemented in `Social` by
    `MastodonPendingThread`, the way `PendingEmbedInterface` is implemented by
    `Bluesky`.
  - **The pass**, `Service/Social/SelfThreadEnricher`, claims a feed's
    `SelfThread` rows, folds each one, and hands the row to `LinkCards` when the
    root shares a link, or deletes it. It runs from `FeedOutcomePersister` after
    `PostEnricher::enrich`. It also runs from `EntryCompletion` through a
    `Completion/SelfThreadPass/SelfThreadPassInterface` that `Social` implements,
    since `Social` depends on `Completion` already.
  - A throttled network (`publicThreadThrottledForSeconds() > 0`, or
    `SocialNetworkThrottledException`) releases the row for later. Any other
    failure folds nothing and moves on. Rows give up after
    `APPVIEW_GIVE_UP_AFTER`, as AppView rows do.

### Search

Not in v1. `EntryIndexer` is a static mapping over `Entry`, and a reindex walks
every entry, so indexing thread text needs a batch lookup in both paths. The root
text stays indexed.

### Reader replies (#1616)

`SocialRepliesSource::read` drops the nodes whose URL is one of the entry's
thread posts, and lifts their children a level, so others' answers to reply 2/3
still show.

## API

`EntryJson` gains **`thread`**: a list, empty for nearly every entry, of

```json
{ "url": "https://bsky.app/…", "html": "<p>…</p>", "text": "plain snippet",
  "publishedAt": "2026-10-17T08:15:31Z", "media": [ /* as entry.media */ ],
  "linkCard": { /* LinkCardJson, or null */ } }
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

## Alongside #1666 and #1667

Both are being built now, in other sessions.

- **#1666 (saved feeds)** copies an entry's `link_card` rows when it saves a
  post (`INSERT … SELECT … FROM link_card`). With `thread_post_id` in place, a
  saved copy would turn a reply's card into a root card. Whichever branch lands
  second copies `thread_post` and maps `thread_post_id` in that copy. Both
  branches also add a last parameter to `EntryListRowEnrichment` and a key to
  `EntryJson`. That's a textual merge, resolved by whoever merges second. This
  branch leaves `Entry`, `EntryIndexer` and the backup walk alone, because #1666
  rewrites them.
- **#1667 (hidden Bluesky profiles)** reads a hidden profile through the
  account session (`getAuthorFeed`, `posts_and_author_threads`). That keeps each
  self-reply as its own entry, which is what the timeline feeds do. This branch
  doesn't touch `SourceFormat::BLUESKY_API` feeds, so the two don't collide. A
  hidden profile then lists self-replies separately while a public one shows a
  folded thread. Folding author feeds too is a follow-up.
