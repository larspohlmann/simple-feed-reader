# Canonical items: one row behind every feed's copy (#2404)

## Intent

The same thing reached through two of your feeds is stored once per feed, and every interaction (score,
comment count, your vote, your like, read state, the thread) is kept per copy, so the copies drift apart. Story
285632 on Strato holds entries 1617614 and 1617690, both `https://lemmy.world/post/54626839`, with scores 3 and 2.

#2404 gives such copies one shared row. This design keeps that scope and makes the mechanism kind-neutral: the
row is a **canonical item**, not a social post, so a future feed kind whose entries can arrive through more than
one feed plugs in with one class.

**What counts as a duplicate:** the *same item* reached through two feeds — one Lemmy post through Local and All,
one Bluesky post through the timeline and an RSS feed, one Mastodon status through two timelines or a timeline and
RSS. Two different posts that share the same article link are **not** duplicates and are never tied together.

## Goals

- Copies of one canonical item show as one row in every mixed list and count once in Trending.
- Score, comment count, self-reply count, your vote, your like and the comment thread are identical whichever copy
  you open, also right after you act on one copy (no reload).
- Read and unread cover every copy, from every surface: the single toggle, scroll-past, and every mark-all-read
  (feed, folder, all, network/group, saved search, search, For you, Trending story).
- Each feed's unread count stays right: read state stays stored per entry.
- A new feed kind gets all of this by implementing one interface.

## Non-goals

- RSS articles. An entry no rule claims keeps `canonical_item_id = NULL`; articles keep collapsing by `url_hash`
  exactly as today, including `EntryStateUpdater::mirror()`'s url_hash sibling mirror for the single toggle.
