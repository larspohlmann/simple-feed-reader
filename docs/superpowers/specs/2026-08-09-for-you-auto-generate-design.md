# Scheduled auto-generation of "For You"

GitHub issue: #333
Branch: `feature/333-for-you-auto-generate`
Date: 2026-08-09

## 1. Goal

Let a user have their "For You" recommendations generated on a schedule, not
only by the manual button. A per-user dropdown offers six choices: **only
manually**, **every 1 hour**, **every 3 hours**, **every 6 hours**, **every 12
hours**, **every 24 hours**.

The default is "only manually". No existing user changes behaviour until they
choose a cadence, so this is opt-in.

The schedule runs from two places with the same result:

- On an install with the background worker, the worker starts the runs by
  itself.
- On an install without the worker, an external cron (for example a GitHub
  Actions schedule) calls an HTTP endpoint that does the same work.

## 2. Decision superseded

`WorkerSchedule` records the `#308` decision "manual button only" — scheduled
recommendation *runs* were deliberately excluded to avoid unattended token
spend. This feature reverses that decision, but as an **opt-in** per-user
cadence. The `WorkerSchedule` doc comment is updated to record the new,
narrower rule: scheduled runs start only for a user who chose a cadence.

Cost note: a user on a 1-hour cadence spends provider tokens continuously and
unattended. This is the explicit intent of the feature.

## 3. Data model

Add one nullable column to the per-user `RecommendationSettings` entity:

- `autoGenerateIntervalHours` (`int`, nullable). `null` means "only manually".
  The only accepted non-null values are `1, 3, 6, 12, 24`.

The value flows through the existing write path without a new one:
`RecommendationSettingsValues` gains the field, `RecommendationSettings::update()`
copies it, and `RecommendationSettingsWriter::save()` persists it. A new
migration adds the nullable column and is verified on SQLite and MySQL.

**Due-ness anchor.** A user is "due" when either of these is true:

- The user has no recommendation run yet.
- `now − mostRecentRun.createdAt ≥ autoGenerateIntervalHours`.

The anchor is the most recent run's `createdAt`, read through a new
`RecommendationRunRepository::findMostRecentCreatedAt(User): ?\DateTimeImmutable`.
Any run resets the clock — manual, worker, or cron — so a manual click never
triggers an auto-run minutes later, and a failed run waits one full interval
before the next attempt (no hammering of a broken provider).

## 4. Shared sweep logic

Both triggers use the same services, so the behaviour cannot drift.

- `DueRecommendationRunFinder`: returns the users to start a run for. A user
  qualifies when the interval is set, the AI is ready
  (`AiSettingsJson::isReady`), there is no active run, and the anchor has
  elapsed.
- `ForYouSweep`: the sweep service, with two entry points.
  - `startDueRuns(): ForYouSweepReport` — starts one run per due user through
    `RecommendationRunStarter::start()`.
  - `sweep(int $budgetSeconds): ForYouSweepReport` — first `startDueRuns()`,
    then advances every active run toward completion within a wall-clock
    budget, looping the way `RefreshRunner` does. Returns a small report
    (counts of started runs, advanced ticks, and still-active runs).

`RecommendationRunStarter::start()` already returns an active run as-is, and the
per-user run lock already serialises execution, so a duplicate start is
impossible even under a race.

## 5. Two triggers

### 5.1 Worker install (automatic)

Add a third recurring message to `WorkerSchedule`:

- `StartDueRecommendationRuns` message + `StartDueRecommendationRunsHandler`
  (`#[AsMessageHandler]`), wired at `RecurringMessage::every('5 minutes', …)`.
  Five minutes is ample, because the smallest interval is one hour.

The handler calls `ForYouSweep::startDueRuns()`. The existing 10-second
`AdvanceRecommendationRuns` sweep then drives the started runs to completion.
The worker-existence gate is implicit: this sweep runs only inside the worker.

### 5.2 No-worker install (external cron)

Add a machine-facing endpoint on the existing `maintenance` firewall
(`security: false`), guarded by the existing `MaintenanceTokenGuard`:

- `POST /maintenance/recommendations/sweep`
- Auth: header `X-Maintenance-Token`, value `MAINTENANCE_TOKEN`. An empty token
  denies every call, so an unset env var keeps the endpoint closed. This is the
  same token and guard the feed-refresh pinger already uses. No new secret and
  no `security.yaml` change.
