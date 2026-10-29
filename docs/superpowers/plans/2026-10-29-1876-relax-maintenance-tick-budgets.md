# #1876 Relax the maintenance tick budgets — implementation plan

**Goal:** let the image-verification and transcript queues drain on Strato by giving the
maintenance tick a configurable window and making image verification share it.

**Root cause:** `MaintenanceTick` plans a fixed 25 s window. `ImageVerificationSweep` spends its
own 15 s outside it, so after a busy refresh transcripts and link cards get no whole fetch before
the deadline. Images are capped at 25 per pass, transcripts at 2 per tick.

**Limits:** Strato web PHP 240 s; ganesh cron every 300 s with `curl --max-time 55`; the sweep half
takes no lock, so a tick must end well inside 300 s.

## Design

- `MAINTENANCE_TICK_SECONDS` (`backend/.env`: 25) → parameter `maintenance_tick_seconds`, wired
  on `MaintenanceTick::$tickWindowSeconds` (not a global bind). Strato's example env sets 150.
- Image verification moves from `MaintenanceTick` into `FollowUpFetchSweeps` (it is a fetch ingest
  left for later, like link cards and transcripts). This keeps `MaintenanceTick` at 9 constructor
  parameters (PHPMD) and the tick's order unchanged: images, link cards, transcripts.
  `FollowUpFetchesReportModel` gains `imageVerification`; `MaintenanceSweepsModel` loses it;
  `MaintenanceTickJson` reads it from the new place, so the response shape is unchanged.
- `ImageVerificationSweep::verifyDue()` becomes `verifyDueBefore(\DateTimeImmutable $deadline)` on
  an injected `ClockInterface` (no `microtime`). Cap 25 → 100 per pass.
- `FollowUpFetchSweeps` gives images half of what is left before the tick deadline, at most 45 s,
  so the sweeps after it keep a share on a 25 s install. Transcripts 2 → 10 per tick (40 s, well
  inside the 5 min claim lease). Link cards stay at 2.
- `VerifyPendingImagesHandler` (worker, every minute) keeps a 15 s pass: `now + 15 s`.

## Tests

- `ImageVerificationSweepTest`: passes a deadline; a passed deadline verifies nothing; downloads
  that advance a `MockClock` stop at the deadline.
- `FollowUpFetchSweepsTest`: images get half the window (50 s window, 30 s per image → 1) and at
  most 45 s (1000 s window → 2).
- `MaintenanceTickTest`, `MaintenanceTickJsonTest`, `VerifyPendingImagesHandlerTest`: new wiring.

## Docs / ops

- `deploy/strato/.env.local.example` and `deploy/strato/README.md`: `MAINTENANCE_TICK_SECONDS=150`.
- Operator (PR body): add the line to Strato's `shared/.env.local`; raise the ganesh script's
  `--max-time` to 200; watch one tick end to end, since an Apache/FastCGI cut-off below 240 s is
  unmeasured.
