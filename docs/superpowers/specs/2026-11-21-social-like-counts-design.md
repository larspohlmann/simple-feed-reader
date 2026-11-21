# Bluesky and Mastodon like counts on list rows (#2362) — design

## Intent

Follow-up to #2348 (reply counts). Social list rows and magazine cards show each
post's like count next to the comment bubble. Display only: it is not a like button
(the article view's `social-counts-row` keeps the live count and the button).

## Data structure (adjusted from the issue)

The issue predates #2404. A social post's counts now live once on `canonical_item`
(`score`, `comment_count`, `self_reply_count`), shared by every feed copy; the
`entry_discussion` embeddable only holds counts for entries without an item. Every
Bluesky and Mastodon entry gets an item, so:

- **New nullable column `canonical_item.like_count`**, not `entry.like_count`. A
  migration adds it on SQLite and MySQL; no backfill (counts arrive on refresh).
- `DiscussionCounts` carries a `likeCount`. `BlueskyTimelinePage` fills it from
  `likeCount`, `MastodonTimelinePage` from `favourites_count`; a boost reads its
  original post, like replies. Lemmy leaves it null.
- `CanonicalItem::recordCounts` keeps the stored like count when the new one is null,
  as for the other counts. `EntryDiscussion` ignores it. The stored-entry refresh
  updates it through the same path as the comment count.
- `EntryJson` sends `likeCount` (null when the entry has no item or no count).

## Frontend

`entry-meta` shows a `thumb_up` icon and the count in the `.discussion` group after
the comment bubble, `role="img"` with an aria label in en/de/fr/es. The group shows
when any of score, comments or likes is known.

## Approaches

- **`canonical_item.like_count`** — picked: one count per post, shared by all copies.
- Reuse `canonical_item.score` (only shown for Lemmy items now) — rejected: makes
  "score" mean two things.
- `entry.like_count` as the issue wrote — rejected: per-copy drift #2404 removed.

## Known limits

- Frozen at fetch time; changes on refresh only. Opening the article does not write
  the live count back.
- A post seen only through a Bluesky profile RSS feed carries no count. A copy that
  shares its item with a timeline copy does show it.
