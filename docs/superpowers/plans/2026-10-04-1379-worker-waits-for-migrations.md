# The worker waits for the migrations — Implementation Plan

**Goal:** No update or install runs the worker between the new code going live and the schema being migrated.

**Spec:** GitHub issue #1379. On ganesh (SQLite) the worker, recreated on the new code by `up -d --build`,
wrote within a second of starting and the migration failed with `database is locked`; on any database the
worker meanwhile queries columns the old schema lacks.

**Architecture:** Every bring-up (`prod-start.sh`, the dev branch of `update.sh`, `bring_up_stack` in
`lib.sh`) runs `up` with `--scale worker=0`, which removes the old worker and starts no new one, migrates,
and only then starts the worker with `up -d --build worker` (the build is a cache hit: same Dockerfile,
target and args as php). Two `lib.sh` helpers carry this, taking the compose function (`compose` or
`prod_compose`) as their first argument, and replace the `restart worker` that followed each migration.

Verified mechanism on a throwaway compose project: `up -d --build --scale worker=0` recreates php and
leaves no worker container; `up -d --build worker` then starts it on the new image.

**Constraints:** bash 3.2, shellcheck-clean, test wired into CI's "Shell scripts" job, commit `fix(#1379): …`.

### Task 1 — helpers + test

- `lib.sh`: `up_holding_worker <compose-fn> [up args…]` and `start_worker <compose-fn> [up args…]`.
- `scripts/test/worker-waits-for-migrations.test.sh`: stub `compose`, run `bring_up_stack`, assert the
  `up` carries `--scale worker=0`, no worker start precedes the migration, and the worker starts after it.
- CI step after `wait-for-php-ready`.

### Task 2 — callers

- `bring_up_stack`, `update.sh` dev branch, `prod-start.sh`: `up` through `up_holding_worker`, the trailing
  `restart worker` becomes `start_worker` (prod and update with `--build`).
- `prod-start.sh`: the worker also stays down when the schema check dies, which is the point.

### Verification

- The test fails against the old `bring_up_stack`, passes after.
- `shellcheck scripts/*.sh scripts/test/*.sh`; every `scripts/test/*.test.sh`.
