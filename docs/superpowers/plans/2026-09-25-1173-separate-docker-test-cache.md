# Separate Docker test cache — Implementation Plan

**Goal:** `php bin/phpunit` (native, SQLite) and `docker compose exec php composer test` (MySQL) can run at the same time without failing each other's tests (#1173).

**Root cause:** the `php` and `worker` containers bind-mount `backend/` at `/app`, so the container kernel and a native kernel resolve the same `var/cache/<env>` directory. The compiled container and the filesystem cache pools both live there, including the rate limiter's sliding windows (`CACHE_DIRECTORY=%kernel.share_dir%/pools/app`, and the share dir defaults to the cache dir). Two concurrent suites spend each other's limiter budget. Evidence from #1154: run concurrently, SQLite had 4 failures and MySQL 7, all rate-limit or cross-process tests; run one after the other, both passed.

**Fix:** give the Docker services their own kernel cache directory. Symfony's `MicroKernelTrait::getCacheDir()` honours `APP_CACHE_DIR` (it appends `/<env>`), and the share and build dirs follow the cache dir. So setting `APP_CACHE_DIR: /app/var/cache-docker` on the `php` and `worker` services moves the compiled container and every pool for the container side, with no code change. Both services get the same value, so php-fpm and the worker keep sharing their pools with each other, as they do today.

## Tasks

- [ ] **1. Compose.** Add `APP_CACHE_DIR: /app/var/cache-docker` to the `environment` of `php` and `worker` in `docker-compose.yml`, with a ≤3-line why-comment on the first. Recreate both containers, then restart nginx (a recreated php strands nginx on the old IP). Warm the dev cache in the container.
- [ ] **2. Docs.** One sentence in `docs/local-docker.md` saying the stack keeps its cache in `backend/var/cache-docker`, so `cache:clear` has to run inside the container to reach it.
- [ ] **3. Prove it.**
  - Run both suites concurrently and expect both green.
  - Break-test: remove the variable, recreate the containers, run concurrently again, and expect the limiter failures to come back.
  - Restore the variable and recreate again.
  - Then check that the app still serves: `/api/health` answers and the reader loads.
- [ ] **4. Gates.** `composer test` in the container and `php bin/phpunit` natively (these can now run together). No PHP change, so no Infection diff.
