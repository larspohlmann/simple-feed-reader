# Pending dots and answered scores for Lemmy votes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** While a Lemmy post vote is on the wire, the tapped arrow on the row's vote pill shows `app-pending-dots`, and the score is never stepped in the browser (#2429).

**Architecture:** `InFlightPatches` keeps each patch's values from before, so `EntryRowCache` can name the arrow a vote on the wire was pressed on. `EntryActionHandler` exposes it, `entry-meta` hands it to `app-vote-pill`, which swaps that arrow's icon for the dots. `setVote` patches only `myVote` optimistically; the score comes from the vote's answer.

**Tech Stack:** Angular 20 signals, Jest.

**Spec:** GitHub issue #2429, plus the measurement below.

## Measurement (done before planning)

Real votes through `PUT /api/entries/{id}/vote` (up, clear, down, clear, up, clear) on a lemmy.world post and on federated feddit.uk and sh.itjust.works posts: the answered scores add up exactly every time (e.g. 11/10/9/10/11/10). Lemmy's home instance counts its own vote at once, so the backend stays as it is (it already stores the answered score, #2425). No server counting.

## Global Constraints

- No browser ±1 on the post score: `setVote` no longer calls `steppedScore` (comment votes in `comments.service.ts` are outside #2429 and keep theirs).
- Dots only on the pressed arrow; the other arrow and the score stay. `aria-busy="true"` on the pill's group while pending.
- Scope: the vote pill in `entry-meta` (list rows and magazine cards). Article and comment pills untouched.
- Every copy of the post shows the pending arrow, as likes do.

## Review Focus

- A clear (tapping the already-voted arrow) shows the dots on that arrow, not on none (Task 1 test with `before`).
- A failed vote restores `myVote` and drops the dots (Task 1 test).
- Score unchanged while the vote is pending, then the answered score (Task 1 test).
- A post without a vote to give (`myVote === null`) shows no dots and stays an image (Task 2 test).
- A second tap while pending is ignored (existing guard; Task 1 asserts no second request).

---

### Task 1: The cache names the pressed arrow and stops stepping the score

**Files:**
- Modify: `frontend/src/app/reader/state/in-flight-patches.ts`, `frontend/src/app/reader/state/entry-row-cache.ts`, `frontend/src/app/reader/entry/entry-actions/entry-action-handler.ts`, `frontend/src/app/reader/shell/entry-state-actions.service.ts`, `frontend/src/app/reader/list/magazine/entry-duplicates.component.ts`
- Test: `frontend/src/app/reader/state/in-flight-patches.spec.ts`, `frontend/src/app/reader/state/entry-row-cache.spec.ts`, handler doubles in specs that need it

**Interfaces:**
- Produces: `InFlightPatches.add(entryId, patch, before: RowPatch = {})`; `InFlightPatches.findBefore(entryId: number, field: keyof RowPatch): RowPatch | null` (the `before` of the patch carrying `field`, reactive like `onTheWire`). `EntryRowCache.findVoteOnTheWire(entryId: number): 'up' | 'down' | null` — the patch's `myVote` when ±1, else the `before.myVote` when ±1, else null. `EntryActionHandler.findVoteOnTheWire(entry: EntryDto): 'up' | 'down' | null` (IGNORED → null).

- [ ] **Step 1: failing tests**
  - in-flight: `findsTheValuesBeforeAPatchOnTheWire` — add(1, {myVote: 0}, {myVote: 1}) → findBefore(1,'myVote') equals {myVote: 1}; after release → null; a computed over it follows.
  - row cache: `names the up arrow while an upvote is on the wire` (Subject vote) → 'up' on both copies, null on another item; `names the arrow being cleared` (from 1 to 0) → 'up'; `names the down arrow`; `leaves the score alone until the vote answers` (score 10 stays 10 while pending, myVote already `to`); `takes the answered score` (existing); `drops the pressed arrow and restores the vote when it fails`.
- [ ] **Step 2:** `docker compose exec -T frontend npx jest src/app/reader/state` → FAIL.
- [ ] **Step 3:** implement; `optimistic()` passes `befores[index]` to `add`; `setVote` patch is `{ myVote: to }`; remove `steppedScore` import there.
- [ ] **Step 4:** same command → PASS.
- [ ] **Step 5:** commit `feat(#2429): the row cache names the arrow a vote is on the wire for and leaves the score to the answer`.

### Task 2: The vote pill shows the dots on the pressed arrow

**Files:**
- Modify: `frontend/src/app/reader/vote-pill/vote-pill.component.{ts,html}`, `frontend/src/app/reader/list/entry-meta/entry-meta.component.{ts,html}`
- Test: `frontend/src/app/reader/vote-pill/vote-pill.component.spec.ts`, `frontend/src/app/reader/list/entry-meta/entry-meta.component.spec.ts`

**Interfaces:**
- Consumes: `EntryActionHandler.findVoteOnTheWire` (Task 1).
- Produces: `VotePillComponent.pending = input<'up' | 'down' | null>(null)`.

- [ ] **Step 1: failing tests**
  - vote-pill: `showsTheDotsOnThePendingArrow` — myVote 1, pending 'up' → up button holds `app-pending-dots`, no `arrow_upward`; down button keeps `arrow_downward`; pill `aria-busy="true"`. `showsNoDotsWithoutAVoteToGive` — myVote null, pending 'up' → no dots. `isNotBusyWithNothingPending`.
  - entry-meta: `hands the pending arrow to the vote pill` — handler double returns 'down' → pill's down button holds the dots.
- [ ] **Step 2:** jest on both specs → FAIL.
- [ ] **Step 3:** template swaps the icon for `<app-pending-dots size="sm" />` in the arrow button when `pending() === direction`; `[attr.aria-busy]="pending() ? true : null"` on the pill; entry-meta computes `voteOnTheWire = computed(() => this.actions.findVoteOnTheWire(this.entry()))` and binds `[pending]`.
- [ ] **Step 4:** `docker compose exec -T frontend npm run check` → green.
- [ ] **Step 5:** commit `feat(#2429): the vote pill shows pending dots on the arrow a vote is on the wire for`.

### Task 3: Live check

- [ ] On :4200, a Lemmy feed row: tap up, capture the dots on the up arrow and the unchanged score, then the answered score; clear the vote again so the post is left as found.

---

## Revision (Lars, 2026-11-21): the controls own their pending dots

Tasks 1–2 above wired the pending state from the row cache into each parent. Lars chose instead (option A):

- **The control owns it.** `app-vote-pill` takes `vote: ((to: VoteValue) => Observable<unknown>) | null` in place of the `voted` output; it shows `app-pending-dots` on the tapped arrow until that observable settles and ignores taps meanwhile. The like buttons do the same: `app-entry-actions` keeps a local pending flag around `actions.like(entry)`, and `app-social-counts-row` takes `like: (() => Observable<unknown>) | null` in place of the `likeToggled` output.
- **Settling observables.** `EntryActionHandler.like` and `.vote` return `Observable<void>` that completes once the request settles (error included; errors stay reported where they are today). `EntryRowCache.setLiked/setVote` return it; `OptimisticToggles.change` (and `run`) return it, so `CommentsService.vote/toggleLike` do too.
- **Comment votes wait for the server:** `CommentsService.vote` applies only `myVote` at once; the score comes from the answer. `steppedScore` goes.
- **Removed:** `isLikeOnTheWire` and `findVoteOnTheWire` (handler, cache, implementers, doubles), `InFlightPatches.findOnTheWire`/`before`, and the `changes` signal added in #2427 (nothing reads `onTheWire` reactively any more).
- **Trade-off accepted:** only the tapped control shows the dots, not other copies of the post.
- Comment like counts keep their current optimistic step (their answer carries no count); they get the dots like every like button.

Tests: per control, the dots appear on tap and go when the observable completes or errors; a second tap while pending sends nothing; post and comment votes keep the score until the answer.
