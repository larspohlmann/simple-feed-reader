# Thumbs-up on every article (#2587)

## Intent

Every article carries the thumbs-up. Where the reader is connected to the post's network the like
goes there as today; anywhere else (feed articles, posts on networks the reader has not connected)
it is kept locally and counts as a like for recommendations.

## Goals / non-goals

- Goal: one toggle on every entry; local likes feed the recommendation history (profile, prompt
  LIKED section, candidate exclusion) exactly like network likes.
- Non-goal: syncing local likes to a network the reader connects later — a local like stays local.
- Non-goal: a local like count. A local like never changes the network count shown.
- Anti-goal: a reader with a connected account getting a silent local like instead of the network
  like.

## Approaches

1. **`likedAt` on `EntryState`** (picked). Sits beside favorite/kept, so backups, the saved-copy
   move and per-user scoping come for free. Keyed per entry: feed articles have no
   `CanonicalItem`, so a per-item key is not available.
2. A separate `entry_like` table. Same data, but one more thing backups and `SavedStateMover`
   must learn.
3. Make `SocialLike`'s account and remote id nullable. Articles have no canonical item, so it
   cannot hold them; it would also blur a network-mirrored record with local state.

## Decided

- `PUT`/`DELETE /api/entries/{id}/like` decide server-side: network like when the reader can like
  the post on its network (the existing `canLike`), local like otherwise.
- The entry payload exposes the local like (`isLiked`); the social `isLiked` stays as it is.
- `ReadingHistoryRepository::liked()` and `EntryLikeDql` include local likes; the prompt text says
  "liked" instead of "liked or upvoted on a network".
- `app-entry-like` always renders a toggle; for a local like the icon fills, a network count, if
  any, is shown unchanged.
- Calls I made: posts on unconnected networks get a local like; a local like stays local after
  connecting.

## Left to the builder

Migration for both SQLite and MySQL, backup export/restore of the field, the exact DTO field
name, the translation keys (en/fr/es) for the local like/unlike hints.
