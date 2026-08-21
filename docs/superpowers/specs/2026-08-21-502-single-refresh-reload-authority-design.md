# Single reload authority for a refresh (#502)

## Problem

One user-initiated scoped refresh reloads the entry list twice. On mobile the
double is usually invisible, because both loads return the same entries. It
becomes visible when new entries arrive between the two loads, or the network
spreads them apart. The header/sidebar **Refresh** button uses the same code
path and has the same double reload. The cause is not the pull gesture and not
iOS.

`EntriesStore.load()` does not coalesce calls. Each call is a real GET to
`/api/entries`; a monotonic `loadSeq` token only stops a stale response from
overwriting a fresher one — it does not stop the second request or the second
re-render. So the double is two real reloads, and the last one wins.

## Root cause

One refresh reloads the entry list from two independent places in
`frontend/src/app/reader/reader-shell.component.ts`:

1. **The slice effect** (~line 446) reloads `subs` + `entries` every time a
   refresh report lands. It was added for the onboarding sweep (#127) so a
   brand-new empty reader fills in progressively. It has no "onboarding sweep
   only" guard, so it fires for every refresh.
2. **The `onDone` callback** of `onScopedRefresh()` and `onRefresh()` reloads
   `subs` + `tags` + `entries` again when the run finishes. This path predates
   the slice effect (#61).

For a single-slice scoped refresh, `entries.load(...)` runs at least twice
(final slice + `onDone`); for a multi-slice `all` sweep it runs once per slice
plus once for `onDone`.

This is the "two overlapping paths left by successive features" pattern: #61
added the reload, #127 added a second reload, the two were never reconciled.

## Constraint that shapes the fix

`RefreshService.run(onDone, scope)` is **not** used only by the reader shell.
`settings/backup-section.component.ts` and `settings/opml-section.component.ts`
both pass an `onDone` to reload their own data after a refresh, and the reader
shell — which owns the reload authority — is not mounted on those pages. So the
`onDone` parameter **must stay** on `RefreshService`. The redundant reloads are
removed at the shell call sites, not from the service.

## Chosen design

Give **one** authority the job of reloading the list after a refresh, and split
the two intents by the flag the shell already has, `sweeping()` (true only for
the span of the post-onboarding sweep):

- **First-run onboarding sweep** keeps the progressive, per-slice fill — the
  reason the slice effect exists. A new user must not stare at an empty list for
  the whole sweep.
- **A user-initiated refresh** (mobile pull, header/sidebar Refresh button,
  add-feed) reloads the list **once, when the whole run finishes** — no
  mid-sweep flicker or reorder.

### The single authority

Replace the slice effect with one effect that is the sole reload authority:

```ts
// One authority reloads the list after any refresh (#502). Two intents, one place:
//   - onboarding sweep (sweeping()): fill progressively, so each landing slice reloads;
//   - every user-initiated refresh: reload once, when the run finishes — no mid-sweep flicker.
// A second reload used to live in each run()'s onDone callback, so one scoped
// refresh loaded the list twice.
effect(() => {
  const slice = this.refreshSvc.slice();
  const running = this.refreshSvc.running();
  untracked(() => {
    if (slice === 0) return; // nothing has reported yet
    if (!this.sweeping() && running) return; // manual refresh: wait for finish
    this.subs.load();
    this.tags.load(); // the authority now reloads tags too (onDone did; the slice effect did not)
    this.entries.load(queryFromSelection(this.selection()));
  });
});
```

The effect tracks `slice()` and `running()`; it reads `sweeping()` and
`selection()` untracked, so neither a selection change nor the sweep flag
re-triggers a reload.

- **Manual refresh, single slice:** the slice increment and `running` → false
  settle before the first change-detection pass, so the effect runs once with
  `running` false → exactly one `entries.load`.
- **Manual refresh, multi-slice `all` sweep:** each partial slice re-runs the
  effect with `running` true → skipped; the completing slice sets `running`
  false → one reload at finish.
- **Onboarding sweep:** `sweeping()` is true throughout, so every landing slice
  reloads (progressive fill). When the sweep ends, `running` → false re-runs the
  effect once more; `sweeping()` may already be cleared, so this fires one final
  reload. That single redundant reload is a once-per-new-user event and is a
  correct final reconcile — accepted as the cost of a single, order-independent
  authority.

### Remove the redundant shell `onDone` reloads

Delete the `onDone` reload bodies at the three shell call sites so each relies on
the single authority:

- `onRefresh()` → `this.refreshSvc.run();`
- `onScopedRefresh()` → `this.refreshSvc.run(undefined, scope);`
- `onAddFeed()` (unfetched-feed branch) → `this.refreshSvc.run(undefined, { feedId: sub.feedId });`

Add-feed must be included: the authority fires for its run too, so leaving its
`onDone` reload would just re-create the double there.

`RefreshService` is unchanged — `onDone` stays for the settings pages.

## Why one effect, not two

A single effect is literally "one authority" and needs no cross-effect ordering
guarantee. The alternative — a per-slice "onboarding only" effect plus a
"reload at finish" effect — avoids the one redundant onboarding reload, but only
by depending on effect-registration order to keep the finish effect from firing
during the sweep. That trades robustness for a saving of one reload in a
once-per-new-user path. Rejected.

## Acceptance criteria

- One user-initiated scoped refresh (pull or header/sidebar button) causes
  exactly one `entries.load`, verified by a component test.
- The onboarding sweep still repopulates progressively as slices land.
- Tags still refresh after a scoped refresh.
- No second reload authority remains for the manual-refresh path.

## Testing

Component tests in `reader-shell.component.spec.ts`:

- **Exactly one reload:** drive `onScopedRefresh()`, flush one `completed`
  report, assert exactly one request to `/api/entries` follows (and one
  `/api/tags`).
- **Onboarding still progressive:** boot a reader whose subscriptions are all
  unfetched (the post-onboarding sweep fires), flush two partial slices, assert
  the list reloads on each slice.
- **Tags reload after a scoped refresh:** assert a `/api/tags` request follows a
  scoped refresh.

The existing scoped-refresh tests (scope mapping for all/tag/subscription, and
"no refresh from the cross-feed saved views") must stay green.

## Files

- `frontend/src/app/reader/reader-shell.component.ts` — slice effect (~446),
  `onRefresh` (~788), `onScopedRefresh` (~817), `onAddFeed` (~877).
- `frontend/src/app/reader/reader-shell.component.spec.ts` — new tests.
- `frontend/src/app/reader/refresh.service.ts` — unchanged (documented here so a
  future reader does not "simplify" `onDone` away).

## Out of scope

- No change to `RefreshService` behaviour or its `onDone` contract.
- No change to the settings backup/OPML refresh paths.
- No change to the `loadSeq` stale-response guard in `EntriesStore`.
