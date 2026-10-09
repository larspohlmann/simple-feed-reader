# #1462 Cap Jest Workers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A full `npm run check` inside the Docker frontend container finishes without the container being OOM-killed while `ng serve` runs.

**Architecture:** Jest starts one jsdom worker per core minus one; on a 10-CPU Docker VM that is 9 workers of a few hundred MB each. Cap the pool at half the cores and recycle a worker once it outgrows 512 MB between test files.

**Tech Stack:** Jest 30 via jest-preset-angular, Docker Compose.

**Spec:** GitHub issue #1462.

## Global Constraints

- `maxWorkers: '50%'`, `workerIdleMemoryLimit: '512MB'` — values verbatim from the issue.
- Frontend tests run inside the Docker frontend container, never two Jest runs at once.

---

### Task 1: Cap the worker pool

**Files:**
- Modify: `frontend/jest.config.ts`

- [ ] **Step 1: Record the baseline.** `docker inspect -f '{{.State.Status}} {{.State.OOMKilled}} {{.HostConfig.Memory}}' $(docker compose ps -q frontend)` and the wall-clock time of `time docker compose exec -T frontend npm test` (if it OOMs, that is the reproduction; restart with `docker compose up -d frontend`).

- [ ] **Step 2: Add the two options** to the exported config, after `testPathIgnorePatterns`:

```ts
  maxWorkers: '50%',
  workerIdleMemoryLimit: '512MB',
```

No comment: the issue and the commit carry the why.

- [ ] **Step 3: Verify.** With `ng serve` running, `time docker compose exec -T frontend npm run check; echo EXIT=$?`, grep `Test Suites:`; then `docker inspect` must show `running false` (OOMKilled=false). Note the wall-clock time.

- [ ] **Step 4: Commit**

```bash
git add frontend/jest.config.ts docs/superpowers/plans/2026-10-09-1462-cap-jest-workers.md
git commit -m "fix(#1462): cap jest workers and recycle bloated ones so the frontend container survives a full run"
```
