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
