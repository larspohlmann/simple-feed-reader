# Stable Trending stories, story history and following a story (#2128)

Status: approved by Lars 2026-11-16 ("A, write specs and plan, consider it approved").

## Intent

Today a Trending story lives for one snapshot: every rebuild deletes a window's stories and inserts them again
(`TrendingSnapshotRepository::replaceStories`), so a story id lasts ten minutes (day) or an hour (week). A reader
cannot follow a developing event, nothing records its history, and hiding has to work through entries so that a
regrouped event stays hidden.

This change gives a story an identity that survives rebuilds, keeps its history after it leaves the window, lets a
reader follow it from the sidebar with a "new since you last opened it" count, and hides by story.

## Goals

1. **Stable identity.** A story keeps its id across rebuilds of both windows while it is the same event.
2. **History.** A story keeps every member it ever had, with the time each joined. It turns *quiet* instead of
   disappearing.
3. **Follow a story.** A reader follows a story from its Trending card. It gets a row in the sidebar with its
   headline and a count of what joined since they last opened it; opening it lists its entries.
4. **Hide by story id**, replacing `trending_hidden_entry`.

## Non-goals

- Catching later developments after a story has left the window, via AI or otherwise (the issue's phase 2). A story
  grows only through the normal rebuild: a new entry joins while it groups with a member still inside the 7-day
  window. After a week with nothing new it cannot gain members again. Lars agreed to this limit.
- Any change to how entries link (`StoryLinks`, `StoryGroups`, `TrendingGrouping`) or to source counting (#2056).
- Automatic expiry of a followed story.

## Anti-goals

- A story that flips ids, or flips between two ids, from one rebuild to the next while its event is unchanged.
- A followed story that silently drops out of the sidebar.
- A hidden story that comes back because the grouping changed underneath it.

## Decided behaviour (agreed with Lars in the conversation)

- **Same event:** after each rebuild, the new group that shares most members with a stored story keeps that story's
  id; follows, hides and "last opened" carry over.
- **Split (week build):** one story becomes two groups. The larger part keeps the id and the follow; the smaller part is a new
  story nobody follows yet.
- **Merge (week build):** two stories become one group. The merged story keeps the id of the larger one; follows and hides of
  both carry over; a reader who hid either one has the merged story hidden ("exclusion wins", as in #2202).
- **New** in the follow count means joined after the reader last opened the story, not published after it. An old
  article that a rebuild groups in only now counts as new.
- **Day and week are two views of one story.** A story followed in "24 h" is the same story in "7 days". D41 (week
  leaves out what the reader is shown in day) works on story ids.
- **Hidden stays hidden**, for as long as the story exists.
- **Sidebar:** a "Followed stories" section, like saved searches, each row with the story's headline and its new
  count; a quiet story stays, marked quiet, until the reader unfollows it.
- **Opening a followed story** lists its entries newest first, as a normal entry list (reader view, star, and so on),
  with a marker where the reader's last visit ended.
- **Follow** is a Trending card action next to "Hide story". Unfollow from the sidebar row's menu and from the
  followed story's list header.
- **Counting** follows the reader's Trending rules: only feeds they follow and include in Trending; an article once
  (`url_hash`), a social account once.

## Approaches considered

- **A. Match after grouping (chosen by Lars).** Grouping is unchanged; each new group is matched to stored stories
  by member overlap before saving. Stories persist; members accumulate with a join time. The linking rules stay free
  to improve, the matching is one testable step, and the read queries keep their shape. Costs an overlap threshold
  tuned against real builds, and an upsert in place of delete-and-insert.
- **B. Entries keep their first story forever.** Trivially stable, but an early wrong grouping is frozen and
  grouping improvements (stemming, equivalences, link cards) can never fix existing stories.
- **C. A separate events layer over the unchanged snapshots.** Touches least, but leaves two notions of "story" to
  keep in sync forever, and D41 has to translate between them.

## Design decisions made by Claude (the builder may refine; deviations get a ledger ruling)

### Who decides identity

The two windows group separately, with different link gaps and cadences (day every 10 min, week hourly). If both
could merge and split stories, a story could flip between builds. So:

- **The week build is the only one that merges.** It applies the split and merge rules above.
- **The day build only attaches.** Each day group takes the stored story it overlaps best (above the threshold),
  or a new story when none qualifies. A day group overlapping two stories attaches to the best one; it never merges
  them.
- A story created by a day build is matched like any other by the next week build.
- The day build may attach several groups to one story, so it never splits; only the week build is one-to-one.

### Matching

- **Overlap** between a new group G and a stored story S is the number of entries they share, divided by the size
  of the smaller of G and S's members that fall inside the build's window. (Members outside the window cannot be in
  G, so they must not dilute the score.)
- **Threshold:** a match needs overlap ≥ 0.5 and at least one shared entry. Tune the number against real builds,
  but keep it a named constant with its reason.
- **Assignment** is one-to-one per week build, greedy by overlap, highest first. Ties go to the story with more in-window
  members, then the older id. A group left without a story gets a new one.
- **Merge (week build only):** when a group's best story is X and another stored story Y also reaches the threshold
  against the same group but was not assigned to any group, Y merges into X: Y's members join X's history, follows
  and hides move to X (deduplicated, hidden wins, the earlier `last_opened_at` stays so nothing is lost), and Y is
  deleted.
- **Split** falls out of one-to-one assignment: the second group gets a new story.

### Storage

- `trending_story` persists. It loses its single `time_window` and gains what the windows need: which windows the
  latest builds placed it in (with each window's current members, so the read path keeps working per window), its
  `first_joined_at` and `last_joined_at`.
- Each member keeps `joined_at`: the build time it first joined this story. A member that leaves the window stays.
- The schema shape (one membership table with a window column plus a history table, or a single table with
  per-window flags) is the plan's call; what is fixed: the Trending read path filters by window exactly as today, and
  a story's full history is one query.
- **Migration:** today's rows become the seed stories, ids kept; their members get `joined_at` = the snapshot's
  `built_at`. Existing `trending_hidden_entry` rows become story hides for every current story holding a hidden
  entry; then the table goes. The migration must run on MySQL 8.0 (Strato) and SQLite; mind #2254 (an FK has to be
  dropped and re-added around a column change on MySQL 8.0).

### Lifetime

- **Quiet:** no member joined for 48 hours. A named constant.
- **Pruning:** a story no one follows is deleted (members and hides cascade) once none of its members falls inside
  the week window, because no future group can overlap it then. A followed story is never pruned. Pruning runs with
  the week build.
- Entries deleted by retention take their member rows with them (cascade); a followed story shows what remains.

### Per reader

- `trending_story_follow (user_id, story_id, followed_at, last_opened_at)`; `last_opened_at` starts at
  `followed_at`.
- `trending_story_hide (user_id, story_id, hidden_at)`.
- **New count** = the story's members joined after `last_opened_at`, counted by the reader's Trending rules.
- **Headline** of a followed story for a reader: the title of the newest member from an article feed (not a social
  network) the reader follows, else the newest member they can see. Computed at read time.
- **Opening** records `last_opened_at` through an explicit `POST`; no `GET` mutates.

### API (plain JSON, bearer auth, problem+json; native iOS stays viable, architecture.md §6)

- `GET /api/trending`: each story card gains `id` (story id) and `followed`.
- `POST /api/trending/hidden` takes `storyId` instead of `entryId` (+ `window` if still needed); the frontend
  changes with it.
- `PUT` / `DELETE /api/trending/stories/{id}/follow` → 204; following a story that does not exist → 404 problem.
- `GET /api/trending/followed` → the reader's followed stories: `id`, `headline`, `newCount`, `quiet`,
  `lastJoinedAt`, ordered by `lastJoinedAt` desc. The sidebar's count polling refreshes it alongside the other counts.
- `POST /api/trending/stories/{id}/opened` → 204, sets `last_opened_at`.
- A followed story's entries come from the normal entry list endpoint with a story filter (the saved-search list
  is the pattern to follow), keyset pagination as usual; each entry carries whether it joined after the visit, or the
  response carries the visit time, so the marker can be drawn.

### Frontend

- Sidebar section "Followed stories" under Trending, collapsible like saved searches, absent when empty. Row: headline
  (one line, ellipsis), new count flush right (sidebar counts style), a quiet marker. Row menu: Unfollow.
- New route selection kind for a followed story; its list header shows the headline and an Unfollow action.
- "Follow story" / "Unfollow story" on Trending cards, next to Hide.
- All strings in de, en, fr, es (French and Spanish are first-class).
- Follow `docs/design-language.md` for tokens and the shared components.

## Measuring

Before tuning is called done, run consecutive builds on the dev database (it is fine to rebuild Trending there;
never clear it) and report, per window: stories kept, created, merged and split per build, and the share of stories
whose id survived an unchanged-input rebuild (must be 100 %). A rebuild over unchanged input must change nothing.

## Left to the builder

Table and column names, the matching module's place under `Service/Curation/Trending`, the exact endpoints' DTOs and
`*Json` mappers, how the story filter plugs into the entry list, and the component structure, all within the
CLAUDE.md rules (PHPMD-clean touched files, no PHPStan ignores, Infection on changed files).
