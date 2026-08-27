# Worktree-Safe Testing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make frontend and e2e testing stop failing silently or misleadingly when work happens in a git worktree (a `+` in the on-disk path) or against a Docker stack owned by a different checkout.

**Architecture:** Three independent hardening steps. (1) Replace jest's `<rootDir>`-anchored ignore patterns with plain path fragments that survive a `+` in the path. (2) Document the stack-ownership convention. (3) Add one shared bash preflight (`backend/bin/e2e-preflight.sh`) that inspects the running `simple-feed-reader` php container's `/app` mount source and fails fast when it does not match the current checkout; `backend/bin/e2e.sh` sources it and `frontend/e2e/global-setup.ts` execs it.

**Tech Stack:** Jest 30 (`frontend/jest.config.ts`), Bash (`backend/bin/e2e.sh`), Playwright + TypeScript (`frontend/e2e/global-setup.ts`), Docker Compose (`docker-compose.yml`, project name pinned to `simple-feed-reader`).

**Spec:** GitHub issue [#615](https://github.com/larspohlmann/simple-feed-reader/issues/615) — "Stabilize testing inside git worktrees". No separate design doc; the issue is the spec, and the one open decision (guard structure = shared bash helper; scope = items 1–3 only) was settled with the user before this plan.

## Global Constraints

- **Branch:** `fix/615-worktree-safe-testing` off `develop` (git-flow; issue number embedded).
- **Commit message format:** `type(#615): summary` — the issue number is the scope.
- **Shell lint:** CI shellcheck fails on ANY finding; use guard clauses, never `A && B || C`. The script must stay `set -euo pipefail`-clean and run on macOS's default bash 3.2.
- **Bash 3.2 compatibility:** No `${var,,}`, no associative arrays, no `mapfile`. `backend/bin/e2e.sh` already targets bash 3.2 — match it.
- **Frontend lint gate:** `npm run check` = `lint && format:check && stylelint && typecheck:spec && jest`. Prettier is 100-column. Any TypeScript added to `global-setup.ts` must pass ESLint + Prettier + `typecheck:spec`.
- **Out of scope (recorded, not planned):** true per-worktree isolation (parametrized `COMPOSE_PROJECT_NAME` + port offsets + per-port certs). Do not add it.
- **Do NOT run `docker compose down -v`** at any point — it deletes the MySQL volume.

---

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `frontend/jest.config.ts` | Modify | Fragment-based `testPathIgnorePatterns`, immune to a `+` in the path. |
| `frontend/jest-ignore-patterns.spec.ts` | Create | Regression test proving the patterns match a `+`-containing worktree path. |
| `backend/bin/e2e-preflight.sh` | Create | Shared ownership guard: resolve the running php container, read its `/app` mount source, fail if it is not the current `backend/`. |
| `backend/bin/e2e.sh` | Modify | Source and call the guard right after the health check. |
| `frontend/e2e/global-setup.ts` | Modify | Exec the guard before the fixture steps; throw on ownership mismatch, soft-skip when no stack is found. |
| `docs/local-docker.md` | Modify | New "Gotchas" entry: e2e is black-box against the single shared stack; run it from the stack-owning checkout. |
| `CLAUDE.md` | Modify | One-line pointer to the convention in the e2e testing notes. |

**Detection mechanism (why `docker inspect`, not `docker compose config`):** `docker-compose.yml` pins `name: simple-feed-reader`, but the `./backend` / `./frontend` bind mounts resolve against whatever directory ran `docker compose up`. The `e2e.sh` health check hits `https://localhost:8443`, a fixed port that a wrong-checkout stack answers just as well — so the health check cannot detect the mismatch. `docker compose config` reads the *local* compose file (what the current checkout *would* mount), not what is *running*. Only inspecting the live container reveals the actual host path. Verified probe on the current host:

```
docker inspect <php-container> --format '{{range .Mounts}}{{.Destination}} <- {{.Source}}{{"\n"}}{{end}}'
# /app <- /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
```

The container is resolved by compose label, not the fragile `-1` suffix:

```
docker ps -q --filter label=com.docker.compose.project=simple-feed-reader --filter label=com.docker.compose.service=php
```

---

## Task 1: Worktree-safe jest ignore patterns

**Files:**
- Modify: `frontend/jest.config.ts:12`
- Test: `frontend/jest-ignore-patterns.spec.ts` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing other tasks depend on. Self-contained.

**Background:** `testPathIgnorePatterns` are regex, matched against the absolute test path. `<rootDir>` expands to the literal checkout path. In a worktree that path contains `+` (branch `fix/612-x` → `.../worktrees/fix+612-x/`), and `+` is a regex metachar (`e+` = "one or more `e`"), so the anchored `<rootDir>/e2e/` no longer matches the real path. Jest then stops ignoring `e2e/` and runs the Playwright specs as jest suites — the "26 failed suites, all Playwright" symptom. Matching on the bare fragment `/e2e/` is immune to the `+` and is strictly more correct in the main checkout too. There is no `src/**/e2e/` or nested `node_modules` test path for the fragment to match by accident.

- [ ] **Step 1: Write the failing test**

Create `frontend/jest-ignore-patterns.spec.ts`. It imports the real config and asserts the patterns ignore a `+`-containing worktree path. This test lives at the frontend root (a `.spec.ts`, so `jest` picks it up) and does not need Angular's testbed.

```typescript
import jestConfig from './jest.config';

// Regression guard for #615: in a git worktree the checkout path contains a
// literal '+', a regex metachar. Anchored '<rootDir>/e2e/' patterns stop
// matching there and jest runs the Playwright specs. Fragment patterns are
// immune. We prove the patterns match a realistic worktree path.
describe('jest testPathIgnorePatterns (#615)', () => {
  const patterns = (jestConfig.testPathIgnorePatterns ?? []).map((p) => new RegExp(p));
  const worktreeE2ePath =
    '/Users/dev/project/.git/worktrees/fix+612-x/frontend/e2e/auth-smoke.spec.ts';
  const worktreeNodeModulesPath =
    '/Users/dev/project/.git/worktrees/fix+612-x/frontend/node_modules/pkg/some.spec.ts';
  const realSpecPath =
    '/Users/dev/project/.git/worktrees/fix+612-x/frontend/src/app/reader/reader.spec.ts';

  it('ignores the e2e directory even when the path contains a "+"', () => {
    expect(patterns.some((re) => re.test(worktreeE2ePath))).toBe(true);
  });

  it('ignores node_modules even when the path contains a "+"', () => {
    expect(patterns.some((re) => re.test(worktreeNodeModulesPath))).toBe(true);
  });

  it('does not ignore a real unit spec under src/', () => {
    expect(patterns.some((re) => re.test(realSpecPath))).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run inside the frontend container (always run frontend jest in Docker):

```bash
docker compose exec -T frontend npx jest jest-ignore-patterns.spec.ts
```

Expected: FAIL. With the current `<rootDir>`-anchored patterns, `<rootDir>` expands to the container's `/app` (no `+`), so neither anchored pattern matches the simulated worktree path — the first two assertions fail.

- [ ] **Step 3: Apply the fix**

In `frontend/jest.config.ts`, change line 12 from:

```typescript
  testPathIgnorePatterns: ['<rootDir>/node_modules/', '<rootDir>/e2e/'],
```

to:

```typescript
  // Path fragments, not <rootDir>-anchored: in a git worktree the checkout
  // path contains a literal '+' (a regex metachar), which breaks anchored
  // matches and makes jest try to run the Playwright specs (#615).
  testPathIgnorePatterns: ['/node_modules/', '/e2e/'],
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose exec -T frontend npx jest jest-ignore-patterns.spec.ts
```

Expected: PASS (all three assertions).

- [ ] **Step 5: Run the full frontend check to confirm no e2e specs leak in**

```bash
docker compose exec -T frontend npm run check
```

Expected: PASS. The Playwright specs under `e2e/` are still ignored; the suite count is unchanged from before the fix.

- [ ] **Step 6: Commit**

```bash
git add frontend/jest.config.ts frontend/jest-ignore-patterns.spec.ts
git commit -m "fix(#615): make jest ignore patterns worktree-safe"
```

---

## Task 2: Shared e2e ownership preflight guard

**Files:**
- Create: `backend/bin/e2e-preflight.sh`

**Interfaces:**
- Consumes: nothing.
- Produces: an executable script that both later tasks invoke.
  - Sourced by `e2e.sh`, which calls the function `assert_stack_owns_checkout "<repo-root>"`.
  - Exec'd directly by `global-setup.ts` as `bash backend/bin/e2e-preflight.sh "<repo-root>"`.
  - **Contract:** exit `0` = this checkout owns the running stack, or no stack is running (the caller decides what a missing stack means). exit `1` = a `simple-feed-reader` php container IS running but mounts a DIFFERENT checkout; stderr names the owning path. exit `2` = docker is not usable (binary missing or daemon unreachable).

**Background:** One implementation, two callers. The script must be usable both as a sourced library (so `e2e.sh` gets the function) and as a standalone entry point (so `global-setup.ts` can `bash …` it). The pattern: define the function, then run it from `"$@"` only when the file is executed directly, guarded by comparing `${BASH_SOURCE[0]}` to `$0`.

- [ ] **Step 1: Create the guard script**

Create `backend/bin/e2e-preflight.sh`:

```bash
#!/usr/bin/env bash
# Preflight guard for the e2e suites (#615).
#
# Both e2e suites are black-box tests against the single shared Docker stack,
# whose project name is pinned to "simple-feed-reader". The stack's code under
# test comes from the ./backend and ./frontend bind mounts, which resolve
# against whatever directory last ran `docker compose up`. So a stack started
# from a different checkout (e.g. a git worktree) answers the same :8443 and
# the same `docker compose exec`, and the suite silently tests the WRONG code.
#
# This guard reads the running php container's /app mount source and compares
# it to the current checkout's backend/. Mismatch fails fast and names the
# owning path, turning a silent wrong-checkout run into a legible error.
#
# Usage:
#   source backend/bin/e2e-preflight.sh && assert_stack_owns_checkout "$REPO_ROOT"
#   bash   backend/bin/e2e-preflight.sh "$REPO_ROOT"
#
# Exit codes (of the function and of direct invocation):
#   0  this checkout owns the running stack, OR no stack is running
#   1  a stack is running but mounts a different checkout (message names it)
#   2  docker is not usable (binary missing or daemon unreachable)
set -euo pipefail

# Resolve a path to its canonical form without realpath(1), which is absent on
# stock macOS. `cd … && pwd -P` follows symlinks and normalises, and works on
# bash 3.2. A missing directory prints nothing and returns non-zero.
canonical_path() {
  local target="$1"
  if [ ! -d "$target" ]; then
    return 1
  fi
  ( cd "$target" && pwd -P )
}

# stdout: the host path the running stack's php /app mount points at, or empty
# if no such container is running. Returns 2 if docker itself is unusable.
running_stack_backend_mount() {
  if ! command -v docker >/dev/null 2>&1; then
    return 2
  fi
  local container
  if ! container="$(docker ps -q \
    --filter label=com.docker.compose.project=simple-feed-reader \
    --filter label=com.docker.compose.service=php 2>/dev/null)"; then
    return 2
  fi
  if [ -z "$container" ]; then
    # No running php container: not an ownership problem. Empty stdout, ok.
    return 0
  fi
  docker inspect "$container" \
    --format '{{range .Mounts}}{{if eq .Destination "/app"}}{{.Source}}{{end}}{{end}}'
}

# Fail fast if the running stack is owned by a different checkout than $1.
assert_stack_owns_checkout() {
  local repo_root="$1"
  local expected_backend mounted_backend rc

  expected_backend="$(canonical_path "$repo_root/backend" || true)"

  set +e
  mounted_backend="$(running_stack_backend_mount)"
  rc=$?
  set -e

  if [ "$rc" -eq 2 ]; then
    echo "==> Preflight: docker not usable; skipping the stack-ownership check." >&2
    return 2
  fi
  if [ -z "$mounted_backend" ]; then
    # No stack running. The caller decides whether that is fatal.
    return 0
  fi

  mounted_backend="$(canonical_path "$mounted_backend" || printf '%s' "$mounted_backend")"

  if [ "$mounted_backend" = "$expected_backend" ]; then
    return 0
  fi

  local owning_root="${mounted_backend%/backend}"
  echo "ERROR: the running 'simple-feed-reader' Docker stack is owned by a different checkout." >&2
  echo "       stack mounts:  $mounted_backend" >&2
  echo "       you are in:    $expected_backend" >&2
  echo "       The e2e suites are black-box against that single shared stack, so this run" >&2
  echo "       would test the OTHER checkout. Run e2e from the owning checkout:" >&2
  echo "         $owning_root" >&2
  echo "       or take ownership here first:  (cd '$repo_root' && docker compose up -d)" >&2
  return 1
}

# Run only when executed directly, not when sourced (bash 3.2 safe).
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  assert_stack_owns_checkout "${1:?usage: e2e-preflight.sh <repo-root>}"
fi
```

- [ ] **Step 2: Make it executable**

```bash
chmod +x backend/bin/e2e-preflight.sh
```

- [ ] **Step 3: Verify it passes against the current (owning) checkout**

With the stack up from this checkout:

```bash
bash backend/bin/e2e-preflight.sh "$(cd backend/.. && pwd)"; echo "exit=$?"
```

Expected: no output, `exit=0` (this checkout owns the stack).

- [ ] **Step 4: Verify it fails against a foreign path (simulate a wrong-checkout run)**

Point the guard at a repo root whose `backend/` is NOT what the stack mounts. Use any other directory that has a `backend` subdir, or a throwaway one:

```bash
mkdir -p /tmp/fake-checkout/backend
bash backend/bin/e2e-preflight.sh /tmp/fake-checkout; echo "exit=$?"
rmdir /tmp/fake-checkout/backend /tmp/fake-checkout
```

Expected: the multi-line ERROR block naming the real owning path, `exit=1`.

- [ ] **Step 5: Verify shellcheck is clean**

```bash
shellcheck backend/bin/e2e-preflight.sh
```

Expected: no findings. (CI fails on any.) If `shellcheck` is not installed locally, note it and rely on CI; do not skip silently.

- [ ] **Step 6: Commit**

```bash
git add backend/bin/e2e-preflight.sh
git commit -m "feat(#615): add shared e2e stack-ownership preflight guard"
```

---

## Task 3: Wire the guard into `backend/bin/e2e.sh`

**Files:**
- Modify: `backend/bin/e2e.sh:22-29`

**Interfaces:**
- Consumes: `assert_stack_owns_checkout "$REPO_ROOT"` from Task 2's `e2e-preflight.sh`.
- Produces: nothing new.

**Background:** `e2e.sh` already computes `REPO_ROOT` (line 21) and health-checks `:8443` (lines 24-29). The ownership guard belongs immediately after the health check: only run it once we know a stack is up, and abort before any `docker compose exec` touches the wrong checkout's database. In `e2e.sh` a running-but-wrong-checkout stack is fatal (the whole point), so a `return 1` from the guard must stop the script. The guard's `return 2` (docker unusable) cannot happen here — the health check already proved the stack reachable — but source the script and let `set -e` propagate a `1`.

- [ ] **Step 1: Source the guard and call it after the health check**

In `backend/bin/e2e.sh`, immediately after the health-check block that ends at line 29 (`fi`), and before the "Purging leftover e2e fixture accounts" step, insert:

```bash
# #615: the stack is up, but is it OUR stack? The project name is pinned, so a
# stack started from another checkout (a worktree) answers the same :8443 and
# the same `docker compose exec`. Fail fast rather than silently seed and test
# the other checkout's database.
# shellcheck source=e2e-preflight.sh
source "$BACKEND_DIR/bin/e2e-preflight.sh"
echo "==> Verifying this checkout owns the running stack ..."
assert_stack_owns_checkout "$REPO_ROOT"
```

Note: `source` re-runs `e2e-preflight.sh`'s top-level `set -euo pipefail` — harmless, it matches `e2e.sh`'s own line 17. The `BASH_SOURCE`/`$0` guard means sourcing does NOT auto-run the check; the explicit `assert_stack_owns_checkout` call does, so it runs exactly once with the intended argument.

- [ ] **Step 2: Confirm the happy path still runs**

From the owning checkout, with the stack up:

```bash
cd backend && composer e2e -- --filter DoesNotExistFilterXyz
```

Expected: the new "Verifying this checkout owns the running stack ..." line prints, the guard passes silently, the suite runs (and reports 0 tests for the bogus filter). The point is that the guard did not abort the owning run.

- [ ] **Step 3: Confirm it aborts a foreign run**

Temporarily fake a foreign root by copying the invocation with a doctored `REPO_ROOT`. The cleanest check without a second checkout: run the guard directly the way `e2e.sh` will, against a fake root, and confirm exit 1 (already proven in Task 2 Step 4). No need to corrupt the real stack.

- [ ] **Step 4: Commit**

```bash
git add backend/bin/e2e.sh
git commit -m "fix(#615): fail composer e2e fast on a wrong-checkout stack"
```

---

## Task 4: Wire the guard into Playwright `global-setup.ts`

**Files:**
- Modify: `frontend/e2e/global-setup.ts`

**Interfaces:**
- Consumes: `backend/bin/e2e-preflight.sh` (exec'd as a subprocess).
- Produces: nothing new.

**Background:** `global-setup.ts` currently runs every docker step best-effort and NEVER throws, so a host without Docker still runs the specs (they skip their infra-dependent steps). The ownership guard changes that ONE case: a running stack owned by a different checkout must throw and abort the run, while a missing stack / no Docker must still soft-skip. The guard's exit codes make this precise: exit `1` → throw; exit `0` or `2` → proceed as before. Run the guard first, before the fixture loop, so a foreign stack aborts before any `docker compose exec` seeds the wrong database.

- [ ] **Step 1: Add the guard call at the top of `globalSetup`**

Edit `frontend/e2e/global-setup.ts`. Add `execFileSync` is already imported. Insert an ownership check before the `FIXTURE_COMMANDS` loop. The repo root is two levels up from `frontend/e2e/`. Replace the body of `globalSetup` so the guard runs first:

```typescript
export default function globalSetup(): void {
  const repoRoot = resolve(__dirname, '..', '..');
  const composeFile = resolve(repoRoot, 'docker-compose.yml');
  const preflightScript = resolve(repoRoot, 'backend', 'bin', 'e2e-preflight.sh');

  assertStackOwnsCheckout(preflightScript, repoRoot);

  for (const consoleArgs of FIXTURE_COMMANDS) {
    try {
      execFileSync(
        'docker',
        ['compose', '-f', composeFile, 'exec', '-T', 'php', 'bin/console', ...consoleArgs],
        { stdio: 'inherit' },
      );
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      console.warn(
        `[global-setup] Skipping e2e fixture step "${consoleArgs.join(' ')}": ${reason}`,
      );
    }
  }
}
```

Add the helper below `globalSetup` (or above it). It distinguishes a hard ownership failure (guard exit 1) from a soft skip (docker missing / no stack):

```typescript
// #615: the Docker project name is pinned, so a stack started from another
// checkout answers the same `docker compose exec`. The shared bash guard exits
// 1 when the running stack mounts a DIFFERENT checkout (hard failure), 2 when
// docker is unusable, and 0 when this checkout owns the stack or none runs.
// Only exit 1 aborts the run; everything else stays best-effort like the
// fixture steps, so a host without Docker still runs the specs.
function assertStackOwnsCheckout(preflightScript: string, repoRoot: string): void {
  try {
    execFileSync('bash', [preflightScript, repoRoot], { stdio: 'inherit' });
  } catch (error) {
    const status = (error as { status?: number }).status;
    if (status === 1) {
      throw error;
    }
    const reason = error instanceof Error ? error.message : String(error);
    console.warn(`[global-setup] Stack-ownership check skipped: ${reason}`);
  }
}
```

- [ ] **Step 2: Typecheck the spec sources**

```bash
docker compose exec -T frontend npm run typecheck:spec
```

Expected: PASS. `execFileSync`'s thrown error carries a numeric `status`; the `(error as { status?: number })` cast is the minimal typing Playwright/Node needs here.

- [ ] **Step 3: Lint and format the changed file**

```bash
docker compose exec -T frontend npx eslint e2e/global-setup.ts
docker compose exec -T frontend npx prettier --check e2e/global-setup.ts
```

Expected: both clean. If Prettier complains, run `npx prettier --write e2e/global-setup.ts` and re-check.

- [ ] **Step 4: Confirm the owning-checkout run passes the guard**

From this (owning) checkout with the stack up:

```bash
cd frontend && npm run e2e -- --grep "auth-smoke"
```

Expected: global setup runs, the guard passes silently, the fixture steps run, and the smoke executes. The guard did not abort the owning run. (The specific spec name is illustrative; any single existing spec works.)

- [ ] **Step 5: Commit**

```bash
git add frontend/e2e/global-setup.ts
git commit -m "fix(#615): fail npm run e2e fast on a wrong-checkout stack"
```

---

## Task 5: Document the stack-ownership convention

**Files:**
- Modify: `docs/local-docker.md` (§7 "Gotchas", around line 231)
- Modify: `CLAUDE.md` (the e2e testing notes, around line 166 / the Testing section around line 225)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing. Documentation only.

**Background:** The convention is the honest explanation of why worktrees hurt here, and it matches the standing "work in place, no worktrees" preference. `docs/local-docker.md` §7 is the gotchas home; `CLAUDE.md` gets a one-line pointer, not the whole explanation (keep it terse — it is the always-loaded index).

- [ ] **Step 1: Add the gotcha to `docs/local-docker.md`**

Under §7 "Gotchas", add an entry. Use the file's existing bullet style:

```markdown
- **Run e2e from the checkout that owns the stack.** Both e2e suites are
  black-box tests against the single shared Docker stack, whose project name is
  pinned to `simple-feed-reader`. The code under test comes from the `./backend`
  and `./frontend` bind mounts, which resolve against whatever directory last
  ran `docker compose up`. So from a second checkout (a git worktree), `composer
  e2e` and `npm run e2e` reach the same `:8443` and the same `docker compose
  exec` and silently test the *other* checkout's code. Edit-and-e2e must happen
  in the checkout that ran `docker compose up`. A preflight guard
  (`backend/bin/e2e-preflight.sh`, wired into both suites) now fails fast and
  names the owning path when the running stack mounts a different checkout, so
  this is a loud error rather than a silent wrong result (#615).
```

- [ ] **Step 2: Add the one-line pointer to `CLAUDE.md`**

In the "Run e2e through `composer e2e`" gotcha area (around line 166) or the Testing section, add one bullet:

```markdown
- **e2e is black-box against the single shared Docker stack.** Run it only from
  the checkout that ran `docker compose up`; a run from another checkout (a
  worktree) tests the wrong code. Both suites preflight-guard against this and
  fail fast naming the owning path (#615, `backend/bin/e2e-preflight.sh`).
```

- [ ] **Step 3: Verify the docs read correctly**

```bash
git diff docs/local-docker.md CLAUDE.md
```

Expected: both additions present, styled like their neighbours, no stray formatting.

- [ ] **Step 4: Commit**

```bash
git add docs/local-docker.md CLAUDE.md
git commit -m "docs(#615): document the e2e stack-ownership convention"
```

---

## Final verification

- [ ] **Frontend gate passes identically:** `docker compose exec -T frontend npm run check` — same suite count as before the branch, all green.
- [ ] **Owning-checkout e2e still works end to end:** `cd backend && composer e2e` completes against this checkout; the new ownership line prints and does not abort.
- [ ] **Guard is loud on a mismatch:** `bash backend/bin/e2e-preflight.sh /tmp/fake-checkout` (with `/tmp/fake-checkout/backend` present) exits 1 with the owning-path message.
- [ ] **shellcheck clean:** `shellcheck backend/bin/e2e-preflight.sh backend/bin/e2e.sh`.
- [ ] **Acceptance re-read:** all three issue checkboxes satisfied — fragment jest patterns, documented convention, fail-fast on a foreign stack.
- [ ] **Open the PR** into `develop` with `Closes #615`, then verify the issue closes on merge.

---

## Self-Review notes

- **Spec coverage:** Issue item 1 (jest) → Task 1. Item 2 (convention docs) → Task 5. Item 3 (preflight in both `e2e.sh` and `global-setup.ts`) → Tasks 2–4. "Out of scope" isolation explicitly excluded (Global Constraints). All three acceptance checkboxes map to a task and to the Final verification list.
- **Type/name consistency:** the bash function `assert_stack_owns_checkout` is named identically in Task 2 (definition), Task 3 (sourced call), and its exec entry point; the TS helper `assertStackOwnsCheckout` is defined and called in Task 4 only. Exit-code contract (0/1/2) is stated in Task 2 and consumed unchanged in Tasks 3–4.
- **The `+`-path habit (issue note):** the issue notes that the `npx jest` → global-babel-jest fallback in a `+` path is a habit, not config, and needs no code change. This plan uses `docker compose exec -T frontend npx jest …`, which runs inside the container where the path has no `+`, so the habit does not bite. No task addresses it because there is nothing to change — recorded here so an executor does not go looking.
