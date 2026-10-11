# #1563 — EntryBodyService cancels superseded fetches

**Goal:** replace `EntryBodyService`'s three staleness guards (the logout `generation`
counter, the `latestRequest` ticket `WeakMap`, the `cache.get(id) === state` check) with
one `Map<id, Subscription>` of in-flight fetches that a newer fetch, `forget` and `clear`
unsubscribe. A superseded request is cancelled, not run to completion and then ignored.

## Rulings

- **`details` is renamed, not moved** (ticket author, fc0b88): it becomes
  `completedEntries`, named for what it carries. #1553 wires it into the `EntriesStore`.
- **The fetch defers itself through `subscribeOn(asapScheduler)`** instead of
  `stateFor`'s `queueMicrotask`. The subscription is then registered synchronously, so a
  `forget` or a logout between `body()` and the microtask cancels a fetch that has not
  gone out yet (the old `cache.get(id) === state` check covered that window for the
  published entry). Every fetch is deferred, `refresh` and `retry` included, so the
  `computed()`-safety rule holds without a second code path.
- **The in-flight map is keyed by the body signal, not the id.** Keyed by id, re-asking
  for an evicted id would cancel the fetch still filling the evicted signal a view holds,
  leaving it `loading` for good. `forget` looks the signal up by id before cancelling.
- **LRU eviction cancels nothing** (acceptance): a view may still hold the evicted signal.
  The evicted fetch now publishes its completed entry when it lands; that entry is
  current server data, so the old suppression bought nothing.
- **Cross-id navigation is not a cancellation case.** Bodies are per-id signals, so A's
  response can only ever write A's signal; a spec pins that rapid A→B never shows A under
  B, but it is not one of the guards removed here. The three removed guards are each
  pinned by a spec asserting the superseded `TestRequest` was `cancelled`, and each fails
  with cancellation taken out.

## Files

- `frontend/src/app/reader/article/content/entry-body.service.ts`
- `frontend/src/app/reader/article/content/entry-body.service.spec.ts`
- `frontend/src/app/reader/shell/reader-route-state.service.ts` (rename)
- `frontend/src/app/reader/shell/reader-shell.component.spec.ts` (rename; the #1517 spec awaits
  the deferred fetch's microtask instead of advancing jest's fake timers, which `asapScheduler`'s
  promise does not ride on)

## Tasks

1. Specs: superseded refresh, forget, logout and forget-before-the-microtask assert
   `cancelled`; LRU eviction asserts the request is *not* cancelled; A→B pin.
2. Service: in-flight map, `subscribeOn(asapScheduler)`, `finalize` drops the map entry;
   delete `generation`, `latestRequest`, `superseded`; rename `details`.
3. Break-test: remove each `unsubscribe` and see the matching spec fail.
4. Gate: `docker compose exec -T frontend npm run check`.
