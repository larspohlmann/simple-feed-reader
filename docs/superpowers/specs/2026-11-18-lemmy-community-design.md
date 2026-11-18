# Lemmy community on Lemmy posts, and a home for Lemmy-only data (#2347)

## Intent

In a combined Lemmy timeline (Subscribed, Local, All, Lemmy searches) and in a Lemmy user feed, a post shows only its
poster, so the reader cannot tell which community it was posted to. Show the community next to the poster in list
rows, magazine kickers and the article byline. Lemmy-only facts about an entry get their own table instead of columns
on `entry`.

## Goals / non-goals

- Goal: a new table `entry_lemmy_post` holds the Lemmy-only facts of an entry: its community and its `score`.
- Goal: every Lemmy post names its community as `c/<name>`, except in the feed of that same community.
- Goal: `entry.score` moves to `entry_lemmy_post`, existing values included (Strato holds them).
- Non-goal: backfilling the community of posts ingested before the change; their row has no community.
- Non-goal: `comment_count` stays on `entry` as the network-neutral count; filling it for Bluesky and Mastodon is
  #2348.
- Anti-goal: Lemmy-specific columns on `entry`.

## Approaches

- **Side table `entry_lemmy_post` (picked by Lars).** One row per Lemmy post entry; keeps `entry` network-neutral and
  is where later Lemmy-only fields go.
- Columns on `entry`: cheapest, but Lemmy-only data on every entry. Rejected by Lars.
- Read the community off the post URL: the URL does not carry it. Not possible.

## Decisions

- Only `score` moves. `discussion_url`, `comments_feed_url`, `comments_load` serve HN/Reddit-style threads too,
  `self_reply_count` serves Bluesky/Mastodon threads, `comment_count` becomes network-neutral (#2348), and votes
  already live in `discussion_vote`.
- The community shows on every Lemmy post, timelines, searches and RSS user feeds alike, and is hidden only when the
  entry's feed is that community's own feed.
- The label is the community's short name, `c/technology`, not its display title (titles repeat across instances).
- In the article byline it links to the community through `OwnInstanceLinks` (opens on the reader's own instance, as
  the poster link does); in rows and kickers it is plain text, as the poster is.

## Design

- **Entity `App\Entity\EntryLemmyPost`**, table `entry_lemmy_post`, modelled like `EntryTranscript`: owned from its
  side (`OneToOne` to `Entry`, unique, `onDelete: CASCADE`), never mapped on `Entry`. Columns: `score` (nullable
  int), `community_name` (lower-cased `name@host` identity, nullable), `community_url` (the community's address,
  nullable).
- **Ingest, API:** `LemmyPostEntries` reads `community.name` / `community.actor_id` (identity as
  `LemmyCommunity::identityOf`) and carries it on `ParsedEntryModel` next to the counts it already sets.
- **Ingest, RSS:** `LemmyPostHeader` already matches the `to /c/…` community link; it returns the community too and
  `LemmyEntryRule` carries it the same way.
- **Persist:** a writer next to `EntryTranscriptWriter`, called from `EntryIngestor` for new entries, creates the row
  when the parse carries a score or a community. `refreshStoredEntries` updates the stored row's score (and creates
  the row when missing); `Entry` keeps recording `comment_count` only.
- **Read:** `EntryListRowEnricher` loads a page's rows in one repository query. `EntryJson` reads `score` from it and
  adds `community: {name, url} | null`, null when the row has none or the entry's feed is that community's feed
  (feed identity from the feed URL, as `ProfileFeedUrl` parses it).
- **Migrations, MySQL and SQLite:** create `entry_lemmy_post`, copy every non-null `entry.score` into a row, drop
  `entry.score`. The SQLite rebuild of `entry` drops the FTS triggers: run `EntrySearchIndex::install()` in the same
  migration.
- **Frontend:** `app-entry-source` renders ` · c/<name>` after the poster; `EntryDto` gains `community`.

## Left to the builder

Model and repository names, how `DiscussionCounts` splits between `entry` and the new row, test layout, and the SCSS
for the community segment (design-language tokens).
