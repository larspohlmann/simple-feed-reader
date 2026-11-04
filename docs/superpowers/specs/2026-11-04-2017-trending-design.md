# Trending (#2017) — design

Brainstormed 2026-11-04, after a look at NewsNest's story grouping (`newsnest/grouping.py`, `events.py`). No AI
in this issue; the design keeps a slot for an AI signal.

## What

A **Trending** item in the sidebar, directly above For you, lists the stories that several of the reader's
subscribed feeds covered in the past **24 hours** or **7 days**, one card per story, ranked by how many of
those feeds ran it. A switch in the list header picks the window.

## Decisions

1. **Trending means coverage.** A story trends for a reader when at least *N* distinct feeds that reader
   subscribes to took part in it: 3 in the day window, 5 in the week window. Not term bursts, not
   instance-wide counts.
2. **Every subscription counts, only articles show.** Social accounts add to the outlet count; the card
   displays only entries of non-social feeds (`feed.network IS NULL`). YouTube and podcast feeds are ordinary
   feeds here: their episodes carry real headlines and show like articles. A story with no displayable entry
   is left out.
   **Amended 2026-11-04:** a story shows only when at least **two** article feeds the reader follows cover it,
   so a card is never a lone article whose other sources are invisible social posts.
3. **Reposts count.** Three followed accounts reposting one post are three outlets: reposts are attention.
4. **One snapshot per window, rebuilt in the background.** Grouping runs once for the whole instance over the
   window's entries and is stored; reading Trending is one query. Entries belong to feeds, not users, so the
   grouping is the same for everyone and only the threshold is per reader.
5. **The windows are separate groupings.** A week grouping filtered to 24 h would inherit the week's merge
   errors, so each window builds its own snapshot with its own rules (decision 11). Fallback, only if the
   week build does not fit Strato's tick: build the week alone and derive the day view from it.
6. **No stable story ids.** A rebuild throws the old snapshot away. Read state stays where it lives: a story is
   *viewed* when any of its entries, social ones included, has `entry_state.is_viewed`.
7. **Cards stay.** Trending is a picture of what the news is about, not an inbox: a viewed story keeps its place
   with the normal read-state mark only. **Amended 2026-11-04:** no dimming (it did not look good). No unread
   switch and no mark-all-read in the Trending header.
8. **The badge counts unopened stories** in the selected window.
9. **The window is a device setting**, stored per account like the unread switch:
   `sfr.user.<userId>.trending-window`, default `day`.
10. **No matching across languages.** A German and an English report of one event stay two stories until an AI
    signal exists.

## Matching

11. **`TrendingWindow` enum** (`day`, `week`) owns every number, so tuning touches one file:

    | | day | week |
    |---|---|---|
    | Span | 24 h | 7 days |
    | Rebuild when older than | 10 min | 1 h |
    | Max gap between two linked entries | 36 h | 48 h |
    | Min distinct subscribed feeds | 3 | 5 |

    The week window sees only what retention keeps; social entries default to 7 days, so they thin out at its
    far end. The gap is what keeps the week clean: a story chains across days through its follow-ups, but a Monday and a
    Friday item never link just for sharing a name.
12. **Text of an entry.** Headline: the title, derived or not. Body: the summary with HTML stripped, minus a
    leading repeat of the title (social posts, where `title_derived` is set), cut at a word boundary within 300 characters (`EntryPlainText` + `WordBoundary`, which also drop junk bodies such as "none").
13. **Stems.** Casefold, strip diacritics (`Normalizer` NFKD), split on non-alphanumerics, drop words under 3
    letters, keep numbers of 2+ digits, cut every word to its first 6 characters. Headline stems weigh 1.0,
    body stems 0.5; a stem in both counts once, at 1.0. German compounds do not split; accepted.
