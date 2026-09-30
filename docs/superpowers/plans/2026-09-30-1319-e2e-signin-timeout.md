# e2e admin sign-in wait survives parallel load — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; one commit). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Stop the full local Playwright run from flaking on sign-in (#1319).

**Architecture:** 26 specs share the line `await expect(sidebar.or(loginError)).toBeVisible();`. It waits for the sidebar or the login error after sign-in and uses Playwright's default 5 s timeout. With 5 workers signing in at once, sign-in sometimes takes longer, so the line gets an explicit 15 s timeout. The helpers exist in 17 variants, so merging them is out of scope.

**Tech Stack:** Playwright in `frontend/e2e/`.

## Global Constraints

- Branch `fix/1319-e2e-signin-timeout` off `develop`. Commit format `type(#1319): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Playwright is not in the CI gate. Verify locally against the Docker stack, following the Playwright run rules in memory:
  - stop the worker;
  - `export OTEL_PHP_AUTOLOAD_ENABLED=false`;
  - clear `cache.rate_limiter` and `altcha.replay.cache` between runs;
  - restart the worker afterwards.

---

### Task 1: 15 s sign-in wait

**Files:** the 26 files listed by `grep -ln "sidebar.or(loginError)).toBeVisible()" frontend/e2e`

- [ ] **Step 1:** Replace the line in all 26 files:

```bash
grep -rl "sidebar.or(loginError)).toBeVisible()" frontend/e2e | xargs sed -i '' 's/sidebar.or(loginError)).toBeVisible()/sidebar.or(loginError)).toBeVisible({ timeout: 15_000 })/'
```

Verify:
- `grep -rn "sidebar.or(loginError)).toBeVisible()" frontend/e2e` prints nothing.
- `grep -rc "timeout: 15_000 })" frontend/e2e | grep -v ':0' | wc -l` prints 26.

- [ ] **Step 2:** Run `docker compose exec -T frontend npm run check`; Prettier and ESLint cover `e2e/`.
- [ ] **Step 3:** Run the full Playwright suite (`cd frontend && npx playwright test --reporter=line`) twice, following the rules above. Both runs are expected to show 127 passed. If a different spec still fails on sign-in, report it and don't raise the timeout further.
- [ ] **Step 4:** Commit `test(#1319): admin sign-in wait gets 15 s under parallel load`. Open the PR (`Closes #1319`) and merge when green.

## Amendments from implementation

1. Step 1's second check prints 28, not 26: `onboarding.spec.ts` and `boot-without-dictionary.spec.ts` already held a `timeout: 15_000 })` on another line. The edit itself touches exactly 26 files and 28 lines (two files wait twice).
