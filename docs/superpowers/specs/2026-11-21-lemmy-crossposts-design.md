# Lemmy cross-posts: one list row per group (#2420)

## Intent

One author often posts the same link or text into several Lemmy communities: PerfectDark's "Before Rainbow Six…" went to games, retrogaming and technology@lemmy.world, which is 3 posts and 8 rows on the dev data. Lists flood with the same post. Each cross-post is still a real, separate Lemmy post with its own score, thread and vote. That is unlike #2404's canonical item, which joins copies of **one** post.

## Decided by Lars (2026-11-21)

- **Match:**
  - Link posts match on the same author and the same linked URL.
  - Text posts match on the same author, the same title and the same body.
  - No extra API calls, so Lemmy's `cross_posts` is not used.
- **Row:** a list view shows one row per cross-post group. The earliest-published post leads.
- **Pills:** the row carries "also in c/<community>" pills for the other cross-posts. Each pill shows that post's own score and comment count. A click opens that post in the reader, in-app.
- **Votes and thread:** they act on the post shown. Votes, likes and comments stay per post, so `buildItemKey` and the item fan-out are untouched.
- **Read state:** each cross-post keeps its own read state, with no propagation across a group. The group row counts as read when its leading post is read.
- **Trending:** a group counts once. It adds one source to a story, not one per community.
- **Shipping:** work autonomously, merge when green, then deploy to Strato.

## Constraints

- Lemmy entries have no `url_hash`: `asDiscussionThread` moves the page URL to `discussion.url`.
- The linked URL is stored nowhere. Today it is only the first anchor in `content_html`. Both ingestion paths, the Lemmy API and Lemmy RSS, must produce the same key for the same post.
- The author identity is `author_url`, the actor URL. The display name differs between the API (`display_name`) and RSS (the username).
- Lists may hold only one row per group, but a page must still hold `limit` rows. The existing collapse is a per-key `NOT EXISTS earlier copy` semi-join (`DuplicateCollapseDql`). One semi-join per key: on MySQL, an `OR` of keys loses the indexes.
- The migration backfill must be join-based or batched. A correlated per-row UPDATE once took 75 min.
- All CLAUDE.md rules apply: naming, PHPMD, module cycles, queries in `src/Repository`.

## Approaches considered

1. **A group key on `canonical_item` (chosen).**
   - A nullable, indexed `cross_post_hash` column, computed at ingestion from the parsed post and kind-neutral in the shared layer.
   - A tagged rule interface, like the canonical key rules, lets Lemmy supply it.
   - Collapse adds a third semi-join; Trending adds a third no-earlier-copy probe and story bucket.
   - Joins are cheap, because every entry already joins its item.
2. **The group key on `lemmy_post`.** That keeps Lemmy data in the side table, but every collapse and Trending probe would need an extra unmapped join. Rejected on cost.
3. **A separate `cross_post_group` row, many items to one group.** It is more normalised, but needs another table and FK for no behaviour a hash column lacks. Rejected (YAGNI).

## Design

- **Linked URL:** `ParsedEntryModel` gains `linkedUrl`. The API path sets it from `post.url`; the RSS path sets it from the post's first link, which the header parser already sees. Both set it before `asDiscussionThread`.
- **Key building:**
  - The key is built by a static `Support/` helper, so the migration can reuse it.
  - Link posts hash the normalised `author_url` plus the normalised linked URL (`UrlNormalizer`).
  - Text posts hash the normalised `author_url`, the normalised title, and the body reduced to whitespace-collapsed plain text. API commonmark HTML and Lemmy-rendered RSS HTML differ, so only the text counts. A mismatch only means no grouping, which is safe.
  - The key is null when the author or both link and text are missing.
- **Collapse:**
  - A third semi-join on `canonicalItem.crossPostHash` hides every group member that has an earlier member in scope. "Earlier" means an earlier `published_at`, with the entry id as tiebreak.
  - Its scope leaves out the read-state filter. Otherwise, in an unread view, reading the leader would surface the next member as a new unread row, against the rule that the group row is read when its leader is read.
- **Pills:**
  - Hidden members are attached to the leader the way duplicates are today (`attachDuplicates` → `duplicates`), each a full list row carrying its own `community`, score, comment count and `myVote`. That costs one extra query per page and no N+1.
  - The SPA's existing "Also in …" chips (`entry-duplicates`) label a Lemmy cross-post `c/<community>` and show its score and comment count.
  - A click opens that post in the reader. Other duplicates keep the overlay.
- **Trending:**
  - The story bucketing (`TrendingRun`) groups by the key too.
  - `TrendingStoryRepository` adds a no-earlier-group-member probe beside the article and item probes, and `buildMemberCount` counts a group once.
- **Backfill:** a migration reads Lemmy items' leading entries in batches and computes the key in PHP with the same Support helper. It then writes batched UPDATEs keyed by id. The ORM mapping and index come in the same migration, for both dialects.
- **Unread counts:** they stay per entry. That is consistent with "each keeps its own read state".

## Left to the builder

The exact names; whether the probe "scope without read state" is a variant of the scope callback or a separate alias set; the batch size of the backfill; and the pill markup within the design language (`docs/design-language.md`).
