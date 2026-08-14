# Scheduling "For You" generation

Each account can have its "For You" recommendations generated on a schedule.
The cadence lives in **Settings → AI → Recommendations → Auto-generate**: *only
manually* (default), or every 1, 3, 6, 12, or 24 hours. A run is due when the
account's newest run is at least one interval old.

## With the background worker

On an install that runs the `worker` container, nothing else is needed. The
worker starts due runs every five minutes and advances them to completion.

## Without a worker (external cron)

An install without the worker exposes a token-guarded endpoint that does the
same work — start due runs, then advance each active run one step:

    POST /maintenance/recommendations/sweep
    Header: X-Maintenance-Token: <MAINTENANCE_TOKEN>

It reuses the same `MAINTENANCE_TOKEN` as the feed-refresh pinger. An empty
token keeps the endpoint closed. Each call advances one step per active run, so
call it on a schedule; a long-running provider call is flushed per step, so a
timed-out request still keeps its progress.

Example GitHub Actions schedule (store the token as the repository secret
`MAINTENANCE_TOKEN`):

    name: for-you-sweep
    on:
      schedule:
        - cron: '*/15 * * * *'
    jobs:
      sweep:
        runs-on: ubuntu-latest
        steps:
          - run: |
              curl -fsS -X POST "https://YOUR_HOST/maintenance/recommendations/sweep" \
                -H "X-Maintenance-Token: ${{ secrets.MAINTENANCE_TOKEN }}"

The response is JSON: `{ "startedRuns": n, "advancedRuns": m, "activeRuns": k }`.

## The on-demand drainer

Worker-less installs do not depend on the cron cadence for interactive
runs. Starting or resuming a run from the web spawns a short-lived,
detached CLI process (`app:recommendations:drain`) that advances every
active run at full worker concurrency until none is left, then exits. The
spawn only happens while no worker heartbeat is fresh, a global
`recommendation-drain` lock guarantees at most one drainer, and the
maintenance tick respawns a drainer as a safety net if one died with runs
still active. If the host cannot spawn processes (`exec` disabled, no CLI
binary configured), the launch silently no-ops and the cron sweep above
carries the runs exactly as before.

The CLI binary is named by `DRAIN_PHP_CLI_BINARY`; a web SAPI must not run
`bin/console` with its own binary (on Strato the web SAPI is `cgi-fcgi`
with a 240 s execution cap, while `/opt/RZphp84/bin/php-cli` is unbounded).
Empty is fine wherever PHP already runs as `cli` or a real worker exists.

## One call for everything

To drive both jobs from a single cron line, ping the combined endpoint instead
of the two separate ones:

    POST /maintenance/tick
    Header: X-Maintenance-Token: <MAINTENANCE_TOKEN>

It refreshes all due feeds, then starts due recommendation runs and advances
each active run one step, and returns both reports:

    { "refresh": { "status": "completed", ... },
      "recommendations": { "startedRuns": n, "advancedRuns": m, "activeRuns": k } }

It always answers `200` when the tick ran; read each half's own status in the
body. The granular `/maintenance/refresh` and `/maintenance/recommendations/sweep`
routes stay available for a caller that wants one job only.

Both halves share one database connection, so if the feed refresh aborts (its
report shows `"status": "aborted"`), the recommendations half is skipped for
that tick — its report keeps the run counts at zero and adds a `"skipped"`
reason — and the call still returns `200`; the next tick tries the sweep again.

Example cron line (every minute for the sweep cadence; the refresh half only
touches feeds that are already due):

    * * * * * curl -fsS -X POST "https://YOUR_HOST/maintenance/tick" -H "X-Maintenance-Token: $MAINTENANCE_TOKEN"
