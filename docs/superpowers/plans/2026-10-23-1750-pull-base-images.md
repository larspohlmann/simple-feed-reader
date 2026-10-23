# #1750 Updates pull patched base images — Implementation Plan

**Goal:** `scripts/update.sh` (dev stack) and `scripts/prod-start.sh` (prod stack,
also run by `update.sh` and the installer) bring patched base images into an
installed stack, so a PHP security release reaches it on the next update.

**Cause:** both bring a stack up with `docker compose up -d --build`. Without
`--pull` the build reuses the cached `php:8.4-fpm-alpine` / `node:22-slim` /
`nginx:1.27-alpine` and its layer cache, and `image:` services are never
re-pulled.

**Design:** one helper in `scripts/lib.sh`, `refresh_images <compose function>`,
runs `pull --ignore-buildable --quiet` (the `image:` services of the active
profiles) and then `build --pull` (the buildable ones). Both run even if the
first fails; the helper fails if either did. Callers wrap it in `run_step … ||
warn …`: offline or a registry outage keeps the cached images instead of
aborting the update. The following `up -d --build` then hits the fresh build
cache and only recreates what changed.

Out of scope: Strato (host PHP under `/opt/RZphp84`, patched by Strato).

## Tasks

1. **Test first** — `scripts/test/update-pulls-base-images.test.sh`: stub
   `compose`, assert `refresh_images compose` issues `pull --ignore-buildable`
   and `build --pull`; with a failing pull, assert the build still runs and the
   helper returns non-zero. Run it, see it fail (helper missing).
2. **Helper** in `scripts/lib.sh` beside `up_holding_worker`. Test passes.
3. **Callers** — `prod-start.sh` after `export_build_version_args` (the build
   needs the version args), `update.sh` before the dev `up_holding_worker`.
4. **Docs** — `docs/docker-production.md` §6 names the pull.
5. **CI** — add the test to the shell-scripts job in `.github/workflows/ci.yml`.
6. **Verify** — `shellcheck scripts/*.sh scripts/test/*.sh`, all
   `scripts/test/*.test.sh`, and a `docker compose --dry-run` of both commands
   against the dev and prod compose files.
