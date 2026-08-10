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
`RefreshRunner` and `ForYouSweep` the granular endpoints already use.

- **Order:** refresh first, then the recommendation sweep. Refresh is the
  cheaper, time-bounded job; running it first also lets a later run see freshly
  fetched entries.
- **Failure isolation:** each half runs behind its own guard. A failure in the
  refresh half does not stop the recommendation half, and the reverse. Both
  underlying pieces report status instead of throwing in the normal case; the
  guard is a defensive floor that records the failure in the report.
- **Budget:** the refresh half keeps its existing 20-second budget. The
  recommendation half advances one step per active run, which is already
  bounded. One tick's worst case is 20 s plus one step per active run — well
  under the Strato request timeout for a personal instance.

### Response

A combined `MaintenanceTickReport`:

```json
{
  "refresh": { "...": "RefreshReport shape" },
  "recommendations": { "startedRuns": 0, "advancedRuns": 0, "activeRuns": 0 }
}
```

If a half fails, its key carries an error marker instead of its normal report,
and the other half's result is still present.

**HTTP status:** always `200` when the tick ran, with each half's own status
inside the JSON. A cron cares about 2xx-versus-error, and one half being "busy"
must not read as a failed tick. The endpoint returns `500` only if the tick
itself could not run.

This differs on purpose from the standalone `/maintenance/refresh`, which maps
`busy → 409` and `aborted → 500`. Those granular codes stay on the granular
route for a caller that pings refresh alone.

## Testing

- Unit test for `MaintenanceTick`: both halves run and merge into one report; a
  thrown failure in one half leaves the other half's result intact.
- Functional test for the route: the token guard rejects a missing or wrong
  token; an authorized call returns the combined JSON with both keys.

## Docs

Update `docs/for-you-scheduling.md`: one cron line calling `/maintenance/tick`
replaces the two, with the granular routes noted as still available.
