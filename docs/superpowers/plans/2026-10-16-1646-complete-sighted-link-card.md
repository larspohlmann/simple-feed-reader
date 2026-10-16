# #1646 Complete a link card as soon as its placeholder is seen — Implementation Plan

**Goal:** A "Loading preview…" placeholder that enters the viewport asks the backend to
complete its entry immediately, instead of waiting for the 3 s pending poll to reach it.

**Why a call of its own:** `POST /api/entries/complete` spends one page fetch per call
(`EntryCompletion::LINK_CARD_FETCHES`) on whichever queued row the backend claims first,
not on the first id sent. Only a single-id call guarantees the seen card gets the fetch.

**Architecture (frontend only):**

- `shell/completed-entries-merge.ts` — `CompletedEntriesMerge`, the merge the poll does
  today (store merge, refresh the open body, forget the others), extracted so both callers
  share it.
- `shell/sighted-previews.service.ts` — `SightedPreviews`: `sighted(id)` asks once per entry
  per reader (reset on identity change), at most 2 calls in flight (Strato has few PHP
  workers), the most recently seen waiting card next, skipping one no longer pending.
  `isCompleting(id)` reports the in-flight ids.
- `PreviewProgress` gains `sighted(entryId)`; `PendingEntriesPoll` forwards it to
  `SightedPreviews` and counts an in-flight sighting as loading, so a placeholder whose poll
  rounds are spent says "Loading preview…" again while its call runs.
- `LinkCardPlaceholderComponent` observes its host with an `IntersectionObserver` and calls
  `sighted()` on the first intersection, then disconnects.

**Tasks (TDD, one commit each):**

1. Extract `CompletedEntriesMerge`; poll spec stays green.
2. `SightedPreviews` with spec: single-id call, once per entry, cap of 2, LIFO next,
   skips a finished entry, merges rows, reset on identity change and destroy.
3. `PreviewProgress.sighted` + poll forwarding + in-flight counted as loading; specs.
4. Placeholder observes and reports sighting; spec with a controllable observer stub.
5. `npm run check` in the worktree.
