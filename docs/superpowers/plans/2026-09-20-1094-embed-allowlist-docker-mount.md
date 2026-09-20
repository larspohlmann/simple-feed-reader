# EmbedFrameAllowlistTest Docker Mount (#1094) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `docker compose exec php composer test` green on a clean `develop` by giving the `php` container read access to the one frontend directory `EmbedFrameAllowlistTest` reads.

**Architecture:** `DumpEmbedFrameAllowlistCommand::allowlistPath()` resolves `dirname(project_dir) . '/frontend/src/app/reader/…'`, which is `/frontend/src/app/reader/…` in the container (`project_dir` = `/app`). The `php` service mounts only `./backend` and `./docs`, so the file is missing. The fix is one more read-only bind mount, the same pattern #593 used for `./docs:/docs:ro`. No PHP changes.

**Tech Stack:** Docker Compose (`docker-compose.yml`, dev stack only), PHPUnit 12.

**Spec:** GitHub issue [#1094](https://github.com/larspohlmann/simple-feed-reader/issues/1094). Side-effect analysis is in the session that opened it; its conclusions are the Global Constraints below.

## Global Constraints

- Mount **read-only** (`:ro`). The `php` container runs as `root` and the dev app processes untrusted feed HTML; it must not be able to write into the SPA source tree.
- Mount the **directory** `./frontend/src/app/reader`, never the single JSON file: a single-file bind mount goes stale when git replaces the file's inode.
- Mount **only that directory**, not all of `./frontend`: the host `node_modules` (476 MB, macOS binaries) has no business in the Linux container.
- `php` service only. The `worker` runs no tests; `nginx` and `frontend` are untouched.
- `docker-compose.yml` only. `docker-compose.prod.yml` is a standalone file, not an overlay, and has no bind mounts — do not touch it.
- Comments in the tree are **three lines at most** (CLAUDE.md). The existing #593 comment is four lines; the new mount shares one three-line comment with it rather than adding a second block.
- No change to `DumpEmbedFrameAllowlistCommand`, `EmbedFrameAllowlistTest`, or any PHP. `app:embed:dump-frame-allowlist` keeps running natively; in the container it fails today (directory missing) and will keep failing (read-only target) — not a regression.
- Commit format `type(#NN): lower-case summary`. No attribution lines. PR into `develop` with `Closes #1094`. Do **not** use `gh pr merge --auto` (`develop` has no required checks, it merges before CI).
- Recreating `php` gives it a new IP; nginx keeps the old upstream and every `/api/*` returns 502 until nginx is restarted. Recreate, then `docker compose restart nginx`.
- Run e2e/tests only from this checkout — it owns the running stack.

---

### Task 1: Read-only mount of the reader directory on the `php` service

**Files:**
- Modify: `docker-compose.yml:36-43` (the `php` service `volumes:` block)
- Test (unchanged, used as the proof): `backend/tests/Service/Reader/Media/EmbedFrameAllowlistTest.php`
- Temporarily edited and restored in Step 6: `frontend/src/app/reader/embed-frame-allowlist.generated.json`

**Interfaces:**
- Consumes: `DumpEmbedFrameAllowlistCommand::allowlistPath(string $projectDir): string` → `dirname($projectDir) . '/frontend/src/app/reader/embed-frame-allowlist.generated.json'`.
- Produces: container path `/frontend/src/app/reader/` (read-only), so the path above resolves.

- [ ] **Step 1: Reproduce the failure (RED)**

Run from the repo root:

```bash
docker compose exec -T php composer test -- --filter=EmbedFrameAllowlistTest
```

Expected: `Failures: 1` with
`Failed asserting that file "//frontend/src/app/reader/embed-frame-allowlist.generated.json" exists.`

If it passes, stop: something already provides `/frontend` and the plan's premise is wrong.

- [ ] **Step 2: Add the mount**

In `docker-compose.yml`, replace this block of the `php` service:

```yaml
    volumes:
      - ./backend:/app
      # BackupSchemaCoverageTest reads docs/backup.md through a path that escapes
      # the backend mount and resolves to /docs (see #593). Without this the
      # container run reports a spurious red and aborts every in-container
      # Infection run. Read-only: the container never writes the doc.
      - ./docs:/docs:ro
```

with:

```yaml
    volumes:
      - ./backend:/app
      # Two tests read through paths that escape the backend mount (#593, #1094); without these the
      # container run is spuriously red and aborts in-container Infection. Read-only, and a directory,
      # not the one file: a single-file bind mount goes stale when git replaces the file.
      - ./docs:/docs:ro
      - ./frontend/src/app/reader:/frontend/src/app/reader:ro
```

- [ ] **Step 3: Validate the file and recreate the container**

```bash
docker compose config -q && echo "compose config OK"
docker compose up -d php
docker compose restart nginx
```

Expected: `compose config OK`; `php` is recreated (not just "Running"); nginx restarts.

Then prove nginx reaches the new `php` container (a 502 here means the restart was skipped):

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://localhost:8443/api/health
```

Expected: not `502`. (If `/api/health` does not exist the code is `404`/`401`, which still proves php answered; only `502` is a failure.)

- [ ] **Step 4: Prove the mount is present and read-only**

```bash
docker compose exec -T php ls /frontend/src/app/reader/embed-frame-allowlist.generated.json
docker compose exec -T php sh -c 'touch /frontend/src/app/reader/.write-probe; echo "exit=$?"'
docker compose exec -T php sh -c 'ls /frontend; ls /frontend/src/app'
```

Expected: the file is listed; the `touch` prints `Read-only file system` and a non-zero `exit=`; `/frontend` holds only `src`, and `/frontend/src/app` only `reader` (no `node_modules`, nothing else leaked).

- [ ] **Step 5: Run the test (GREEN)**

```bash
docker compose exec -T php composer test -- --filter=EmbedFrameAllowlistTest
```

Expected: `OK (9 tests, …)`.

- [ ] **Step 6: Break-test the guard on both legs, then restore**

The issue's acceptance demands the drift guard still fails when the committed JSON and the providers disagree. Break the JSON on the host (the container sees it through the mount), without `git checkout`:

```bash
python3 - <<'EOF'
import pathlib
p = pathlib.Path('frontend/src/app/reader/embed-frame-allowlist.generated.json')
s = p.read_text()
assert s.startswith('[\n')
p.write_text(s.replace('[\n', '[\n    "^https://break-test\\\\.invalid/$",\n', 1))
EOF
docker compose exec -T php composer test -- --filter=EmbedFrameAllowlistTest
(cd backend && php bin/phpunit tests/Service/Reader/Media/EmbedFrameAllowlistTest.php)
```

Expected: **both** runs fail in `testTheCommittedClientAllowlistMatchesTheProviders` on the `assertSame` (a content diff), **not** on `assertFileExists`. If the container run still passes, the mount is stale or wrong — stop and investigate.

Restore by exact string removal and verify the tree is clean:

```bash
python3 - <<'EOF'
import pathlib
p = pathlib.Path('frontend/src/app/reader/embed-frame-allowlist.generated.json')
s = p.read_text()
line = '    "^https://break-test\\\\.invalid/$",\n'
assert s.count(line) == 1
p.write_text(s.replace(line, ''))
EOF
git diff --quiet -- frontend/ && echo "frontend tree clean"
```

Expected: `frontend tree clean`.

- [ ] **Step 7: Full MySQL leg**

```bash
docker compose exec -T php composer test
```

Expected: `OK` — zero failures (it was `Failures: 1` before). Skips are fine. Any failure here is real and must be reported, not waved through.

- [ ] **Step 8: Shell/YAML gates and the dev log**

```bash
git diff --stat
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq -r 'select(.level_name=="ERROR" or .level_name=="CRITICAL") | .message' | tail -5
```

Expected: `git diff --stat` shows `docker-compose.yml` only (plus this plan). No new ERROR/CRITICAL lines caused by the recreate.

- [ ] **Step 9: Commit**

```bash
git add docker-compose.yml docs/superpowers/plans/2026-09-20-1094-embed-allowlist-docker-mount.md
git commit -m "fix(#1094): mount the reader directory read-only into the php container"
```

Commit body (design rationale belongs in the commit): why read-only, why the directory and not the file, why not all of `./frontend`, and that prod is unaffected because `docker-compose.prod.yml` is standalone.

- [ ] **Step 10: PR**

```bash
git push -u origin fix/1094-embed-allowlist-docker-mount
gh pr create --base develop --title "fix(#1094): mount the reader directory read-only into the php container" --body "Closes #1094 …"
```

Wait for all CI checks to pass (`gh pr checks <n> --watch --fail-fast`), **then** merge with `gh pr merge <n> --merge` only if the user asked for the merge. After a merge, verify #1094 closed.

---

## Self-Review

- **Spec coverage:** Acceptance 1 (Docker leg green on clean `develop`) → Steps 5 and 7. Acceptance 2 (guard still fails on both legs when JSON and providers disagree) → Step 6. Recommended fix option 1, directory-not-file → Step 2 and Global Constraints. Side effects (ro, scope, prod, nginx 502) → Global Constraints, Steps 3–4.
- **Placeholders:** none; every command and the full YAML are given. The PR body is abbreviated in Step 10 on purpose — it restates the commit body.
- **Consistency:** the container path `/frontend/src/app/reader` is identical in Steps 2, 4 and the Interfaces block; the branch name matches the one already created.
