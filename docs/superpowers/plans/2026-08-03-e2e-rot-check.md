# Weekly E2E Rot Check — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run both e2e suites weekly in GitHub Actions against a real Docker stack, and open an issue when they rot.

**Architecture:** One new workflow, `.github/workflows/e2e-rot-check.yml`, on a weekly `schedule` plus `workflow_dispatch`. It boots the dev Docker stack with an mkcert certificate, runs the backend black-box suite (`composer e2e`) and the Playwright suite (`npm run e2e`) against it, and opens or updates a GitHub issue on failure. The all-skip trap — a green run that verified nothing — is caught by a small shell script with its own unit test, not by inline YAML.

**Tech Stack:** GitHub Actions, Docker Compose, mkcert, PHP 8.4 / PHPUnit 12, Node 22 / Playwright 1.61, `jq`, bash.

**Spec:** [docs/superpowers/specs/2026-08-03-e2e-rot-check-design.md](../specs/2026-08-03-e2e-rot-check-design.md)
**Issue:** [#96](https://github.com/larspohlmann/simple-feed-reader/issues/96)
**Branch:** `feature/96-e2e-rot-check` (already created, spec already committed)

## Global Constraints

- **Shell scripts must be shellcheck-clean at every severity.** CI runs
  `shellcheck scripts/*.sh scripts/test/*.sh …` and fails on **any** finding,
  including info-level. Never write `A && B || C` (SC2015); use an `if`.
- Every shell script starts with `#!/usr/bin/env bash` and `set -euo pipefail`.
- New scripts land in `scripts/` (flat) so the existing shellcheck glob covers
  them; their tests land in `scripts/test/`.
- Comments explain **why**, never **what**. The existing workflow files
  (`catalog-rot-check.yml`, `deploy-strato.yml`) set the standard: they justify
  a trigger choice or record a decision. Match that density.
- Backend commands run from `backend/`, frontend commands from `frontend/`.
- The workflow is **never** given a `pull_request` trigger. That is the decision
  in the spec, not an oversight.
- `docker compose down -v` deletes the developer's MySQL volume — **never put it
  in a step an engineer might copy-paste locally**. The runner is disposable and
  needs no teardown at all.

## Findings that change the spec

Three things turned up while reading the code. Each is handled by a task below;
none needs a spec rewrite, but an implementer must know them.

1. **The Docker `frontend` container is required — do not run `ng serve` on the
   runner.** `frontend/proxy.conf.json` proxies `/api` to `https://nginx`, a
   compose-network hostname that does not resolve on the host. The dev build
   uses `apiBaseUrl: ''`, so every API call goes through that proxy.
   `playwright.config.ts` hardcodes `reuseExistingServer: true` (**not**
   `!process.env.CI`), which is exactly what lets Playwright reuse the
   container's server under CI. Do not "correct" it to the `!process.env.CI`
   convention; that would break this workflow.

2. **The two suites need opposite skip rules.** Every `test.skip()` in
   `frontend/e2e/` is the fixture-missing trap, so **any** Playwright skip fails
   the job. The backend's `markTestSkipped` calls in
   `backend/tests/E2e/ReaderJourneyE2eTest.php` fire when an *external* news
   homepage is unreachable — legitimate, and outside our control. Backend skips
   must **not** fail the job.

3. **`backend/bin/e2e.sh` degrades on Linux unless `openssl.cafile` is set.**
   The script builds its CA bundle as `system bundle + mkcert root`, but reads
   the system bundle from `ini_get('openssl.cafile')`. When that ini value is
   empty — the usual case for `shivammathur/setup-php` — the bundle becomes
   *only* the mkcert root, and every outbound HTTPS fetch in the backend suite
   fails verification. Fixed by passing `ini-values` to `setup-php`, with no
   change to the script.

---

### Task 1: The all-skip guard, as a tested script

**Files:**
- Create: `scripts/assert-playwright-ran.sh`
- Create: `scripts/test/assert-playwright-ran.test.sh`
- Modify: `.github/workflows/ci.yml` (add one step that runs the new test)

**Interfaces:**
- Consumes: nothing.
- Produces: `scripts/assert-playwright-ran.sh <json-report-path>`. Exit `0` when
  `stats.expected > 0` and `stats.skipped == 0`. Exit `1` when the report is
  missing, when no test passed, or when any test skipped. Exit `2` on usage
  error. Task 4 calls it.

**Why a script and not four lines of YAML:** this guard is the whole point of
the workflow — issue #96 says a leg that skips everything looks green and
reproduces the problem it exists to solve. Logic that important gets a test.
There is precedent: `scripts/test/configure-public-url.test.sh` exists for the
same reason.

**Note on the ci.yml change:** the spec says "no change to `ci.yml`". That meant
*do not add the e2e leg there*. Adding one line so a new script test runs is
consistent with the existing `scripts` job, which already runs
`configure-public-url.test.sh`. The shellcheck glob needs no change — it already
matches `scripts/*.sh` and `scripts/test/*.sh`.

- [ ] **Step 1: Write the failing test**

Create `scripts/test/assert-playwright-ran.test.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Unit test for the all-skip guard. Issue #96 records the failure mode it
# exists to prevent: every Playwright spec calls test.skip() when the seeded
# admin is missing, so a run whose fixture setup silently failed reports
# success having verified nothing. A green suite that proves nothing is worse
# than a red one, because nobody looks at it.
#
# The guard reads Playwright's JSON reporter output, so the fixtures here are
# trimmed copies of that shape: only the fields the guard reads.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
guard="${_dir}/../assert-playwright-ran.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

write_report() {
  local path=$1 expected=$2 skipped=$3 title=$4 status=$5
  cat > "${path}" <<JSON
{
  "stats": { "expected": ${expected}, "skipped": ${skipped}, "unexpected": 0, "flaky": 0 },
  "suites": [
    {
      "title": "reader-smoke.spec.ts",
      "specs": [
        { "title": "${title}", "tests": [ { "status": "${status}" } ] }
      ]
    }
  ]
}
JSON
}

# --- a healthy run passes ----------------------------------------------------
healthy="${work}/healthy.json"
write_report "${healthy}" 36 0 'the reader shell renders' expected
if ! "${guard}" "${healthy}" > /dev/null; then
  fail 'a run with 36 passed and 0 skipped must be accepted'
fi

# --- the trap itself: everything skipped, nothing verified -------------------
all_skipped="${work}/all-skipped.json"
write_report "${all_skipped}" 0 36 'the reader shell renders' skipped
if "${guard}" "${all_skipped}" > /dev/null 2>&1; then
  fail 'a run where every spec skipped must be rejected'
fi

# The operator has to know WHICH specs skipped, or the red run is a dead end.
#
# Capture first, then grep. Piping the guard straight into grep would report
# the GUARD's exit status under `set -o pipefail`, so the assertion would
# invert: a correct rejection would read as a test failure.
rejection=$("${guard}" "${all_skipped}" 2>&1 || true)
if ! printf '%s\n' "${rejection}" | grep -q 'the reader shell renders'; then
  fail 'the rejection must name the skipped specs'
fi

# --- one skip among many passes is still a rejection -------------------------
# Partial skipping means part of the suite verified nothing, which is the same
# defect in a smaller dose. There is no legitimate skip in frontend/e2e/.
partial="${work}/partial.json"
write_report "${partial}" 35 1 'the tag dialog opens' skipped
if "${guard}" "${partial}" > /dev/null 2>&1; then
  fail 'a single skipped spec must be rejected'
fi

# --- no report at all --------------------------------------------------------
# Playwright crashing before it writes the report must not read as success.
if "${guard}" "${work}/absent.json" > /dev/null 2>&1; then
  fail 'a missing report must be rejected'
fi

# --- usage -------------------------------------------------------------------
if "${guard}" > /dev/null 2>&1; then
  fail 'no argument must be a usage error'
fi

printf 'PASS: assert-playwright-ran.sh\n'
```

Make it executable:

```bash
chmod +x scripts/test/assert-playwright-ran.test.sh
```

- [ ] **Step 2: Run the test to verify it fails**

Run from the repo root:

```bash
scripts/test/assert-playwright-ran.test.sh
```

Expected: FAIL — `scripts/assert-playwright-ran.sh: No such file or directory`.

- [ ] **Step 3: Write the guard**

Create `scripts/assert-playwright-ran.sh`:

```bash
#!/usr/bin/env bash
# Reject a Playwright run that verified nothing.
#
#   scripts/assert-playwright-ran.sh playwright-report.json
#
# Every spec in frontend/e2e/ calls test.skip() when the seeded admin is
# unavailable, so a run whose fixture setup failed exits 0 with every spec
# skipped. Playwright is right to report that as success -- nothing FAILED --
# but for a scheduled rot check it is the worst outcome: green, unattended,
# and proving nothing. #93 rotted for weeks behind exactly that kind of quiet.
#
# There is no legitimate skip in the Playwright suite, so the threshold is
# zero rather than a ratio.
set -euo pipefail

report="${1:-}"
if [ -z "${report}" ]; then
  echo "usage: $0 <playwright-json-report>" >&2
  exit 2
fi

if [ ! -f "${report}" ]; then
  echo "ERROR: no Playwright JSON report at '${report}'." >&2
  echo "ERROR: Playwright did not get far enough to write one." >&2
  exit 1
fi

expected=$(jq -r '.stats.expected // 0' "${report}")
skipped=$(jq -r '.stats.skipped // 0' "${report}")

echo "==> Playwright: ${expected} passed, ${skipped} skipped."

status=0

if [ "${expected}" -eq 0 ]; then
  echo "ERROR: no Playwright spec passed. The suite proved nothing." >&2
  status=1
fi

if [ "${skipped}" -ne 0 ]; then
  echo "ERROR: ${skipped} spec(s) skipped. In this suite a skip means the" >&2
  echo "ERROR: fixture admin was missing, so those assertions never ran:" >&2
  jq -r '
    [ .. | objects | select(has("specs")) | .specs[]
      | select(any(.tests[]?; .status == "skipped"))
      | .title
    ] | unique | .[] | "ERROR:   - " + .
  ' "${report}" >&2
  status=1
fi

exit "${status}"
```

Make it executable:

```bash
chmod +x scripts/assert-playwright-ran.sh
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
scripts/test/assert-playwright-ran.test.sh
```

Expected: `PASS: assert-playwright-ran.sh`

- [ ] **Step 5: Run shellcheck exactly as CI does**

CI fails on info-level findings, and a locally "clean" script has broken the
build before. Run the real command:

```bash
shellcheck scripts/*.sh scripts/test/*.sh docker/php/entrypoint-prod.sh docker/web/10-select-mode.sh
```

Expected: no output, exit 0. Fix any finding at any severity before continuing.

- [ ] **Step 6: Wire the test into ci.yml**

In `.github/workflows/ci.yml`, in the `scripts` job, after the
`configure_public_url` step, append:

```yaml
      # The all-skip guard for the weekly e2e rot check (#96). It decides
      # whether a Playwright run that reported success actually verified
      # anything, so it is exactly the kind of logic that must not rot itself.
      - name: assert-playwright-ran
        run: scripts/test/assert-playwright-ran.test.sh
```

- [ ] **Step 7: Commit**

```bash
git add scripts/assert-playwright-ran.sh scripts/test/assert-playwright-ran.test.sh .github/workflows/ci.yml
git commit -m "feat(#96): guard against an e2e run that skipped everything"
```

---

### Task 2: The workflow skeleton and a booted stack

**Files:**
- Create: `.github/workflows/e2e-rot-check.yml`

**Interfaces:**
- Consumes: nothing from Task 1 yet.
- Produces: a workflow named `E2E rot check`, dispatchable on any branch, whose
  job reaches a state where `https://localhost:8443/api/health` and
  `http://localhost:4200` both answer. Tasks 3–5 append steps to this job.

**Why the cron slot:** the catalog check runs `17 4 * * 1`. This one takes a
different day and a different off-the-hour minute, so the two never contend and
neither lands on the top-of-hour scheduling crowd.

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/e2e-rot-check.yml`:

```yaml
name: E2E rot check

# Scheduled only, plus manual. NEVER a pull_request trigger: this boots the
# whole Docker stack -- a MySQL image, a built PHP image, nginx, and an Angular
# dev server that installs its own node_modules on first run -- which is minutes
# of wall clock on top of every push. Issue #96 weighed that against the risk
# and chose weekly reporting over per-PR gating (2026-08-03). A red run here
# does not block a deploy; it opens an issue.
on:
  schedule:
    - cron: '41 3 * * 3'
  workflow_dispatch:

permissions:
  contents: read
  issues: write

jobs:
  e2e:
    name: Both e2e suites against a real stack
    runs-on: ubuntu-latest
    # The stack build plus two suites; well clear of normal, tight enough that a
    # wedged dev server cannot burn an hour.
    timeout-minutes: 45

    steps:
      - uses: actions/checkout@v5

      # The dev nginx config (docker/nginx/default.conf) reads these two exact
      # filenames, and docker/certs is gitignored -- TLS keys never enter the
      # repository, so the runner makes its own.
      #
      # mkcert rather than a bare openssl self-signed pair: `mkcert -install`
      # puts the root in the runner's system trust store, and backend/bin/e2e.sh
      # already builds PHP's CA bundle from `mkcert -CAROOT`. The runner then
      # behaves exactly like a developer machine and no script needs a CI
      # branch. libnss3-tools is what mkcert needs to reach the NSS store.
      - name: Generate a locally trusted certificate
        run: |
          set -euo pipefail
          sudo apt-get update
          sudo apt-get install -y mkcert libnss3-tools
          mkcert -install
          mkdir -p docker/certs
          mkcert -cert-file docker/certs/localhost.pem \
                 -key-file docker/certs/localhost-key.pem \
                 localhost 127.0.0.1 ::1

      # --wait blocks until every service is running and every service that
      # declares a healthcheck is healthy. Only mysql declares one, so the two
      # readiness gates below are not redundant with it.
      - name: Boot the stack
        run: docker compose up -d --wait

      - name: Migrate the database
        run: |
          set -euo pipefail
          docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction

      # An unreachable API is the failure this whole workflow is built to
      # notice, so it fails loudly here rather than letting every spec skip its
      # way to green further down.
      - name: Wait for the API
        run: |
          set -euo pipefail
          for _ in $(seq 1 60); do
            if curl -fsS -o /dev/null https://localhost:8443/api/health; then
              echo "==> API is up."
              exit 0
            fi
            sleep 5
          done
          echo "!!! https://localhost:8443/api/health never answered." >&2
          docker compose logs --no-color nginx php >&2
          exit 1

      # The frontend container runs `npm ci` INSIDE itself on first boot, into a
      # named volume the runner starts empty every time. That install dominates
      # this wait, hence ten minutes rather than one.
      #
      # The container is not optional. frontend/proxy.conf.json proxies /api to
      # https://nginx -- a compose-network hostname that does not resolve on the
      # host -- and the dev build calls the API on a relative path, so an
      # `ng serve` started on the runner would 404 every request. Playwright
      # reuses this server because playwright.config.ts sets
      # `reuseExistingServer: true` unconditionally. Do NOT change that to the
      # usual `!process.env.CI`: it would make Playwright try to start a second
      # dev server on a port already bound, and this job would never pass.
      - name: Wait for the Angular dev server
        run: |
          set -euo pipefail
          for _ in $(seq 1 120); do
            if curl -fsS -o /dev/null http://localhost:4200; then
              echo "==> Dev server is up."
              exit 0
            fi
            sleep 5
          done
          echo "!!! http://localhost:4200 never answered." >&2
          docker compose logs --no-color frontend >&2
          exit 1
```

- [ ] **Step 2: Commit and push the branch**

A `workflow_dispatch` trigger is only selectable once the file exists on a
pushed branch.

```bash
git add .github/workflows/e2e-rot-check.yml
git commit -m "feat(#96): boot the Docker stack in a weekly e2e workflow"
git push -u origin feature/96-e2e-rot-check
```

- [ ] **Step 3: Run it for real and verify the stack comes up**

CI YAML has no meaningful offline test. Dispatch it against the branch:

```bash
gh workflow run e2e-rot-check.yml --ref feature/96-e2e-rot-check
```

Wait, then read the conclusion **by run id**. `gh run watch --exit-status`
returns 0 even for a failed run, so never trust its exit code:

```bash
gh run list --workflow=e2e-rot-check.yml --branch feature/96-e2e-rot-check --limit 1
```

Then, with the id from that listing:

```bash
gh run view <run-id> --log
```

Expected: the job succeeds, and the log contains both `==> API is up.` and
`==> Dev server is up.`. If a wait step fails, the dumped `docker compose logs`
say why; fix and re-dispatch before starting Task 3.

---

### Task 3: The backend black-box suite

**Files:**
- Modify: `.github/workflows/e2e-rot-check.yml` (append steps to the `e2e` job)

**Interfaces:**
- Consumes: the booted stack from Task 2.
- Produces: a step with `id: backend`, `continue-on-error: true`, whose log
  lands in `${RUNNER_TEMP}/backend-e2e.log`. Task 5 reads
  `steps.backend.outcome`.

**Why `ini-values`:** `backend/bin/e2e.sh` builds PHP's CA bundle as
`ini_get('openssl.cafile') + mkcert root`. When that ini value is empty — the
default for `shivammathur/setup-php` — the concatenation degrades to *only* the
mkcert root, and the suite loses every public CA. `ReaderJourneyE2eTest` fetches
real news homepages over HTTPS and would then skip its way through the run.
Setting the ini value makes the script's existing branch take the correct path,
so the script stays untouched.

**Why `continue-on-error`:** the Playwright suite must still run when the
backend suite fails, and Task 5 needs both verdicts to write one issue.
`catalog-rot-check.yml` uses the same pattern.

- [ ] **Step 1: Append the backend steps**

Append to the `steps:` list in `.github/workflows/e2e-rot-check.yml`:

```yaml
      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: intl, pdo_sqlite, pdo_mysql
          coverage: none
          # backend/bin/e2e.sh concatenates the system CA bundle with the mkcert
          # root and points PHP at the result, keeping full peer verification.
          # It reads the system bundle from this ini value, and setup-php leaves
          # it empty by default -- which would silently reduce the bundle to the
          # mkcert root alone and break every outbound HTTPS fetch in the suite.
          ini-values: openssl.cafile=/etc/ssl/certs/ca-certificates.crt, curl.cainfo=/etc/ssl/certs/ca-certificates.crt

      - name: Install backend dependencies
        working-directory: backend
        run: composer install --prefer-dist --no-progress

      # composer e2e is the documented entry point and does the fixture work
      # itself: purge leftover throwaway accounts, seed the admin and its
      # subscription, clear the rate-limiter and ALTCHA-replay pools, then run
      # the suite against https://localhost:8443 with verification on.
      #
      # No skip guard here, unlike the Playwright leg. ReaderJourneyE2eTest
      # skips when an external news homepage is unreachable, which is a real
      # condition outside this repository's control -- failing on it would make
      # the workflow red for someone else's outage.
      #
      # `shell: bash` is load-bearing, not decoration. The default shell for a
      # `run:` block is `bash -e`, WITHOUT pipefail, so `composer e2e | tee`
      # would report tee's exit status and a failing suite would read as a
      # passing step -- the exact silent-green defect this workflow exists to
      # end. `shell: bash` runs `bash --noprofile --norc -eo pipefail`.
      - name: Backend black-box e2e
        id: backend
        continue-on-error: true
        shell: bash
        working-directory: backend
        run: composer e2e 2>&1 | tee "${RUNNER_TEMP}/backend-e2e.log"
```

- [ ] **Step 2: Commit, push, dispatch**

```bash
git add .github/workflows/e2e-rot-check.yml
git commit -m "feat(#96): run the backend black-box suite in the rot check"
git push
gh workflow run e2e-rot-check.yml --ref feature/96-e2e-rot-check
```

- [ ] **Step 3: Verify the suite really ran**

Read the run log by id, as in Task 2 Step 3. Expected in the backend step's
output: PHPUnit's summary line reporting **9 tests** with no errors or failures.
Skips from `ReaderJourneyE2eTest` are acceptable and expected.

A summary reporting `No tests executed` means the suite did not run at all —
treat that as a failure of this task, not a pass.

---

### Task 4: The Playwright suite and the all-skip guard

**Files:**
- Modify: `.github/workflows/e2e-rot-check.yml` (append steps to the `e2e` job)

**Interfaces:**
- Consumes: the booted stack from Task 2, and
  `scripts/assert-playwright-ran.sh <report>` from Task 1.
- Produces: steps with `id: playwright` and `id: playwright_guard`, both
  `continue-on-error: true`, and a log at `${RUNNER_TEMP}/playwright-e2e.log`.
  Task 5 reads both outcomes.

**Why the guard is a separate step from the run:** a Playwright run that skips
everything exits 0. If the guard shared that step, its failure would be
indistinguishable from a genuine spec failure in the issue body. Separate ids
let Task 5 say which happened.

- [ ] **Step 1: Append the Playwright steps**

Append to the `steps:` list in `.github/workflows/e2e-rot-check.yml`:

```yaml
      - name: Set up Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: frontend/package-lock.json

      # Same reason as ci.yml: node 22 ships npm 10.9.8, but the lockfile was
      # authored by npm 11, and npm 10 mis-resolves chokidar and rejects it.
      - name: Pin npm 11 (matches the lockfile author)
        run: npm i -g npm@11

      - name: Install frontend dependencies
        working-directory: frontend
        run: npm ci

      - name: Install Chromium
        working-directory: frontend
        run: npx playwright install --with-deps chromium

      # The json reporter goes to a file (PLAYWRIGHT_JSON_OUTPUT_NAME), so the
      # list reporter still gives a readable log. The guard below reads the file.
      #
      # This does NOT start a dev server: playwright.config.ts reuses the one
      # the frontend container is already serving on :4200. Its globalSetup
      # re-runs the same fixture commands composer e2e ran -- harmless, and it
      # keeps the two suites on identical fixtures.
      #
      # `shell: bash` for pipefail, same reason as the backend step: the default
      # `bash -e` would return tee's status and hide a failing suite.
      - name: Playwright e2e
        id: playwright
        continue-on-error: true
        shell: bash
        working-directory: frontend
        env:
          PLAYWRIGHT_JSON_OUTPUT_NAME: ${{ runner.temp }}/playwright-report.json
        run: npm run e2e -- --reporter=list,json 2>&1 | tee "${RUNNER_TEMP}/playwright-e2e.log"

      # Playwright exits 0 when every spec skipped, because nothing failed. For
      # an unattended weekly check that is the worst possible outcome, so the
      # verdict is re-decided here. See scripts/assert-playwright-ran.sh.
      - name: Assert the Playwright suite verified something
        id: playwright_guard
        continue-on-error: true
        shell: bash
        run: scripts/assert-playwright-ran.sh "${RUNNER_TEMP}/playwright-report.json" 2>&1 | tee "${RUNNER_TEMP}/playwright-guard.log"

      # The HTML report and the raw logs are what makes a red run actionable a
      # week later. Kept only on failure; a green run has nothing to look at.
      - name: Upload the reports
        if: failure() || steps.playwright.outcome == 'failure' || steps.playwright_guard.outcome == 'failure' || steps.backend.outcome == 'failure'
        uses: actions/upload-artifact@v4
        with:
          name: e2e-reports
          path: |
            ${{ runner.temp }}/*.log
            ${{ runner.temp }}/playwright-report.json
            frontend/playwright-report/
          retention-days: 30
          if-no-files-found: ignore
```

- [ ] **Step 2: Commit, push, dispatch**

```bash
git add .github/workflows/e2e-rot-check.yml
git commit -m "feat(#96): run the Playwright suite and guard against all-skip"
git push
gh workflow run e2e-rot-check.yml --ref feature/96-e2e-rot-check
```

- [ ] **Step 3: Verify the suite really ran**

Read the run log by id. Expected: the guard step prints
`==> Playwright: 36 passed, 0 skipped.` and exits 0.

If it reports skips, do not weaken the guard. Find out why the fixtures did not
take — that is the workflow working correctly on its first real defect.

- [ ] **Step 4: Prove the guard is falsifiable**

A guard that has never fired is a guard nobody has tested. Break the fixtures
deliberately and confirm the job notices.

Temporarily change the Playwright step's `run:` line in the workflow to skip the
seeding, by pointing globalSetup at a non-existent compose project:

```yaml
        run: docker compose down frontend nginx && npm run e2e -- --reporter=list,json 2>&1 | tee "${RUNNER_TEMP}/playwright-e2e.log"
```

Commit on a throwaway commit, push, dispatch, and read the run.

Expected: the `playwright` step reports every spec skipped or failed, and the
`playwright_guard` step goes red naming the skipped specs.

Then **revert the throwaway commit**:

```bash
git revert --no-edit HEAD
git push
```

Confirm the reverted workflow file matches Step 1 exactly before continuing.

---

### Task 5: Open an issue when either suite rots

**Files:**
- Modify: `.github/workflows/e2e-rot-check.yml` (append the final step)

**Interfaces:**
- Consumes: `steps.backend.outcome`, `steps.playwright.outcome`,
  `steps.playwright_guard.outcome` from Tasks 3 and 4, and the log files in
  `${RUNNER_TEMP}`.
- Produces: nothing later tasks consume. This is the last step of the job.

**Why a label rather than a title match:** the workflow must not open a second
issue every week while the first is still open. A dedicated `e2e-rot` label is a
stable handle; issue titles get edited by humans. `gh label create` fails when
the label already exists, so it is guarded — with an `if`, never `||` (SC2015 is
an info-level finding and CI fails on those).

- [ ] **Step 1: Append the reporting step**

Append to the `steps:` list in `.github/workflows/e2e-rot-check.yml`:

```yaml
      # One issue per rot episode, not one per week. A suite that stays broken
      # for a month must produce a single issue with four comments, or the
      # backlog becomes the noise that hides the signal.
      - name: Open or update an issue when a suite rotted
        if: steps.backend.outcome == 'failure' || steps.playwright.outcome == 'failure' || steps.playwright_guard.outcome == 'failure'
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          RUN_URL: ${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}
          BACKEND_OUTCOME: ${{ steps.backend.outcome }}
          PLAYWRIGHT_OUTCOME: ${{ steps.playwright.outcome }}
          GUARD_OUTCOME: ${{ steps.playwright_guard.outcome }}
        run: |
          set -euo pipefail

          body="${RUNNER_TEMP}/body.md"
          {
            echo "The weekly e2e rot check failed. These suites are the only"
            echo "coverage for defects that unit tests structurally cannot see:"
            echo "Jest runs in jsdom, which applies no stylesheets at all."
            echo
            echo "| Suite | Result |"
            echo "| --- | --- |"
            echo "| Backend black-box (\`composer e2e\`) | ${BACKEND_OUTCOME} |"
            echo "| Playwright (\`npm run e2e\`) | ${PLAYWRIGHT_OUTCOME} |"
            echo "| Playwright verified something | ${GUARD_OUTCOME} |"
            echo
            echo "[Full run and reports](${RUN_URL})"
            echo
            if [ "${GUARD_OUTCOME}" = "failure" ]; then
              echo "**The Playwright suite skipped specs.** A skip there means the"
              echo "seeded admin was unavailable, so those assertions never ran."
              echo "Playwright reports that as success; this check does not."
              echo
              echo '```'
              cat "${RUNNER_TEMP}/playwright-guard.log"
              echo '```'
              echo
            fi
            echo "Reproduce locally with the stack up (\`docker compose up -d\`):"
            echo
            echo '```bash'
            echo "cd backend && composer e2e"
            echo "cd frontend && npm run e2e"
            echo '```'
          } > "${body}"

          # The label is the deduplication handle. Creating it fails when it
          # already exists, which is the normal case after the first run.
          #
          # The listing is captured before it is searched. Piping gh straight
          # into `grep -q` lets grep exit early, kill gh with SIGPIPE, and hand
          # pipefail a 141 even when the label WAS found -- which would send
          # this into `gh label create` for a label that already exists, and
          # `set -e` would abort the step.
          labels="$(gh label list --json name --jq '.[].name')"
          if ! printf '%s\n' "${labels}" | grep -qx 'e2e-rot'; then
            gh label create e2e-rot \
              --description 'The weekly e2e rot check is failing' \
              --color 'B60205'
          fi

          existing="$(gh issue list --state open --label e2e-rot \
            --limit 1 --json number --jq '.[0].number // empty')"

          if [ -n "${existing}" ]; then
            echo "==> Commenting on the open rot issue #${existing}."
            gh issue comment "${existing}" --body-file "${body}"
            exit 0
          fi

          echo "==> Opening a new rot issue."
          gh issue create \
            --title "E2E rot check: a suite is failing" \
            --body-file "${body}" \
            --label e2e-rot

      # continue-on-error above kept the job green so the reporting step could
      # run. The job's own verdict is restored here, so the Actions list and the
      # failure email agree with the issue.
      - name: Fail the job when a suite rotted
        if: steps.backend.outcome == 'failure' || steps.playwright.outcome == 'failure' || steps.playwright_guard.outcome == 'failure'
        run: |
          echo "!!! An e2e suite failed. See the issue labelled e2e-rot." >&2
          exit 1
```

- [ ] **Step 2: Commit, push, dispatch, and confirm the green path is quiet**

```bash
git add .github/workflows/e2e-rot-check.yml
git commit -m "feat(#96): open one issue per e2e rot episode"
git push
gh workflow run e2e-rot-check.yml --ref feature/96-e2e-rot-check
```

Read the run by id. Expected: the job succeeds, both reporting steps are
skipped, and no issue was created:

```bash
gh issue list --state open --label e2e-rot
```

Expected: empty.

- [ ] **Step 3: Prove the failure path opens exactly one issue**

Make a throwaway commit that forces a failure — change the Playwright step's
`run:` to `exit 1` — then push and dispatch it **twice**.

Expected after the first run: the job is red, and one open issue carries the
`e2e-rot` label.

Expected after the second run: **still one** issue, now with a second comment.
Verify:

```bash
gh issue list --state open --label e2e-rot --json number,title,comments
```

Then revert the throwaway commit and close the test issue:

```bash
git revert --no-edit HEAD
git push
gh issue close <issue-number> --comment "Verification of the #96 reporting path."
```

Confirm the reverted workflow file matches Step 1 exactly.

---

### Task 6: Document it and open the PR

**Files:**
- Modify: `docs/local-docker.md` (a short section on the scheduled check)
- Modify: `CLAUDE.md` (one line in Testing)

**Interfaces:**
- Consumes: the finished workflow.
- Produces: nothing.

- [ ] **Step 1: Add a section to docs/local-docker.md**

Append at the end of the document:

```markdown
## The weekly rot check

Both e2e suites also run in GitHub Actions every Wednesday
(`.github/workflows/e2e-rot-check.yml`), against a stack the runner boots the
same way this page describes — including its own mkcert certificate. It is
deliberately **not** a pull-request check: booting the stack costs minutes, and
issue #96 chose weekly reporting over per-PR gating.

When a suite rots, the run opens a single issue labelled `e2e-rot` and comments
on that issue on later failures rather than opening more. A red run does not
block a deploy; the deploy guard still only reads `ci.yml`.

Run it early with:

    gh workflow run e2e-rot-check.yml
```

- [ ] **Step 2: Add the line to CLAUDE.md**

In the `## Testing` section, after the Playwright bullet, add:

```markdown
- Both e2e suites run weekly in CI (`.github/workflows/e2e-rot-check.yml`) and
  open an `e2e-rot` issue when they break. Never give that workflow a
  `pull_request` trigger — the cost was weighed in #96.
```

- [ ] **Step 3: Verify the frontend gate still passes**

Nothing here touches frontend source, but the gate is cheap and the branch must
be green:

```bash
cd frontend && npm run check
```

Expected: PASS.

- [ ] **Step 4: Commit and open the PR**

```bash
git add docs/local-docker.md CLAUDE.md
git commit -m "docs(#96): record the weekly e2e rot check"
git push
```

Then open the PR into `develop`. The body must contain `Closes #96`, which
auto-closes the issue on merge because `develop` is the default branch:

```bash
gh pr create --base develop --title "Run both e2e suites weekly in CI" --body "Closes #96"
```

- [ ] **Step 5: Confirm CI is green on the PR**

```bash
gh pr checks
```

`gh pr checks` exits 8 while checks are merely pending, so re-read until every
check reports a conclusion. Do not treat a non-zero exit as failure on its own.

---

## Self-review

**Task 1 is pre-verified.** The guard, its test, and their shellcheck run were
executed while writing this plan: the test prints
`PASS: assert-playwright-ran.sh` and shellcheck reports nothing at any severity.
Two bugs were found and fixed there rather than left for the implementer — the
`pipefail` inversion in the test's grep assertion, and the missing `shell: bash`
on the piped workflow steps. Copy the code as written.

**Spec coverage.** Every section of the spec maps to a task: report-weekly-not-gate → Task 2 triggers; one workflow one job both suites → Tasks 2–4; stack boot with mkcert → Task 2; the two suites → Tasks 3 and 4; the all-skip trap → Tasks 1 and 4; issue with duplicate suppression → Task 5; out-of-scope items are respected, with the single ci.yml line justified in Task 1.

**Deviations from the spec, both deliberate and flagged:** the spec's "no change to `ci.yml`" gains one step that runs the new script test (Task 1), and the spec's silence on `openssl.cafile` is corrected by the `ini-values` finding (Task 3).
