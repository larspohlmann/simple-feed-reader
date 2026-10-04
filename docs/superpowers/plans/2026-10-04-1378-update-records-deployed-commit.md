# update.sh decides from the deployed commit — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A failed `update.sh` run no longer makes the next run report "Already on the latest release".

**Architecture:** `update.sh` checks the release tag out before it rebuilds the stacks, so the checkout is no evidence of what runs. A git-ignored file `.installed-commit` in the repo root names the commit the stacks were last brought up from. Only a successful update or install writes it. `update.sh` compares the target tag's commit with it; no file means "not known to be current", so the update runs (`prod-start.sh` is idempotent).

**Tech Stack:** bash (3.2-safe: ganesh runs macOS 12's `/bin/bash` via `env`), git, shellcheck, the `scripts/test/*.test.sh` harness run by CI's "Shell scripts" job.

**Spec:** GitHub issue #1378.

## Global Constraints

- Every script stays shellcheck-clean (`shellcheck scripts/*.sh scripts/test/*.sh` fails CI on any finding).
- Bash 3.2-compatible: no associative arrays, no `${var,,}`, no `mapfile`.
- The record is written only after every stack step succeeded; a failed run leaves the previous record in place.
- Commit format `fix(#1378): …`.

---

### Task 1: Record helpers in lib.sh, with their test

**Files:**
- Modify: `scripts/lib.sh` (after `current_version`, ~line 174)
- Create: `scripts/test/installed-commit.test.sh`
- Modify: `.github/workflows/ci.yml` (a step after `wait-for-php-ready`)
- Modify: `.gitignore`

**Interfaces:**
- Produces: `record_installed_commit` (writes `git rev-parse HEAD` to `${REPO_ROOT}/.installed-commit`), `installed_version` (prints the record's release tag, `(unreleased)` for an untagged record, `(unknown)` without one), `runs_ref <ref>` (status 0 only when the record equals `<ref>`'s commit).

- [ ] **Step 1: Write the failing test** — `scripts/test/installed-commit.test.sh`: a throwaway git repo with commits tagged `v1.0.0` and `v1.1.0` plus one untagged commit; `REPO_ROOT` points at it after sourcing `lib.sh`. Cases:
  - no record → `runs_ref v1.1.0` fails, `installed_version` is `(unknown)`;
  - checkout on `v1.1.0` with no record (the failed update of #1378) → `runs_ref v1.1.0` fails;
  - record written on `v1.0.0`, checkout moved to `v1.1.0` → `runs_ref v1.1.0` fails, `installed_version` is `v1.0.0`;
  - record written on `v1.1.0` → `runs_ref v1.1.0` succeeds, `installed_version` is `v1.1.0`;
  - record written on the untagged commit → `installed_version` is `(unreleased)`;
  - `runs_ref no-such-ref` fails without exiting the shell.
- [ ] **Step 2: Run it** — `scripts/test/installed-commit.test.sh`; expected: fails, `record_installed_commit: command not found`.
- [ ] **Step 3: Implement** in `scripts/lib.sh`:

```bash
# --- what the stacks run ----------------------------------------------------
# update.sh checks the release out before it rebuilds the stacks, so after a
# failed build the checkout names a release that never ran (#1378). Only a
# finished update or install writes this record.
installed_commit_file() { printf '%s/.installed-commit\n' "${REPO_ROOT}"; }

record_installed_commit() {
  git -C "${REPO_ROOT}" rev-parse HEAD > "$(installed_commit_file)"
}

installed_commit() {
  cat "$(installed_commit_file)" 2>/dev/null || true
}

# '(unknown)' is an install from before #1378: it has no record yet.
installed_version() {
  local commit
  commit=$(installed_commit)
  if [ -z "${commit}" ]; then
    echo '(unknown)'
    return 0
  fi
  git -C "${REPO_ROOT}" describe --tags --exact-match "${commit}" 2>/dev/null || echo '(unreleased)'
}

runs_ref() {
  local commit
  commit=$(installed_commit)
  [ -n "${commit}" ] \
    && [ "${commit}" = "$(git -C "${REPO_ROOT}" rev-parse --verify --quiet "$1^{commit}")" ]
}
```

  `.gitignore`: `/.installed-commit` with a one-line comment. CI: a `installed-commit` step running the test, with a short comment in the style of its neighbours.
- [ ] **Step 4: Run** the test and `shellcheck scripts/lib.sh scripts/test/installed-commit.test.sh`; expected: PASS, no findings.
- [ ] **Step 5: Commit** — `fix(#1378): record the commit the stacks were brought up from`.

### Task 2: update.sh and the installers use the record

**Files:**
- Modify: `scripts/update.sh` (the `current=` line, the release check, before `ok "Updated …"`)
- Modify: `scripts/install.sh` (after `prod-start.sh`)
- Modify: `scripts/install-dev.sh` (after `bring_up_stack`)

**Interfaces:**
- Consumes: `record_installed_commit`, `installed_version`, `runs_ref` from Task 1.

- [ ] **Step 1:** `update.sh`: `current=$(installed_version)`; the release check becomes `if runs_ref "${target}"; then ok "Already on the latest release (${target}). Nothing to do."; exit 0; fi`; call `record_installed_commit` right before `ok "Updated ${current} -> ${target}."` (after the "no installed stack" exit, so a checkout-only run records nothing).
- [ ] **Step 2:** `install.sh`: `record_installed_commit` straight after `SFR_DEFER_SUMMARY=1 "${REPO_ROOT}/scripts/prod-start.sh"`. `install-dev.sh`: straight after `bring_up_stack`.
- [ ] **Step 3: Verify the whole flow** in a scratch clone with `docker`/`compose`/`prod-start.sh` stubbed is not possible without editing the scripts, so verify by reading plus the sandboxed sequence: in a throwaway clone of this repo at the branch, run the `runs_ref`/`installed_version` decision exactly as `update.sh` calls it (no record → update runs; record on target → "Nothing to do"). Run all `scripts/test/*.test.sh` and `shellcheck scripts/*.sh scripts/test/*.sh`.
- [ ] **Step 4: Commit** — `fix(#1378): decide "already current" from the deployed commit`.

### Task 3: PR

- [ ] Push `fix/1378-update-records-deployed-commit`, open a PR into `develop` with `Closes #1378`, note for ganesh: the first `update.sh` after this lands re-runs the update once (no record yet).
