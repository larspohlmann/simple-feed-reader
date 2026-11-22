# Likes and upvotes count for For you (#2468) — design

## Intent

A post the reader likes (Bluesky, Mastodon) or upvotes (Lemmy) says something about
their taste. The For you reading profile is distilled from four history sections —
FAVORITES, SHARED, KEPT, VIEWED — and never sees those likes, although they are
already stored per user (`social_like`, `discussion_vote`). They become a fifth
section.

## Goals

- A LIKED section in the reading history: liked and upvoted posts together, newest
  first by when the like or vote was cast.
- Weight after SHARED and before KEPT. An entry appears in its highest section only:
  favorites beat shared, shared beats liked, liked beats kept, kept beats viewed.
- A new or withdrawn like changes the profile fingerprint, so a scheduled profile
  run is due again.

## Non-goals

- Downvotes. On Lemmy a downvote often means "I disagree", not "less of this topic".
- Votes on comments.
- Ranking candidates by how many likes a post has.
- A setting, a cap control or a "Liked" list in the sidebar.
- Protecting liked entries from retention. A like whose entry is gone has no line.

## Design

- **Query** — `ReadingHistoryRepository::liked(int $userId, int $cap)`: entries in
  still-subscribed feeds whose canonical item carries the user's `social_like`, or
  their `discussion_vote` with `VoteScore::Up`. One line per liked item, also when
  several subscribed feeds carry a copy of it; which copy stands for it is
  deterministic. Not a favorite, not shared. Ordered by `likedAt` / `votedAt`
  descending across both tables, ties broken by the newest entry, as the other
  sections do.
- **Lower sections** — `kept()` and `viewed()` leave out liked entries, the way they
  leave out shared ones today (`EntryShareDql::exists`).
- **Cap** — `RecommendationHistoryCaps::LIKED_CAP = 40`, fixed like `SHARED_CAP`.
- **Model** — `RecommendationHistoryModel` gains `liked`; `isEmpty()` counts it, so a
  reader with nothing but likes gets a profile.
- **Prompt** — the distill call renders `LIKED (newest first):` between SHARED and
  KEPT. `DISTILL_ROLE` names five sections, says LIKED holds the posts the reader
  liked or upvoted on their social network, and orders the weights: SAVED SEARCHES
  and FAVORITES strongest, SHARED next, then LIKED, then KEPT, VIEWED least. A liked
  post's line carries its `shares:` article like every other history line.
- **Fingerprint** — `ProfileInputFingerprint` adds the liked entry ids and the cap.
- **Scoring (Jev) and the batch and consolidation calls** — unchanged: they see the
  profile and FAVORITES, as today.
- **Docs** — `docs/features.md` ("what you favorite, keep, and read") and the profile
  paragraph in `docs/recommendations-runs.md` name likes and upvotes.

## Approaches

- **A LIKED section of its own** — picked. It follows the path SHARED took in #2007,
  and the model is told how much a like weighs against a share or a keep.
- Fold likes into KEPT or SHARED — rejected: no new section, but a like would weigh
  the same as a deliberate share or keep, and KEPT's cap is a user setting that likes
  would eat into.
- Treat a like as a favorite — rejected: favorites reach every scoring call and the
  Favorites list; a like on a network is a lighter act.

## Calls made while writing this

- Likes in a network switched out of For you (#1995) still shape the profile. The
  other history sections ignore that switch too; it filters candidates, not taste.
- The fingerprint gains a key, so every profile is "changed" once after the deploy:
  each scheduled profile runs one extra time. #2007 had the same effect.
- A like made on the network itself counts once the app has mirrored it, which
  happens when the post is opened.

## Verification

- Repository tests on SQLite and MySQL: a liked post and an upvoted post land in
  LIKED in cast order; a downvote, a favorite, a shared entry and an unsubscribed
  feed do not; a liked entry leaves KEPT and VIEWED; two copies of one item give one
  line; the cap holds across both tables.
- A real profile run against the configured provider: its debug log shows the LIKED
  section, and the run completes.

## Left to the builder

- Whether the liked check is a shared DQL fragment like `EntryShareDql` and how the
  two tables are merged into one ordered, capped read.
- Which copy of an item stands for it, as long as it is deterministic.
