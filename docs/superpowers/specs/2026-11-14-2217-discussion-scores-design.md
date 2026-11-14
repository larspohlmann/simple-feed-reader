# #2217 — Lemmy post and comment scores — design

## Intent

Lemmy feed items already carry `N points | N comments` in their header, and the
Lemmy comment API answer carries each comment's score. #2216 parses the header and
throws the numbers away. Keep them and show them, without one extra request.

The badges are built so #2218 (connect a Lemmy account; vote and reply) only has to
make them interactive: the up/down arrows render now, inert, in exactly the shape
#2218 will turn into buttons.

## Goals

- Store a Lemmy post's score and comment count on the entry; refresh both on every
  feed fetch.
- Show them on list rows, magazine cards and in the article view (reader and link
  reader).
- Show each Lemmy comment's score in the comments panel.

## Non-goals

- Voting, replying, the reader's own vote state (#2218).
- Hacker News points: HN has no downvote, so the two-arrow pill would mislead; HN
  stays as it is.
- Reddit (its RSS has no score), Substack comments, comment feeds, Mastodon/Bluesky
  replies: unchanged.
- Restyling the Mastodon/Bluesky counts row.

## Look (agreed in the visual round)

- **Vote pill**: up arrow, score, down arrow (`arrow_upward` / `arrow_downward`),
  tabular muted number. Arrows inert and dimmed (opacity of the pill-toggle's
  `aria-disabled` state). Two variants:
  - *framed*: the pill-toggle ring (`--border-strong`), `--space-1 --space-2`
    padding per arrow half;
  - *bare*: no ring, tight padding, for a text line.
- **List rows and magazine cards**: the footer becomes one line — framed vote pill
  and comment count (`chat_bubble` + number) on the left, then the tag pills,
  actions on the right. No extra line per post.
- **Article view**: framed vote pill in the header row, before the tag pills. The
  comment count goes into the existing meta-line *Discussion (N)* link, which today
  shows its number only after the comments load; it now falls back to the stored
  count. No second comment badge.
- **Comments panel**: bare vote pill flush right in each Lemmy comment's byline.
- An entry or comment without a score shows nothing; an entry with a score but no
  comment count shows the vote pill alone.
- Screen readers: the pill reads as one image labelled "N points" (Transloco, all
  shipped languages, plural forms); the arrows are `aria-hidden` until #2218 makes
  them buttons.

## Approaches

1. **One shared `VotePill` component** (chosen). Inputs: `score`, a `bare`
   variant. #2218 adds the reader's vote and two outputs, and swaps the arrow spans
   for buttons, in this one component; the three places that host it stay put.
2. *Lemmy as a fourth network in the social counts row* — rejected: that row
   assumes a connected account and a social-post DTO; Lemmy posts come from an
   ordinary feed, so it would need shims now that #2218's account model would fight.

## Backend

- `Entry`: two nullable int columns, `score` and `comment_count`; migration for
  SQLite and MySQL (verified by the CI migrate-from-empty leg).
- `LemmyPostHeader` reads the two numbers (the `N points |` text and the
  `N comments` link) onto `LemmyFeedPostModel`; the Lemmy entry rule puts them on
  the parsed entry.
- Refresh: on every feed fetch, already-stored entries matched by guid get the new
  numbers, alongside `EntryIngestor::fillMissingImages` (same lookup, one pass).
- Entry JSON: `score`, `commentCount` (null when unknown).
- `EntryCommentModel::$score` (`?int`), filled from `counts.score` by `LemmyThread`,
  emitted as `score` by `CommentsJson`; null for every other source.

## Left to the builder

- Exact name of the refresh step and whether it shares the image backfill's loop.
- Number formatting: reuse the counts row's compact formatter.
- Where the shared component lives (`reader/` vs `shared/`), per the design
  language's catalog rules.
