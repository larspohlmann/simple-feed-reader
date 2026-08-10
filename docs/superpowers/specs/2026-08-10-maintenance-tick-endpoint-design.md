# Single maintenance tick endpoint

Issue: [#346](https://github.com/larspohlmann/simple-feed-reader/issues/346)

## Problem

A worker-less install (the Strato deployment) drives scheduled work from an
external cron. Today that needs two separate token-guarded pings:

- `POST /maintenance/refresh` — refresh all due feeds.
- `POST /maintenance/recommendations/sweep` — start due recommendation runs and
  advance each active run one step.

So an operator wires two cron lines. One combined entry point is simpler to run
and to document.

## Goal

Add one endpoint that does both jobs in a single call, so a single cron line
drives the whole worker-less install. Keep the two granular endpoints for
callers that want one job only.

## Non-goals

- No change to due-ness logic. Both halves already self-gate:
  `RefreshRequest::allDue()` refreshes only due feeds, and
  `ForYouSweep::sweepOnce()` starts only due accounts and advances each active
  run one step.
- No new worker heartbeat. The `WorkerPresence` arbitration between a poll tick
  and a live worker is untouched. The cron does the work directly; it does not
  pretend to be a worker.

## Design

### Route

```
POST /maintenance/tick
Header: X-Maintenance-Token: <MAINTENANCE_TOKEN>
```

Guarded by the existing `MaintenanceTokenGuard` (constant-time shared-token
check). The two granular routes stay unchanged.

### Controller

A new action on `MaintenanceController`. It stays thin (the `ThinControllerRule`
gate): check the token, call one service, return the report as JSON. No
orchestration in the controller.

### Orchestration service

A new `MaintenanceTick` in `Service/Maintenance/`. It depends on the same
concrete `RefreshRunner` and `ForYouSweep` the granular endpoints already use.

- **Order:** refresh first, then the recommendation sweep. Refresh is the
  cheaper, time-bounded job; running it first also lets a later run see freshly
  fetched entries, and its work commits before the sweep begins.
- **No extra guard — isolation comes from the halves themselves.** Both halves
  are already near-non-throwing: `RefreshRunner::run()` catches its own database
  errors and returns `status: "aborted"` instead of throwing, and
  `ForYouSweep::sweepOnce()` catches per-run failures internally. So a broken
  refresh surfaces as a status in its own report and the sweep still runs; a
  broken provider for one account is logged and skipped inside the sweep.
  `MaintenanceTick` adds no try/catch of its own. A genuinely unexpected
  exception is left to bubble to Symfony's 500 handler — the "the tick could not
  run" case.
- **Budget:** the refresh half keeps its existing 20-second budget. The
  recommendation half advances one step per active run, which is already
  bounded. One tick's worst case is 20 s plus one step per active run — well
  under the Strato request timeout for a personal instance.

### Response

A combined `MaintenanceTickReport`:

```json
{
  "refresh": { "...": "RefreshReport shape, incl. status" },
  "recommendations": { "startedRuns": 0, "advancedRuns": 0, "activeRuns": 0 }
}
```

**HTTP status:** always `200` when the tick ran, with each half's own status
inside the JSON. A cron cares about 2xx-versus-error, and a refresh half that
came back `busy` or `aborted` must not read as a failed tick — its status lives
in the body. The endpoint reaches Symfony's `500` only on an unexpected,
unhandled exception.

This differs on purpose from the standalone `/maintenance/refresh`, which maps
`busy → 409` and `aborted → 500`. Those granular codes stay on the granular
route for a caller that pings refresh alone.

## Testing

- Unit test for `MaintenanceTickReport`: it merges the two half-reports under
  the `refresh` and `recommendations` keys.
- Integration test for `MaintenanceTick` (through the container): one `run()`
  produces a report carrying both halves' shapes.
- Functional test for the route: the token guard rejects a missing or wrong
  token; an authorized call returns the combined JSON with both keys.

## Docs

Update `docs/for-you-scheduling.md`: one cron line calling `/maintenance/tick`
replaces the two, with the granular routes noted as still available.