14. **Filler is ignored**, three ways: a short English and German seed list of function words; a stem in at
    least 3 % of the window's entries (counted against at least 1,500, so a quiet window behaves like a busy
    one); and a stem in at least half of one feed's window entries, for feeds with at least 4, ignored for that
    feed only — this catches "ots", a columnist's name in every headline, "Reposted by".
15. **Rarity weight** of a stem: `ln((N + 2) / (df + 1))`, with *N* the window's entry count (floored at 1,500)
    and *df* the entries holding it.
16. **When two entries link.** Different feeds, published within the window's gap, and either:
    - **the same URL** — the entry's link, or the URL of the article a post shares, has the same `url_hash`; or
    - **headline overlap** — at least one shared *headline* stem, a shared weight of at least 14 (about three
      ordinary stems or two rare ones), and a shared weight of at least 40 % of the lighter entry's total.
17. **Grouping resists chaining.** Collect the linked pairs, strongest first (shared weight; a URL link ranks
    above all; ties by entry id). Two ungrouped entries open a group; an entry joins a group only if it links
    to at least half of the group's members (the 8 newest, for a bigger group); two groups merge only if links
    between them are as broad. The result does not depend on arrival order, and one weak bridge cannot glue two
    stories.
18. **Links come from `StoryLinkSourceInterface`** implementations, a tag plus keyed locator in the pattern of
    `Service/Refresh/FeedBodyParser`: `SharedUrlLinks` and `HeadlineLinks` now. A later `AiEventLabelLinks`
    adds links for entries the others leave alone, using the existing provider plumbing, without touching the
    grouping step.

## Backend

19. **Module `Service/Trending`**, by role: `TrendingRebuild` and `TrendingSnapshotBuilder` (root, the job),
    `TrendingStories` (root, the per-reader read), `Pass/HeadlineWindow` (per-run inverted index and document
    frequencies), `Support/HeadlineStems` (static tokenizer), `Model/` for the story, link and report shapes,
    `StoryLinkSourceInterface/` with its two implementations. It depends on no `Recommendation` or `Search`
    class.
20. **Tables**, one migration for SQLite and MySQL:
    - `trending_snapshot`: `time_window` (PK, the enum), `built_at`, `entry_count`, `story_count`,
      `duration_ms`, `peak_memory_bytes`.
    - `trending_story`: `id`, `time_window`, `first_seen`, `last_seen`, `outlet_count` (instance-wide distinct
      feeds, for logs); index `(time_window, outlet_count)`.
    - `trending_story_entry`: `story_id`, `entry_id`, `feed_id` (copied from the entry, so the per-reader count
      needs no join through `entry`); PK `(story_id, entry_id)`, index on `feed_id`; both foreign keys
      `ON DELETE CASCADE`, so retention purging an entry removes its link.
21. **A rebuild replaces one window in one transaction**: delete the window's stories, insert the new ones,
    update its `trending_snapshot` row. Readers see the old snapshot until the commit; a week rebuild never
    touches the day snapshot.
22. **Memory.** The builder streams the window's entries in id order and turns each into integer stem ids at
    once; the index holds ids and counts, never the texts. `peak_memory_bytes` and `duration_ms` are recorded
    per build.
23. **`TrendingRebuild::rebuildDue()`** rebuilds every window whose snapshot is older than its interval. Two
    callers:
    - Docker: a property-less worker message `RebuildDueTrendingStories`, every 5 minutes, added **last** in
      `WorkerSchedule` (its checkpoint stores a list position); the handler logs the report.
    - Strato: `MaintenanceTick` calls it after its existing sweeps when time is left before the deadline.