- The controller is thin, mirroring `MaintenanceController::refresh`: it checks
  the guard, calls `ForYouSweep::sweep($budgetSeconds)`, and returns the report
  as JSON. It performs no querying, no validation, and no security logic of its
  own.
- Response: `200` with the report on success, `403` with
  `application/problem+json` on a bad or missing token.
- The endpoint calls `sweep()` (start **and** advance), because a worker-less
  install has no 10-second advance sweep. The bounded budget keeps each request
  short and safe to call repeatedly; the external cron makes steady progress
  over successive calls.

The endpoint stays harmless on a worker install: the token gates it, and
`startDueRuns()` skips users who already have an active or not-yet-due run.

## 6. API surface (per-user settings)

The recommendation-settings resource at `/api/me/ai/recommendations` changes:

- GET response gains two fields:
  - `autoGenerateIntervalHours`: the saved value (`null` or one of the allowed
    hours).
  - `workerAlive`: a boolean from `WorkerPresence::isRecommendationWorkerAlive()`,
    so the client knows whether to show the external-cron help note.
- The save request accepts `autoGenerateIntervalHours`, validated against the
  allowed set (`null, 1, 3, 6, 12, 24`); any other value is rejected with
  `application/problem+json`.

All fields are plain JSON, so the surface stays native-iOS-safe.

## 7. Frontend

Add the dropdown to `recommendation-settings-card.component`.

- The dropdown is **always shown when AI is ready**. It is not hidden when the
  worker is absent.
- Options, in order: *Only manually*, *Every 1 hour*, *Every 3 hours*, *Every
  6 hours*, *Every 12 hours*, *Every 24 hours*. It saves through the existing
  settings-save flow — no new save button.
- When `workerAlive` is **false**, a help note shows under the dropdown. It
  explains that, without a background worker, the schedule cannot fire on its
  own, and that an external cron must call the endpoint. It shows a copyable
  example (curl and a GitHub Actions step) that POSTs to
  `<origin>/maintenance/recommendations/sweep` with an `X-Maintenance-Token`
  header. The token is shown as a placeholder (`<MAINTENANCE_TOKEN>`), never the
  real secret.
- When `workerAlive` is **true**, no help note shows — the worker handles the
  schedule.

Styles live in the component's sibling `.scss`. The help note uses design tokens
only, with no hex colours and no raw `px` values outside `theme/`.

## 8. Documentation

Add a short note to the docs (for example under the worker or maintenance docs)
that describes the endpoint, the token header, and a GitHub Actions cron
example. No workflow file is committed to the repository, so nothing spends
tokens until the reader sets it up deliberately.

## 9. Testing

Backend:

- Unit test for `DueRecommendationRunFinder`: due when the anchor elapsed, not
  due before it, skipped when the interval is `null`, skipped when the AI is
  not ready, and skipped when a run is already active.
- Unit test for the anchor query `findMostRecentCreatedAt`.
- Functional test for the cron endpoint: a due user's run is started and
  advanced; the endpoint returns `403` without the token; the endpoint returns
  `403` when `MAINTENANCE_TOKEN` is empty.
- Functional test for `StartDueRecommendationRunsHandler`: it starts a run for a
  due user and skips a not-due, no-AI, or already-active user. Back the handler
  with a functional test, not a direct-invocation test only.
- Read/write test for the new settings field, including validation of the
  allowed set.
- The migration is verified on SQLite and MySQL, with
  `doctrine:schema:validate` clean.

Frontend:

- Jest test: the dropdown renders whenever AI is ready; the help note shows only
  when `workerAlive` is false; a choice saves through the settings flow.

## 10. Gates

- Backend: `composer check` (PSR-12 + PHPStan max) and `composer md`
  (PHPMD codesize) clean on every touched file; PhpStorm inspections clean on
  changed PHP (block on ERROR and WARNING).
- Frontend: `npm run check` (ESLint + Prettier + Stylelint + Jest).
- Mutation: Infection over the changed files, at or above the gate in
  `infection.json5`.
- A live check: start a real run through the new path and confirm it completes
  with zero transport failures. Gates green is not the deliverable.

## 11. Out of scope

- No committed GitHub Actions workflow file.
- No instance-wide admin cadence (the setting is per-user).
- No separate cron token (the existing `MAINTENANCE_TOKEN` is reused).
- No change to the ranking, prompt, or batch pipeline.