- Link cards, thread posts, transcripts and share enrichment stay per entry (later issue, as #2404 says).
- No API route changes: routes stay `/entries/{id}/…` and the server resolves entry → canonical item.

## Anti-goals

- Anything named after social media in the shared layer (`post`, `network` as the column name, `SocialNetwork`
  as the column type). Social words belong in the social modules that implement the rule.
- Tying two different items together because they link the same URL.
- A per-kind `if`/`match` in the shared code: collapse, read propagation, votes, Trending and JSON never ask the kind.

## Approaches considered

1. **A generic identity row filled by per-kind rules (picked).** A `canonical_item` table, `entry.canonical_item_id`,
   and a tagged interface one class per feed kind implements. Every interaction relates to the row.
2. **A stored identity hash on the entry, no table.** Like `url_hash`, filled only by the per-kind rules. Collapse,
   read state and Trending would work, but score, counts, votes and likes would have no single home — keyed by a
   bare hash or still copied per entry, which is the drift this fixes. Dropped.
3. **#2404's `social_post`, renamed later.** Cheapest now, but the name would spread through queries, DTOs and SPA
   keys, and a non-social kind would mean a stack-wide rename plus a migration. Dropped.

Picked by Lars: 1, named `canonical_item`.

## Design

### Schema

- **`canonical_item`**: `id`; `kind` (`App\Enum\CanonicalItemKind`, string-backed: `bluesky`, `mastodon`, `lemmy`
  today); `canonical_key` (the key string); `key_hash` (hash of the key, unique together with `kind`); and the
  item-level facts `score` (nullable), `comment_count`, `self_reply_count`.
- **`entry.canonical_item_id`**: nullable FK to `canonical_item`, index `(canonical_item_id, id)`.
- **`lemmy_post`** replaces `entry_lemmy_post`, keyed on `canonical_item` (Lemmy-only facts in a side table, as
  settled in #2347). Its `score` moves to `canonical_item.score`.
- **`discussion_vote`** becomes unique on `(user_id, canonical_item_id)`; **`social_like`** likewise. (Likes have
  the same per-copy drift as votes; #2404 did not name them.)
- **The acting account (Lars, after the builder check):** a like or vote stays one row per user and item, with
  the `social_account_id` that made it. Once it exists, every copy acts through that account — unlike, changing
  or removing the vote, and the network sync (`SocialLikes::syncFromNetwork`, `DiscussionVotes::syncFromNetwork`)
  — whichever copy is open. Only while none exists does `ActingAccountChooser` pick per copy, as today. A sync
  through another account never drops the row. The migration's "keep the latest" merge keeps that row's account.
- `CanonicalItemKind` lives in `App\Enum` because an entity stores it. Mapping `SocialNetwork` ↔ `CanonicalItemKind`
  is the social module's job.

### The rule

A tagged interface, one implementation per kind, in the pattern of `EntryIdentityUrlInterface` /
`FeedBodyParser`'s keyed locator: given an entry, return the canonical key (kind + key string) or null. The shared
layer owns the interface; the social modules implement it, so no module cycle forms.

Per kind today:

- **Bluesky:** the AT URI (the guid). Timeline and RSS copies already share it.
- **Mastodon:** `MastodonStatusModel::uri()` — the `https://host/users/x/statuses/123` form — reached through
  `MastodonStatusAddress::parseAddress()` from either the timeline guid (status `uri`) or the RSS guid/url
  (`https://host/@x/123`). Both forms become one key.
- **Lemmy:** the post's `ap_id`, which is the entry's guid (`LemmyPostEntries::buildPostEntry`). Note:
  `LemmyPostAddressRule::findPost()` returns null today and `findDiscussionPost()` only answers for some entries,
  so the Lemmy key does **not** come from `PostAddressRuleInterface`; the new rule reads the guid.
- **Lemmy RSS copies** (community RSS feeds, `LemmyEntryRule`) carry the post page on the feed's own instance,
  not the `ap_id`. They get an item too, keyed by that page URL in the same normalised form as an `ap_id`, so an
  RSS copy joins the API copies whenever its page is the post's home, and is its own item otherwise (Lars, after
  the builder check). Every entry that can be voted on or liked therefore has an item.

### Ingestion

- A writer next to `EntryLemmyPostWriter`, called from `EntryIngestor::ingest` (attach, new entries) and
  `StoredEntryRefresher` (refresh, refetched entries), finds or creates the canonical item by
  `(kind, key_hash)` and links the entry.
- The newest fetch wins for `score`, `comment_count`, `self_reply_count`, written to the canonical item.
  `EntryLemmyPost::recordScore`'s "a null score keeps the old one" rule carries over to the item.
- `Entry::recordDiscussionCounts` and `Entry::countSelfReplies` write to the canonical item when the entry has one;
  the entry's own `EntryDiscussion` copy stays only for entries without one (articles with comment counts).
- Two copies of a new item can arrive in one refresh run, in one flush: find-or-create must not create two rows for
  one key (unique constraint must not fire).

### Read state

- Central point: `EntryReadMarker` and `EntryStateUpdater`.
- **Single toggle** (`EntryStateUpdater::mirror`): siblings are the user's entries with the same
  `canonical_item_id` when set, else the same `url_hash` (today's behaviour). Mark unread (API `isRead:false`)
  mirrors too.
- **Id batches** (`markEntriesRead`: scroll-past, For you, saved search, search, Trending story): expand the ids to
  every copy the user can see before marking.
- **Watermark sweeps** (`markSubscriptionsReadUntil`: feed, tag, all, network/group): the watermark only covers the
  swept feeds, and copies in other feeds have no `entry_state` row. After the sweep, find the user's copies in
  *other* subscriptions of canonical items that have an entry in the swept feeds up to `until`, and mark them by id
  (`hideUnreadAmong` + `createMissing`). One query, batched like `markEntriesRead`.
- **A copy that arrives later** (Lars, after the builder check): when ingestion links a new entry to an item
  that a subscriber of the entry's feed has already read through another copy, that subscriber's state for the
  new entry starts read (hidden; not viewed). One set-based write per ingest batch, not a query per subscriber.
- Unread counts are unchanged (`UnreadCountRepository::perSubscription` counts per feed).

### Lists and Trending

- `DuplicateCollapseDql` also hides an entry when an earlier entry in scope has the same `canonical_item_id`
  (`e.location.urlHash` OR `e.canonicalItem`), served by the new index.
- Trending counts a canonical item once: story member counts and `noEarlierCopy` dedupe on `canonical_item_id`
  alongside the article-feed `url_hash` dedupe, and `TrendingRun` groups copies of one item together.

### API and SPA

- Entry JSON gains `canonicalItemId` (nullable). `discussion.score`, `discussion.commentCount`, `myVote` and the
  like state come from the canonical item when present. Vote and like endpoints resolve entry → item.
- SPA: `CommentsService`, `SocialCountsService`, `PostActionsService` and `EntryRowCache`'s vote/like patch key by
  `canonicalItemId`, falling back to the entry id for entries without one, so an action on copy A updates every
  loaded row of copy B. The server-side comments cache is already keyed by post address and stays.
- Native iOS client: one additive nullable field, no route or auth change; passes the architecture §6 checklist.

### Migration (SQLite and MySQL, `upMySql`/`upSqlite`/`down…` as house style)

- Create `canonical_item`, add `entry.canonical_item_id` + index, create `lemmy_post`.
- Backfill from existing network entries grouped by canonical key; take the newest entry's score and counts.
- Move votes and likes onto the item; where one user voted (or liked) two copies, keep the latest.
- Move `entry_lemmy_post` rows to `lemmy_post`; drop `entry_lemmy_post`.
- Migrations run on deploy, so the backfill runs in the migration, on both dialects, and is checked by the CI
  migrate-from-empty leg plus a test fixture with real copies (one Lemmy pair, one Mastodon timeline+RSS pair).

### Backup

- `id`s, votes, likes, `entry_lemmy_post` and discussion counts are already outside the backup
  (`docs/backup.md`), so no mapping is needed. Declare `canonical_item` and `entry.canonical_item_id` as skipped
  in `BackupSchemaCoverageTest` and `docs/backup.md` §6.
- Restore (`EntryBatchInserter`, native SQL) must link restored network entries to canonical items, or restored
  copies stop collapsing and sharing read state.

### Retention

When the last entry of a canonical item is pruned, the item (and its votes, likes, `lemmy_post`) goes too.

## Done when

- Story 285632's two copies show as one row in Trending and in every mixed list, and count once in the story.
- Marking either copy read, by hand or by any mark-all-read, leaves no copy of that post unread in any feed;
  marking unread does the same. A copy fetched after the post was read arrives read.
- Score, comment count, your vote, your like and the comment thread are identical whichever copy you open.
- A Mastodon status seen through a timeline and an RSS feed is one canonical item.
- Nothing in the shared layer names a social network.

## Left to the builder

- Module placement of the interface, the writer and the item repository (no service-module cycle; lower module
  owns the interface).
- The key hash function (reuse the url normaliser's hash or a plain sha1 of the key).
- How the watermark-sweep copy query and the migration backfill are written per dialect.
- Whether Bluesky's `EntryIdentityUrl` stays (it keeps feeding `url_hash`; nothing here needs it removed).