24. **`GET /api/trending?window=day|week`**, bearer auth, JSON. An unknown window is a 422 `problem+json`
    (mapped in the module's `*Problems` mapper). Response `{ window, builtAt, stories }`, at most 15 stories,
    no paging; each story `{ lead, others, outletCount, viewed }`:
    - `outletCount`: distinct subscribed feeds in the story, social included.
    - `lead`, `others`: article-feed entries as `EntryJson` list rows, one per feed (the newest when a feed has
      two). The lead is the newest entry with an image, else the newest.
    - `viewed`: decision 6.
    - Order: `outletCount` descending, then the newest entry.
    A thin `TrendingController` hands a `TrendingWindow` to `TrendingStories`; `src/Http/TrendingJson` shapes
    the response. The per-reader query lives in a repository.
25. **Sidebar counts.** The cheap counts response (`GET /api/subscriptions/counts`, polled by
    `sidebar-counts-poll`) gains `trending: { day, week }`, the unopened-story count per window. No new poll.

## Frontend

26. **Sidebar:** *Trending* right above For you, `view: 'trending'`, badge per decision 8. It stays visible when
    empty; the empty state states the rule ("a story shows up here once several of your feeds cover it"; decision 38 makes the number per reader).
27. **List header:** title and a `24 h | 7 days` switch with both options visible (`<app-segmented-choice>`).
28. **Card.** In the magazine layout the lead goes through the normal magazine block, the kicker line shows
    "7 sources", and the footer lists the other outlets. In the list layout the same on an `entry-row`. A viewed
    story uses the existing read-state styling, nothing more (decision 7). Opening the lead opens the article
    page; next and previous walk the leads.
29. **"Also in" is generalised.** `entry-duplicates` takes its copies and its label as inputs instead of reading
    `entry().duplicates`: duplicates pass "Also in", Trending passes "Also covered by". The footer shows at most
    4 names and "+N more", so the magazine planner's block height stays predictable.
30. **Translations** in English and German.

## Tuning

31. **`app:trending:explain --window=day|week`**, read-only: runs the grouping in memory against the current
    database and writes nothing. Prints every group (outlet count, headlines) and the **near misses** — pairs
    that failed exactly one rule, with the rule and the numbers ("shared weight 12.8 < 14").
32. **Acceptance before the PR:** on the dev database, the top 20 groups of each window hold no wrong merge,
    and the week build stays under 30 s and 128 MB (about 40k entries on 2026-11-04; the day window held
    5,926). The counts go in the PR.

## Testing

33. **Unit:** `HeadlineStems` (diacritics, umlauts, the 6-character cut, numbers, minimum length); filler (the
    3 % cut with its floor, the per-feed rule); the link rule with a value on each side of every threshold; the
    URL link; the gap; same feed never links. Grouping: shuffled input gives the same groups; a weak bridge does
    not merge two groups; a broadly linked entry joins.
34. **Integration, SQLite and MySQL:** the builder replaces only its window; `rebuildDue()` honours the
    intervals; `TrendingStories` applies the per-reader threshold, subscribed feeds only, social counted but not
    shown, article-less stories dropped, the lead and one-per-feed rules, and `viewed`.
35. **Functional:** `GET /api/trending` (auth, 422, shape); the counts carry `trending`; `MaintenanceTick` runs
    the rebuild. The migration is covered by CI's migrate-from-empty leg.
36. **Frontend:** Jest for the sidebar item and badge, the list, the window switch and its storage, the hide action, the
    generalised footer (the duplicates footer unchanged) and its 4-name cap. One Playwright smoke that stubs
    `/api/trending`. Live check on :4200: magazine and list layouts at phone and desktop width, Trending cards
    beside ordinary duplicates, dark mode.

## Additions

37. **Hide a story (added 2026-11-04).** A *Hide* icon in a Trending card's action row (Trending only, no undo)
    records every entry of that story, social ones included, as hidden for the reader
    (`trending_hidden_entry`: `user_id`, `entry_id`, `hidden_at`; PK both ids; both foreign keys `ON DELETE
    CASCADE`, so retention clears it). A story containing any hidden entry is left out of the reader's list and
    badge, so the event stays hidden after rebuilds even as it grows. `POST /api/trending/hidden` with
    `{ window, entryId }` (the lead) answers 204; an entry in no story of that window is a 404 `problem+json`.
    The card leaves the list at once; on failure it comes back.

38. **The threshold scales with the reader's active feeds (added 2026-11-04).** Decision 1's 3 / 5 become floors:
    a story needs at least `max(floor, ceil(share × active))` distinct subscribed feeds, where *active* is the
    number of feeds the reader follows that published at least one entry within the window. Day: floor 3,
    share 3 %; week: floor 5, share 3.5 %. Both numbers live in `TrendingWindow`. The build keeps dropping
    groups below the floor (instance-wide). On 2026-11-04 the owner's account (129 / 190 active of 241) gets 4
    and 7, about 19 and 23 stories.

39. **Trending can be switched off per account (added 2026-11-04).** A *Trending* toggle in Settings →
    Preferences, on by default, stored on the account (`preferences.trending_enabled`, like `sharing_enabled`), so
    every device follows it; it travels in the backup's account line. Off: no sidebar item and no badge; a
    `?view=trending` selection falls back to All items; the counts response skips both per-reader Trending queries
    and reports `{ day: 0, week: 0 }`. `GET /api/trending` keeps answering; the instance-wide rebuild keeps running
    for other accounts; hidden stories stay hidden when the switch comes back on.

40. **Trending rides the shared reader paths (added 2026-11-04, after `/simplify`; the product owner chose all four).**
    - **A story is an ordinary list row.** `GET /api/trending` answers `{ window, builtAt, stories: EntryJson[] }`.
      Each story is its lead as an `EntryJson` list row whose `duplicates` hold the other outlets (one per feed: the
      story's other article entries merged with the lead's own url-hash duplicates, the newest per feed). The row
      also carries `story: { outletCount, viewed }`. The client keeps no separate `coverage` path: the footer, the
      store's optimistic patching and the popover all work on `duplicates`, and `story` alone switches the footer label
      to "Also covered by", the kicker's "N sources" and the unread-mark rule (decision 7). Amends decisions 24 and 29.
    - **Hide belongs to the Trending list.** The list puts its Hide control into the card's action row through a
      list-owned slot. The shared entry-action handler contract and `EntryActionsComponent` know nothing about stories.
      Amends decision 37 (the behaviour is unchanged).
    - **Switched off means parse-time fallback.** With decision 39's switch off, `?view=trending` resolves to All items
      where the URL's view is parsed, the way an unknown view does. The selection never differs from the URL.
    - **The counts carry only the selected window.** `GET /api/subscriptions/counts` and `GET /api/subscriptions` take an
      optional `trendingWindow=day|week` (default `day`; an unknown value is a 422) and answer
      `trending: { window, unopened }`. The client sends the window it shows and refetches when it changes. Amends
      decisions 8 and 25.
    - **The window is in the URL.** `?view=trending&window=week`, `day` when absent. The 24 h / 7 days switch is a pair
      of links. The per-account device setting from decision 9 stays as the *default*: a sidebar link to Trending
      (with no window) opens the stored window, and picking a window stores it. Amends decision 9.

41. **7 days leaves out today's stories (added 2026-11-04; product owner: "too much overlap between the 24h list and
    the 7 day list").** For a reader, a week story is left out when it shares any entry with a story in that reader's
    shown 24 h list: after the threshold, the two-article-feed rule, hidden stories and the 15-story cap. The week list
    becomes "earlier this week", still capped at 15. The week badge counts only what the week list shows, and the hide
    lookup for `week` uses the same rule, so list, badge and hide never disagree. A day story the reader is not shown
    never removes anything from the week list. The grouping and the snapshots are unchanged; this is a per-reader read
    rule. Amends decisions 4, 8 and 24.

## Out of scope

A per-feed "include in Trending" toggle, an instance-wide (admin) Trending switch, notifications, cross-language matching, mark-all-read in Trending,
the AI link source.
