# Notification context sources return contexts (#2300)

## Intent

Follow-up to #2296. A context source today must return every row it was given, in order, with context added,
and `NotificationPageModel::withSubjectsOf()` merges them back by remote id. Returning only what was found removes
that contract, the merge-back, the `withSubject()` → `withContext()` clone chain and the "row unchanged" branches.

Goal: same behaviour, simpler shape. Non-goal: any change to what context is fetched or stored.

## Approaches

- **Page model applies the map (picked).** `NotificationPageModel::withContexts()` replaces `withSubjectsOf()`;
  the page owns its rows, the lookup stays a one-liner.
- Lookup applies the map itself. Puts row rebuilding into the service; rejected.

## Design

- `NotificationContextSourceInterface::contextsOf(SocialAccount, non-empty-list<FetchedNotificationModel>):
  array<string, NotificationContext>` — keyed by remote id, only for rows where context was found; the value is the
  row's full new context (built on the one it has). Failed lookups leave the key out; a rejected credential still
  propagates; no call is made when no row has references.
- Bluesky and Mastodon keep their batched fetches and fill a map.
- Lemmy keeps its per-id cache, `break`s on a throttle and skips rows without an answered id or excerpt.
- `NotificationPageModel::withContexts(array<string, NotificationContext>)`: rows with a key get
  `withContext()`, others stay.
- `FetchedNotificationModel::withContext()` clones once (`subject => subject->withContext(...)`);
  `withSubject()` is deleted.
- Tests (`RecordingContextSource`, the three source tests, `NotificationPageModelTest`) assert on the returned map,
  an unmatched row being an absent key.
