# #1361 For You progress: time model with a real-progress floor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax.

**Goal:** The For You bar keeps following the time model (`elapsed / (elapsed + eta)`, #668) but never shows less than the real progress.

**Architecture:** Steps differ in length, and the final consolidate call can be long. So the real progress is phase-weighted. `PhaseDurationsModel::finishedShare()` returns the finished batches' time over the predicted total. The estimator, renamed `RecommendationRunForecaster`, returns a `RunForecastModel` (ETA and finished share), and the status JSON gains `finishedShare`. The client bar is `min(0.99, max(timeShare, finishedShare))`. Without history it falls back to `batchesDone / batchesTotal`. The pure helpers live in `reader/state/run-progress.ts`.

**Tech Stack:** Angular 20 signals, Jest (in the Docker frontend container).

## Global Constraints

- Jest only via `docker compose exec -T frontend npm test`, one process at a time.
- `npm run check` must pass (Prettier 100 cols).
- The existing time-model, stall and 429 tests stay green unchanged.

---

### Task 1: Batch floor under the time model

**Files:**
- Modify: `frontend/src/app/reader/state/recommendations.service.ts`
- Test: `frontend/src/app/reader/state/recommendations.service.spec.ts`

- [ ] **Step 1: Failing tests.**
  - No ETA, 9 of 10 running → `progress()` is 0.9.
  - 3 of 4 with elapsed 20 and ETA 60 (time model at 0.25) → 0.75.
  - 1 of 4 with elapsed 60 and ETA 20 (time model at 0.75) → 0.75, so time leads when it is ahead.
  - 4 of 4 still running → 0.99.
- [ ] **Step 2:** Run Jest on the spec. The new tests fail.
- [ ] **Step 3:** Implement as in Architecture.
- [ ] **Step 4:** Run Jest on the spec, then `npm run check` in the container.
- [ ] **Step 5:** Commit `fix(#1361): floor the For You bar at the finished-batch share`.
