# Settings split + self-update from admin About (#1947, #1948) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split Settings → About into a user **Statistics** section and an admin-only **About** section with an update pill (#1947), then let an admin run the update from About through an opt-in `updater` container (#1948).

**Architecture:** Tasks 1–4 are frontend only: `settings/about/` becomes `settings/statistics/`, the identity/version half moves to `settings/admin/about/`, and the nav and sidebar read the existing `VersionService` signals. Tasks 5–10 add the self-update: a small `updater` container (Docker socket + the checkout, nothing published) watches a shared `.update-state/` directory; the backend writes a request file there and reads the run's status back; the run itself is a one-off `docker run` outside the compose project, so the stack rebuild it triggers cannot kill it. The app never passes arguments to the script: a request always means "update to the latest release".

**Tech Stack:** Angular 20 standalone components and signals, Transloco, Jest (jsdom), SCSS with Stylelint; Symfony 7.4 / PHP 8.4, PHPUnit; bash + Docker Compose; GitHub Actions.

**Spec:** GitHub issues #1947 and #1948 (`gh issue view 1947`, `gh issue view 1948`).

## Global Constraints

- About is **admins only**: its section is in the `admin` group and its route has `adminGuard`.
- `/settings/about` redirects to `/settings/statistics` (old links, non-admins).
- The pill reads `VersionService.updateAvailable()` / `latest()` — no new polling.
- Sidebar notice: admin → `routerLink="/settings/admin/about"`; everyone else → the release notes, unchanged.
- Component styles in sibling `.scss`; no hex colours, no ad-hoc `px` (Stylelint).
- Prettier 100 columns. Comments only where a reader would otherwise get it wrong.
- Every new translation key goes into both `frontend/public/i18n/en.json` and `de.json`.
- Run Jest inside the container: `docker compose exec -T frontend npm test -- <path>` from the repo root. Check the container serves this checkout first (memory: verify-containers-are-current).
- Commits: `type(#1947): …` for Tasks 1–4, `type(#1948): …` for Tasks 5–10, no attribution lines. The PR closes both issues.
- Backend: CLAUDE.md's PHP rules apply in full — `final readonly`, Model/Exception/interface folders, no HTTP in `Service`, `Response::HTTP_*` constants, `requireId()`, PHPMD-clean files. Gates: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`.
- Shell: `set -euo pipefail`, shellcheck-clean (CI fails on any finding); every new `scripts/test/*.test.sh` is added to the CI list in `.github/workflows/ci.yml` next to its siblings.
- The `updater` service publishes no port and holds the only Docker socket mount in the stack. Nothing the app writes is ever passed to `update.sh` as an argument.
- Strato stays out: there is no `.update-state/` there, so the backend reports the updater absent and the About page shows the manual hint.

## File Structure

| File | Responsibility |
|---|---|
| `frontend/src/app/settings/statistics/statistics-section.component.{ts,html,scss,spec.ts}` | "Your reading" (moved from `about/about-section.*`, About half removed) |
| `frontend/src/app/settings/statistics/day-chart*` | moved unchanged from `settings/about/` |
| `frontend/src/app/settings/admin/about/about-section.component.{ts,html,scss,spec.ts}` | identity, links, versions, stale notice (About half only) |
| `frontend/src/app/settings/settings-sections.ts`, `settings.routes.ts` | new `statistics` + `admin/about` entries and routes, `about` redirect |
| `frontend/src/app/settings/settings-nav.component.{ts,html,scss,spec.ts}` | update pill on `admin/about` |
| `frontend/src/app/reader/shell/sidebar/sidebar-foot.component.{ts,html,spec.ts}` | admin routing of the update notice |
| `frontend/src/app/theme/glass-rim-coverage.spec.ts` | path of `day-chart.component.scss` |
| `frontend/public/i18n/{en,de}.json` | new keys |

---

### Task 1: Statistics section

**Files:**
- Move: `frontend/src/app/settings/about/` → `frontend/src/app/settings/statistics/` (`git mv`), then rename `about-section.component.*` → `statistics-section.component.*` (`git mv`)
- Modify: the four `statistics-section.component.*` files, `settings-sections.ts`, `settings.routes.ts`, `theme/glass-rim-coverage.spec.ts`, `public/i18n/{en,de}.json`

**Interfaces:**
- Produces: `StatisticsSectionComponent` (selector `app-statistics-section`), section path `'statistics'`, key `settings.statistics.title`.

- [ ] **Step 1: Move the folder and files**

```bash
cd frontend/src/app/settings
git mv about statistics
for ext in ts html scss spec.ts; do git mv statistics/about-section.component.$ext statistics/statistics-section.component.$ext; done
```

- [ ] **Step 2: Trim the template.** In `statistics-section.component.html` delete the second `<app-settings-group icon="info" …>` block (identity, author/license/source/issues/changelog rows, version rows, stale notice, copyright). Keep the `<app-settings-stack>` wrapper, the "Your reading" group and the `#bars` template.

- [ ] **Step 3: Trim the class.** In `statistics-section.component.ts`:
  - rename the class to `StatisticsSectionComponent`, selector `app-statistics-section`, `templateUrl`/`styleUrl` to `./statistics-section.component.*`;
  - delete `VersionRow`, `REPOSITORY_URL`, `author`, `copyrightYear`, `links`, `unavailable`, `loading`, `rows`, `staleBundle`, `buildDate`, `this.versions.load()`, the `versions` injection, and the now-unused imports (`ReleaseVersion`, `VersionService`, `formatLongDate`, `DEVELOPMENT_VERSION`, `buildVersion`, `SettingsRowComponent`, `IconComponent` if no longer used by the template — check with the template).

- [ ] **Step 4: Trim the styles.** In `statistics-section.component.scss` delete the rules for `.identity`, `.identity-name`, `.identity-tagline`, `.copyright`, `.loading`, `.value`, `.link`, `.pending`, `.stale` and their section banner comments.

- [ ] **Step 5: Trim the spec.** In `statistics-section.component.spec.ts` import/describe `StatisticsSectionComponent`; delete the `VersionService` provider, `load` mock, `buildVersion`/`bakedIn` handling and these tests: "asks the API which build…" (keep its `loadIfStale` assertion as a new test "loads the subscription counts"), "shows the version and commit of both halves", "shows no build date…", "still shows the app version…", "reports a stale bundle…", "says nothing when the two releases match", "does not cry stale…", "links the source code…". `mount` keeps its `api` parameter only if still needed — drop it and its `unavailable` option.

```ts
it('loads the subscription counts', () => {
  mount();
  expect(loadIfStale).toHaveBeenCalled();
});
```

- [ ] **Step 6: Section, route, redirect, keys.**

`settings-sections.ts` — replace the general `about` entry (same position, last of the general group):

```ts
  { path: 'statistics', icon: 'insights', labelKey: 'settings.statistics.title', group: 'general' },
```

`settings.routes.ts` — replace the `about` route:

```ts
      {
        path: 'statistics',
        title: sectionLabelKey('statistics'),
        loadComponent: () =>
          import('./statistics/statistics-section.component').then(
            (module) => module.StatisticsSectionComponent,
          ),
      },
      // #1947: About became admin-only; the old path forwards to what every user had there.
      { path: 'about', redirectTo: 'statistics', pathMatch: 'full' },
```

`glass-rim-coverage.spec.ts`: `'app/settings/about/day-chart.component.scss'` → `'app/settings/statistics/day-chart.component.scss'`.

i18n: `settings.statistics.title` — en `"Statistics"`, de `"Statistik"`.

- [ ] **Step 7: Route test.** In `settings.routes.spec.ts`, next to the retired Tags/Profile tests and in their style, add "forwards the retired About path to Statistics, so an old link still lands". `grep -n "about" frontend/src/app/settings/*.spec.ts` must come back clean of stale references.

- [ ] **Step 8: Run** `docker compose exec -T frontend npm test -- src/app/settings src/app/theme` — PASS.

- [ ] **Step 9: Commit** `refactor(#1947): move the reading statistics into their own Statistics section`

---

### Task 2: Admin About section

**Files:**
- Create: `frontend/src/app/settings/admin/about/about-section.component.{ts,html,scss,spec.ts}`
- Modify: `settings-sections.ts`, `settings.routes.ts`

**Interfaces:**
- Produces: `AboutSectionComponent` (selector `app-about-section`), section path `'admin/about'` (label `settings.about.title`, icon `info`).

- [ ] **Step 1: Start from the pre-split files**

```bash
cd frontend/src/app/settings
mkdir -p admin/about
for ext in ts html scss spec.ts; do git show origin/develop:frontend/src/app/settings/about/about-section.component.$ext > admin/about/about-section.component.$ext; done
```

- [ ] **Step 2: Trim to the About half** — the mirror image of Task 1:
  - html: keep only `<app-settings-stack>` with the `icon="info"` group; delete the "Your reading" group and the `#bars` template.
  - ts: delete `StatTile`, `RankedFeed`, `FeedBar`, `TOP_FEEDS`, `subscriptions`, `api`, `destroyRef`, `activity`, `readingPending`, `plainFeeds`, `tiles`, `arrivedDays`, `readDays`, `hasArrivals`, `hasReads`, `arrivedAverage`, `topUnreadFeeds`, `topReadFeeds`, `topArrivalFeeds`, `feedLinkParams`, `chartDays`, `toBars`, `oneDecimal`, `count`, and their imports (`takeUntilDestroyed`, `DestroyRef`, `NgTemplateOutlet`, `RouterLink`, `signal`, `SettingsApi`, `SubscriptionsStore`, `isSocialFeed`, `ReadingActivity`, `ReadingDay`, `selectionQueryParams`, `ChartDay`, `hasActivity`, `DayChartComponent`). `ngOnInit` keeps only `this.versions.load()`.
  - Fix relative import depths (one level deeper: `../../../core/…`, `../../../shared/…`, `../../../../environments/version`).
  - scss: keep `.identity` … `.stale` and whatever `%inset`/`@use` they use; delete the tile/block/average/bar rules. Fix `@use` paths one level deeper.
  - spec: keep the version/stale/source tests and "renders the section as a settings group"; drop the reading tests, the `SubscriptionsStore`/`SettingsApi` providers and the store/activity helpers. Fix import depths.

- [ ] **Step 3: Section and route.** `settings-sections.ts`, last entry of the admin group (after `admin/queues`):

```ts
  { path: 'admin/about', icon: 'info', labelKey: 'settings.about.title', group: 'admin' },
```

`settings.routes.ts`, after the `admin/mail` route:

```ts
      {
        path: 'admin/about',
        title: sectionLabelKey('admin/about'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/about/about-section.component').then(
            (module) => module.AboutSectionComponent,
          ),
      },
```

- [ ] **Step 4: Route test.** `settings.routes.spec.ts` asserts no guards for any admin route; its "titles sections by a key the dictionary holds" test already covers the new entry. Add one guard test:

```ts
  it('keeps About to admins', () => {
    const about = SETTINGS_ROUTES[0].children?.find((route) => route.path === 'admin/about');
    expect(about?.canActivate).toEqual([adminGuard]);
  });
```

- [ ] **Step 5: Run** `docker compose exec -T frontend npm test -- src/app/settings` — PASS.

- [ ] **Step 6: Commit** `feat(#1947): move About into the admin settings`

---

### Task 3: Update pill on the About nav entry

**Files:**
- Modify: `settings/settings-nav.component.{ts,html,scss,spec.ts}`, `public/i18n/{en,de}.json`

**Interfaces:**
- Consumes: `VersionService.updateAvailable: Signal<boolean>`, `VersionService.latest: Signal<LatestRelease | null>`, `VersionService.load()`.

- [ ] **Step 1: Failing tests** in `settings-nav.component.spec.ts`. Add a `VersionService` stub to `mount` (options `updateAvailable?: boolean`):

```ts
        {
          provide: VersionService,
          useValue: {
            load: versionLoad,
            updateAvailable: signal(options.updateAvailable ?? false),
            latest: signal(
              options.updateAvailable
                ? { version: 'v9.9.9', notesUrl: 'https://github.test/releases/tag/v9.9.9' }
                : null,
            ),
          },
        },
```

with `const versionLoad = jest.fn();` reset in a `beforeEach`. Tests:

```ts
  it('pills the About entry while an update is available', () => {
    const fixture = mount(['ROLE_USER', 'ROLE_ADMIN'], { updateAvailable: true });
    const about = (fixture.nativeElement as HTMLElement).querySelector(
      'a[href="/settings/admin/about"]',
    );
    const pill = about?.querySelector('.badge.update');
    expect(pill?.textContent?.trim()).toBe('Update');
    expect(pill?.getAttribute('aria-label')).toBe('Update available: version v9.9.9');
  });

  it('shows no pill when the running build is current', () => {
    const fixture = mount(['ROLE_USER', 'ROLE_ADMIN']);
    expect((fixture.nativeElement as HTMLElement).querySelector('.badge.update')).toBeNull();
  });

  it('asks for the version only for an admin, who alone sees the pill', () => {
    mount(['ROLE_USER']);
    expect(versionLoad).not.toHaveBeenCalled();
    TestBed.resetTestingModule();
    mount(['ROLE_USER', 'ROLE_ADMIN']);
    expect(versionLoad).toHaveBeenCalled();
  });
```

- [ ] **Step 2: Run** `docker compose exec -T frontend npm test -- src/app/settings/settings-nav` — FAIL.

- [ ] **Step 3: Implement.** `settings-nav.component.ts`:

```ts
  private readonly versions = inject(VersionService);
  readonly updateAvailable = this.versions.updateAvailable;
  readonly latestVersion = computed(() => this.versions.latest()?.version ?? '');
```

and in the constructor (Settings is outside the reader shell, which otherwise triggers the check):

```ts
    if (this.auth.isAdmin()) {
      this.mailHealth.refresh();
      this.versions.load();
    }
```

`settings-nav.component.html`, after the `admin/mail` badge:

```html
            @if (s.path === 'admin/about' && updateAvailable()) {
              <span
                class="badge update"
                [attr.aria-label]="
                  'settings.about.updatePillLabel' | transloco: { version: latestVersion() }
                "
                >{{ 'settings.about.updatePill' | transloco }}</span
              >
            }
```

`settings-nav.component.scss`, after `.badge` — an update is news, not a warning, so it takes the sidebar notice's accent colours:

```scss
.badge.update {
  background: var(--accent-soft);
  color: var(--accent);
  font-weight: 600;
}
```

i18n: `settings.about.updatePill` — en `"Update"`, de `"Update"`; `settings.about.updatePillLabel` — en `"Update available: version {{version}}"`, de `"Update verfügbar: Version {{version}}"`.

- [ ] **Step 4: Run** the nav spec — PASS.

- [ ] **Step 5: Commit** `feat(#1947): pill the admin About entry while an update is available`

---

### Task 4: Sidebar notice routes admins to About

**Files:**
- Modify: `reader/shell/sidebar/sidebar-foot.component.{ts,html,spec.ts}`, `public/i18n/{en,de}.json`

**Interfaces:**
- Consumes: `AuthService.isAdmin(): boolean`.

- [ ] **Step 1: Failing tests.** In `sidebar-foot.component.spec.ts` the `AuthService` stub gains `isAdmin`; `mount` takes `admin?: boolean`:

```ts
      {
        provide: AuthService,
        useValue: {
          user: signal(over.user ?? account(null)),
          isAdmin: () => over.admin ?? false,
        },
      },
```

New test (the existing release-notes test stays as the non-admin case):

```ts
  it('sends an admin to the About page instead of the release notes', () => {
    const fixture = mount({ admin: true });
    const versions = TestBed.inject(VersionService);
    versions.latest.set({ version: 'v9.9.9', notesUrl: 'https://github.test/releases/tag/v9.9.9' });
    versions.updateAvailable.set(true);
    fixture.detectChanges();

    const badge = (fixture.nativeElement as HTMLElement).querySelector('.update-badge');
    expect(badge?.getAttribute('href')).toBe('/settings/admin/about');
    expect(badge?.getAttribute('target')).toBeNull();
    expect(badge?.getAttribute('aria-label')).toBe('Update available: version v9.9.9 — open About');
  });
```

- [ ] **Step 2: Run** `docker compose exec -T frontend npm test -- src/app/reader/shell/sidebar/sidebar-foot` — FAIL.

- [ ] **Step 3: Implement.** `sidebar-foot.component.ts`: `readonly isAdmin = computed(() => this.auth.isAdmin());`. `sidebar-foot.component.html` — the update badge becomes:

```html
  @if (availableUpdate(); as update) {
    @if (isAdmin()) {
      <a
        class="update-badge"
        routerLink="/settings/admin/about"
        [attr.aria-label]="'reader.updateAvailableAdminLabel' | transloco: { version: update.version }"
        >{{ 'reader.updateAvailable' | transloco: { version: update.version } }}</a
      >
    } @else {
      …the existing release-notes anchor, unchanged…
    }
  }
```

i18n: `reader.updateAvailableAdminLabel` — en `"Update available: version {{version}} — open About"`, de `"Update verfügbar: Version {{version}} — „Über“ öffnen"`.

- [ ] **Step 4: Run** the sidebar-foot spec — PASS.

- [ ] **Step 5: Commit** `feat(#1947): send admins from the sidebar update notice to About`

---


## Self-update (#1948)

### The state directory contract

Every task below reads or writes `${REPO_ROOT}/.update-state/` (mode `1777`, gitignored). Mounted at `/state` in the updater and at `/app/update-state` in `php` — outside `var/`, which the php entrypoint `chown -R`s to www-data on every start.

| File | Writer | Content |
|---|---|---|
| `heartbeat` | watcher, every poll | Unix seconds, one line |
| `request.json` | backend | `{"id":"<16 hex>","requestedBy":"<email>","requestedAt":"<ISO 8601 UTC>"}` |
| `status.json` | watcher, run | `{"requestId":"…","phase":"running\|succeeded\|failed","startedAt":"<ISO>","finishedAt":"<ISO>\|null","exitCode":<int>\|null}` |
| `run.log` | run | `update.sh` output |

A request is **pending** while `request.json`'s `id` differs from `status.json`'s `requestId`. Every write is temp file + `mv`, so a reader never sees half a file. Nobody deletes a file, which is why `1777` (sticky) is enough for two writers with different UIDs.

---

### Task 5: Let `update.sh` run from inside the stack's network

**Files:**
- Modify: `scripts/lib.sh` (`prod_base_url`), `scripts/update.sh` (dev-stack branch)
- Create: `scripts/test/update-in-container.test.sh`
- Modify: `.github/workflows/ci.yml` (add the test next to `release-images.test.sh`)

**Interfaces:**
- Produces: env `SFR_PROD_INTERNAL_HOST` (a compose service name; `prod_base_url` then prints `http(s)://<host>`, no port), env `SFR_SKIP_DEV_STACK` (non-empty → `update.sh` leaves the dev stack alone).

- [ ] **Step 1: Failing test** `scripts/test/update-in-container.test.sh`, harness as in `scripts/test/configure-grafana.test.sh` (source `lib.sh`, `fail()`, temp `ENV_PROD_FILE`):

```bash
printf 'WEB_MODE=http\nWEB_HTTP_PORT=8080\n' > "${ENV_PROD_FILE}"
[ "$(SFR_PROD_INTERNAL_HOST=web prod_base_url)" = 'http://web' ] \
  || fail 'inside the network the health URL names the service on its container port'
[ "$(prod_base_url)" = 'http://localhost:8080' ] \
  || fail 'on the host the health URL keeps the published port'

grep -q 'SFR_SKIP_DEV_STACK' "${_dir}/../update.sh" \
  || fail 'update.sh lets an in-stack run skip the development stack'
printf 'ok\n'
```

- [ ] **Step 2: Run** `scripts/test/update-in-container.test.sh` — FAIL.

- [ ] **Step 3: Implement.** Top of `prod_base_url`:

```bash
  # The updater's run sits on the stack's network, where localhost is itself.
  if [ -n "${SFR_PROD_INTERNAL_HOST:-}" ]; then
    if [ "$(prod_web_mode)" = tls ]; then
      printf 'https://%s' "${SFR_PROD_INTERNAL_HOST}"
    else
      printf 'http://%s' "${SFR_PROD_INTERNAL_HOST}"
    fi
    return 0
  fi
```

`update.sh`, the dev-stack condition becomes:

```bash
if [ -z "${SFR_SKIP_DEV_STACK:-}" ] && [ -n "$(compose ps -aq php 2>/dev/null)" ]; then
```

and `usage()` gains, under Environment: `SFR_SKIP_DEV_STACK      Non-empty: update the production stack only.`

- [ ] **Step 4: Run** the test and `shellcheck scripts/*.sh scripts/test/*.sh` — PASS, clean.

- [ ] **Step 5: Commit** `feat(#1948): let update.sh run from inside the production stack's network`

---

### Task 6: The updater image, its scripts and its service

**Files:**
- Create: `docker/updater/Dockerfile`, `docker/updater/state.sh`, `docker/updater/watch.sh`, `docker/updater/run.sh`
- Create: `scripts/test/updater.test.sh`
- Modify: `docker-compose.prod.yml` (service `updater`, `php` mount), `.gitignore`, `scripts/prod-start.sh`, `scripts/lib.sh` (`ensure_update_state_directory`), `.github/workflows/release.yml` (both `image:` matrices), `.github/workflows/ci.yml` (shellcheck list + the new test)

**Interfaces:**
- Consumes: Task 5's `SFR_PROD_INTERNAL_HOST`, `SFR_SKIP_DEV_STACK`.
- Produces: compose profile `updater`; env vars the service reads from `.env.prod`: `SFR_REPO_ROOT`, `UPDATER_UID`, `UPDATER_GID`, `UPDATER_SOCKET_GID` (Task 7 writes them); the state contract above.

- [ ] **Step 1: Failing test** `scripts/test/updater.test.sh`. It runs the scripts on the host with a stub `docker` on `PATH` that logs its arguments and answers `ps` from a file:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Unit test for the #1948 updater: the watcher's heartbeat, one run per request,
# and a run that vanished (host reboot) being marked failed. docker is stubbed.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
updater="${_dir}/../../docker/updater"
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
mkdir -p "${work}/bin" "${work}/state" "${work}/repo/scripts"
cat > "${work}/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${STUB_LOG}"
if [ "$1" = ps ]; then cat "${STUB_RUNNING}" 2>/dev/null || true; fi
EOF
chmod +x "${work}/bin/docker"

export PATH="${work}/bin:${PATH}" STUB_LOG="${work}/docker.log" STUB_RUNNING="${work}/running"
export SFR_STATE_DIR="${work}/state" SFR_REPO_ROOT="${work}/repo" SFR_UPDATER_LIB="${updater}/state.sh"
export UPDATER_IMAGE=updater:test UPDATER_SOCKET_GID=0 SFR_RUN_NETWORK=net

watch_once() { bash "${updater}/watch.sh" --once; }

watch_once
[ -s "${work}/state/heartbeat" ] || fail 'a tick writes the heartbeat'
if grep -q '^run' "${STUB_LOG}" 2>/dev/null; then fail 'no request, no run'; fi

printf '{"id":"abc","requestedBy":"a@b","requestedAt":"2026-10-31T10:00:00Z"}' > "${work}/state/request.json"
watch_once
grep -q '^run .*--name sfr-update-run' "${STUB_LOG}" || fail 'a new request starts the run'
[ "$(jq -r .phase "${work}/state/status.json")" = running ] || fail 'the watcher marks the request running'
[ "$(jq -r .requestId "${work}/state/status.json")" = abc ] || fail 'status names the request'

printf 'c0ffee\n' > "${STUB_RUNNING}"
: > "${STUB_LOG}"
watch_once
if grep -q '^run' "${STUB_LOG}"; then fail 'a handled request never starts a second run'; fi

: > "${STUB_RUNNING}"
watch_once
[ "$(jq -r .phase "${work}/state/status.json")" = failed ] || fail 'a running status with no run container is a failed run'

printf '#!/usr/bin/env bash\necho updating\nexit 3\n' > "${work}/repo/scripts/update.sh"
chmod +x "${work}/repo/scripts/update.sh"
bash "${updater}/run.sh" abc
[ "$(jq -r .phase "${work}/state/status.json")" = failed ] || fail 'a non-zero update.sh fails the run'
[ "$(jq -r .exitCode "${work}/state/status.json")" = 3 ] || fail 'the run records the exit code'
grep -q updating "${work}/state/run.log" || fail 'the run logs update.sh output'
printf 'ok\n'
```

- [ ] **Step 2: Run** `scripts/test/updater.test.sh` — FAIL (scripts missing). Needs `jq` on the host (CI's ubuntu runner has it).

- [ ] **Step 3: `docker/updater/state.sh`** — sourced by both halves:

```bash
# shellcheck shell=bash
# The state directory contract shared by watch.sh and run.sh (#1948).
STATE_DIR=${SFR_STATE_DIR:-/state}
RUN_CONTAINER=sfr-update-run

now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

json_field() {
  local file=$1 field=$2
  [ -f "${file}" ] || return 0
  jq -r --arg field "${field}" '.[$field] // empty' "${file}"
}

write_atomically() {
  local target=$1
  cat > "${target}.tmp"
  mv -f "${target}.tmp" "${target}"
}

write_status() {
  local request_id=$1 phase=$2 started_at=$3 finished_at=$4 exit_code=$5
  jq -n --arg requestId "${request_id}" --arg phase "${phase}" --arg startedAt "${started_at}" \
    --arg finishedAt "${finished_at}" --arg exitCode "${exit_code}" \
    '{requestId: $requestId, phase: $phase, startedAt: $startedAt,
      finishedAt: (if $finishedAt == "" then null else $finishedAt end),
      exitCode: (if $exitCode == "" then null else ($exitCode | tonumber) end)}' \
    | write_atomically "${STATE_DIR}/status.json"
}
```

- [ ] **Step 4: `docker/updater/watch.sh`:**

```bash
#!/usr/bin/env bash
set -euo pipefail

# The updater's resident half (#1948): a heartbeat the app reads as "the
# updater is here", and one update run per new request.

# shellcheck source=docker/updater/state.sh
source "${SFR_UPDATER_LIB:-/usr/local/lib/sfr-updater/state.sh}"
: "${SFR_REPO_ROOT:?set SFR_REPO_ROOT in .env.prod (./scripts/prod-configure.sh)}"
POLL_SECONDS=${SFR_UPDATER_POLL_SECONDS:-30}

run_in_progress() {
  [ -n "$(docker ps -q --filter "name=^${RUN_CONTAINER}$")" ]
}

# Paths after -v are host paths: the daemon resolves them, not this container.
start_run() {
  local request_id=$1
  : > "${STATE_DIR}/run.log"
  write_status "${request_id}" running "$(now_iso)" '' ''
  docker run -d --rm --name "${RUN_CONTAINER}" \
    --user "$(id -u):$(id -g)" --group-add "${UPDATER_SOCKET_GID}" \
    --network "${SFR_RUN_NETWORK}" \
    -e SFR_REPO_ROOT -e HOME=/tmp -e SFR_PROD_INTERNAL_HOST=web -e SFR_SKIP_DEV_STACK=1 \
    -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "${SFR_REPO_ROOT}:${SFR_REPO_ROOT}" \
    -v "${SFR_REPO_ROOT}/.update-state:/state" \
    --entrypoint sfr-updater-run "${UPDATER_IMAGE}" "${request_id}" >/dev/null
}

tick() {
  date -u +%s | write_atomically "${STATE_DIR}/heartbeat"

  local status_file="${STATE_DIR}/status.json"
  if [ "$(json_field "${status_file}" phase)" = running ] && ! run_in_progress; then
    write_status "$(json_field "${status_file}" requestId)" failed \
      "$(json_field "${status_file}" startedAt)" "$(now_iso)" ''
  fi

  local request_id
  request_id=$(json_field "${STATE_DIR}/request.json" id)
  if [ -z "${request_id}" ] || [ "${request_id}" = "$(json_field "${status_file}" requestId)" ]; then
    return 0
  fi
  if run_in_progress; then
    return 0
  fi
  start_run "${request_id}" \
    || write_status "${request_id}" failed "$(now_iso)" "$(now_iso)" ''
}

if [ "${1:-}" = --once ]; then
  tick
  exit 0
fi
while true; do
  tick
  sleep "${POLL_SECONDS}"
done
```

- [ ] **Step 5: `docker/updater/run.sh`:**

```bash
#!/usr/bin/env bash
set -euo pipefail

# One update run (#1948), in its own container outside the compose project so
# the stack rebuild update.sh triggers cannot stop it.

# shellcheck source=docker/updater/state.sh
source "${SFR_UPDATER_LIB:-/usr/local/lib/sfr-updater/state.sh}"
request_id=$1
started_at=$(json_field "${STATE_DIR}/status.json" startedAt)

exit_code=0
"${SFR_REPO_ROOT}/scripts/update.sh" >> "${STATE_DIR}/run.log" 2>&1 || exit_code=$?

phase=succeeded
if [ "${exit_code}" -ne 0 ]; then
  phase=failed
fi
write_status "${request_id}" "${phase}" "${started_at}" "$(now_iso)" "${exit_code}"
```

- [ ] **Step 6: Run** the test — PASS; `shellcheck docker/updater/*.sh scripts/test/updater.test.sh` — clean.

- [ ] **Step 7: `docker/updater/Dockerfile`** (build context is the repo root, as for php and web):

```dockerfile
# The #1948 updater: Docker CLI + compose plugin and what update.sh needs on top.
FROM docker:27.5.1-cli
RUN apk add --no-cache bash git curl coreutils jq
COPY docker/updater/state.sh /usr/local/lib/sfr-updater/state.sh
COPY docker/updater/watch.sh /usr/local/bin/sfr-updater-watch
COPY docker/updater/run.sh /usr/local/bin/sfr-updater-run
ENTRYPOINT ["/usr/local/bin/sfr-updater-watch"]
```

- [ ] **Step 8: Compose.** `docker-compose.prod.yml`, a new service after `web`:

```yaml
  # #1948: runs ./scripts/update.sh when an admin asks for it in Settings → About.
  # The only service holding the Docker socket, which is root on the host; it
  # publishes nothing and the app can only ask for "the latest release".
  # Defaults are permissive for the same reason as grafana's: compose resolves
  # them while the profile is off.
  updater:
    image: ghcr.io/larspohlmann/simple-feed-reader-updater:${APP_IMAGE_TAG:-local}
    build:
      context: .
      dockerfile: docker/updater/Dockerfile
    profiles: ["updater"]
    restart: unless-stopped
    user: "${UPDATER_UID:-0}:${UPDATER_GID:-0}"
    group_add:
      - "${UPDATER_SOCKET_GID:-0}"
    read_only: true
    cap_drop: [ALL]
    tmpfs: [/tmp]
    mem_limit: 64m
    environment:
      SFR_REPO_ROOT: ${SFR_REPO_ROOT:-}
      UPDATER_IMAGE: ghcr.io/larspohlmann/simple-feed-reader-updater:${APP_IMAGE_TAG:-local}
      UPDATER_SOCKET_GID: ${UPDATER_SOCKET_GID:-0}
      SFR_RUN_NETWORK: simple-feed-reader-prod_default
      HOME: /tmp
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - ./.update-state:/state
```

`php`'s `volumes:` gains `- ./.update-state:/app/update-state` (not the worker's).

`.gitignore`, under the `.installed-commit` entry:

```
# The self-update hand-over between the app and the updater container (#1948).
/.update-state/
```

- [ ] **Step 9: The directory exists before compose mounts it.** `lib.sh`:

```bash
# Created here, not by docker: a bind mount docker creates is root-owned 0755,
# and php (www-data) and the updater (the host user) both write into it.
ensure_update_state_directory() {
  mkdir -p "${REPO_ROOT}/.update-state"
  chmod 1777 "${REPO_ROOT}/.update-state"
}
```

`prod-start.sh` calls it just before `export_build_version_args`.

- [ ] **Step 10: Release images.** `.github/workflows/release.yml`: both `image: [php, web]` matrices become `image: [php, web, updater]`; the `IMAGE_PREFIX` comment names `{php,web,updater}`. Operator note for the PR: the first release that pushes `simple-feed-reader-updater` creates a **private** GHCR package — make it public once, or every release install falls back to building locally.

- [ ] **Step 11: CI.** Append `docker/updater/*.sh` to the `shellcheck` line in `ci.yml` and add a step running `scripts/test/updater.test.sh` next to the other script tests.

- [ ] **Step 12: Build check:** `docker build -f docker/updater/Dockerfile -t sfr-updater:check .` and `docker run --rm --entrypoint sh sfr-updater:check -c 'bash --version && git --version && docker compose version && jq --version'` — all four print.

- [ ] **Step 13: Commit** `feat(#1948): add the opt-in updater container`

---

### Task 7: Installer question

**Files:**
- Modify: `scripts/lib.sh`, `scripts/install.sh`, `scripts/prod-configure.sh`, `scripts/prod-start.sh`, `.env.prod.example`, `.github/workflows/ci.yml`
- Create: `scripts/test/configure-updater.test.sh`

**Interfaces:**
- Consumes: profile `updater` and the env vars of Task 6.
- Produces: `prod_uses_updater`, `configure_updater <default>`, `current_updater_choice`, `stop_disabled_updater_container`, `docker_socket_gid`.

- [ ] **Step 1: Failing test** `scripts/test/configure-updater.test.sh` — copy `configure-grafana.test.sh`'s harness (canned answer queue replacing the prompts) and assert:
  - `configure_updater 'n'` with an empty answer leaves `UPDATER_ENABLED` empty and `prod_compose_profiles` without `updater`;
  - answering `y` sets `UPDATER_ENABLED=1`, `SFR_REPO_ROOT` to `${REPO_ROOT}`, `UPDATER_UID`/`UPDATER_GID` to `id -u`/`id -g`, `UPDATER_SOCKET_GID` to the stubbed `docker_socket_gid` (redefine it after sourcing: `docker_socket_gid() { printf '991'; }`), and `prod_compose_profiles` ends in `updater`;
  - `current_updater_choice` prints `y` after that and `n` on an empty file;
  - headless (`can_prompt() { return 1; }`) applies the passed default.

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** in `lib.sh`, next to the Grafana functions and in their style:

```bash
# Whether the operator opted in to the self-update container (#1948).
prod_uses_updater() {
  [ "$(trim_whitespace "$(env_prod_get UPDATER_ENABLED)")" = 1 ]
}

# The group that owns the Docker socket as containers see it -- not always the
# host's docker group (Docker Desktop maps its own).
docker_socket_gid() {
  docker run --rm --entrypoint stat -v /var/run/docker.sock:/var/run/docker.sock \
    docker:27.5.1-cli -c %g /var/run/docker.sock
}

configure_updater() {
  local default=$1 choice
  if ! can_prompt; then
    apply_updater_choice "${default}"
    return 0
  fi
  say 'Allow updates from the app?'
  tell '  An updater container lets an admin run the update from Settings -> About.'
  tell '  It holds the Docker socket, which is root on this host; it publishes'
  tell '  nothing and can only update to the latest release.'
  choice=$(prompt_with_default 'Enable updates from the app? (y/n)' "${default}")
  apply_updater_choice "${choice}"
}

apply_updater_choice() {
  case "$1" in
    [yY]*) use_updater ;;
    *) use_no_updater ;;
  esac
}

current_updater_choice() {
  if prod_uses_updater; then
    printf 'y'
    return 0
  fi
  printf 'n'
}

use_no_updater() {
  env_prod_set UPDATER_ENABLED ''
  say 'Updates run from the shell only (./scripts/update.sh).'
}

use_updater() {
  env_prod_set UPDATER_ENABLED 1
  env_prod_set SFR_REPO_ROOT "${REPO_ROOT}"
  env_prod_set UPDATER_UID "$(id -u)"
  env_prod_set UPDATER_GID "$(id -g)"
  env_prod_set UPDATER_SOCKET_GID "$(docker_socket_gid)"
  say 'Admins can run the update from Settings -> About.'
}

stop_disabled_updater_container() {
  if prod_uses_updater; then
    return 0
  fi
  if [ -z "$(prod_compose ps -aq updater 2>/dev/null)" ]; then
    return 0
  fi
  say 'Updates from the app are disabled -- removing the updater container ...'
  prod_compose rm -sf updater >/dev/null
}
```

`prod_compose_profiles` gains, after the grafana branch:

```bash
  if prod_uses_updater; then
    profiles="${profiles:+${profiles},}updater"
  fi
```

and its comment names `'updater'`. `install.sh`, after `configure_grafana 'n'`: `configure_updater 'n'` (opt-in: it is root on the host). `prod-configure.sh`, after the Grafana line: `configure_updater "$(current_updater_choice)"`. `prod-start.sh`, after `stop_disabled_grafana_containers`: `stop_disabled_updater_container`. `.env.prod.example`: a commented block for `UPDATER_ENABLED`, `SFR_REPO_ROOT`, `UPDATER_UID`, `UPDATER_GID`, `UPDATER_SOCKET_GID`, written by the installer.

- [ ] **Step 4: Run** the new test, `configure-grafana.test.sh`, `configure-search-engine.test.sh` and shellcheck — PASS. Add the test to `ci.yml`.

- [ ] **Step 5: Commit** `feat(#1948): ask about updates from the app at install`

- [ ] **Step 6: Ask once on the first update (user request).** An install from before this release has no `UPDATER_ENABLED` key at all. Its first `./scripts/update.sh` must ask the question. The asking lives in **`prod-start.sh`, not `update.sh`**: an old install runs its *old* `update.sh`, which checks out the new release and then calls the *new* `prod-start.sh`, so only `prod-start.sh` reaches that first update.
  - `lib.sh`: `updater_choice_recorded()` — true when `.env.prod` has a `UPDATER_ENABLED=` line (any value, empty included; `grep -qE '^UPDATER_ENABLED='`). `offer_updater_if_undecided()` — returns when recorded; with a terminal (`can_prompt`) runs `configure_updater 'n'`, which records the answer either way; without one prints `say 'Updates from the app are off -- enable them with ./scripts/prod-configure.sh.'` and records nothing, so a later interactive update still asks.
  - `prod-start.sh` calls `offer_updater_if_undecided` before `ensure_update_state_directory` (before the profiles are computed).
  - `install.sh`'s quick package (Q) branch calls `use_no_updater` next to `use_no_grafana`, so a quick install records "no" and `prod-start.sh` never asks it.
  - Tests in `configure-updater.test.sh`: key absent + terminal + `y` → enabled; key absent + headless → key still absent and the hint printed; key present and empty → no prompt; `prod-start.sh` calls `offer_updater_if_undecided` before `ensure_update_state_directory` (order check by line number).

---

### Task 8: Backend — status and request endpoints

**Files (all under `backend/`):**
- Create: `src/Service/SelfUpdate/SelfUpdater.php`
- Create: `src/Service/SelfUpdate/Model/SelfUpdatePhase.php`, `UpdateRunModel.php`, `UpdateRequestModel.php`, `SelfUpdateStatusModel.php`
- Create: `src/Service/SelfUpdate/UpdateStateStore/UpdateStateStoreInterface.php`, `FileUpdateStateStore.php`
- Create: `src/Service/SelfUpdate/Exception/UpdaterUnavailableException.php`, `UpdateInProgressException.php`, `NoUpdateAvailableException.php`, `MalformedUpdateStateException.php`, `UpdateRequestNotSavedException.php`
- Create: `src/Http/Admin/SelfUpdateStatusJson.php`, `src/Http/Problem/ExceptionProblems/SelfUpdateProblems.php`, `src/Controller/Admin/AdminUpdateController.php`
- Modify: `config/services.yaml` (parameter + interface alias)
- Tests: `tests/Service/SelfUpdate/SelfUpdaterTest.php`, `tests/Service/SelfUpdate/UpdateStateStore/FileUpdateStateStoreTest.php`, `tests/Http/Problem/ExceptionProblems/SelfUpdateProblemsTest.php`, `tests/Controller/Admin/AdminUpdateControllerTest.php`

**Interfaces:**
- Consumes: `App\Service\Version\VersionReporter::report(): VersionReportModel` (`->updateAvailable`), `Psr\Clock\ClockInterface`, the state contract.
- Produces: `GET /api/admin/update` → `{updaterPresent: bool, phase: "idle"|"requested"|"running"|"succeeded"|"failed", startedAt: string|null, finishedAt: string|null, exitCode: int|null, log: string}`; `POST /api/admin/update` → `202` with the same body; problems `updater_unavailable` (503), `update_in_progress` (409), `no_update_available` (409).

- [ ] **Step 1: Models.**

```php
enum SelfUpdatePhase: string
{
    case Idle = 'idle';
    case Requested = 'requested';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isUnderway(): bool
    {
        return self::Requested === $this || self::Running === $this;
    }
}

final readonly class UpdateRunModel
{
    public function __construct(
        public string $requestId,
        public SelfUpdatePhase $phase,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?int $exitCode,
    ) {
    }
}

final readonly class UpdateRequestModel
{
    public function __construct(
        public string $id,
        public string $requestedBy,
        public \DateTimeImmutable $requestedAt,
    ) {
    }

    public function toStateFileJson(): string
    {
        return json_encode([
            'id' => $this->id,
            'requestedBy' => $this->requestedBy,
            'requestedAt' => $this->requestedAt->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
    }
}

final readonly class SelfUpdateStatusModel
{
    public function __construct(
        public bool $updaterPresent,
        public SelfUpdatePhase $phase,
        public ?UpdateRunModel $lastRun,
        public string $log,
    ) {
    }
}
```

- [ ] **Step 2: Store interface.**

```php
interface UpdateStateStoreInterface
{
    public function heartbeatAt(): ?\DateTimeImmutable;

    public function pendingRequestId(): ?string;

    public function lastRun(): ?UpdateRunModel;

    public function logTail(): string;

    public function saveRequest(UpdateRequestModel $request): void;
}
```

`pendingRequestId()` returns `request.json`'s `id`, or null without the file; the service decides whether it is still pending.

- [ ] **Step 3: Failing `FileUpdateStateStoreTest`** against a temp directory (`sys_get_temp_dir()` + `uniqid()`, removed in `tearDown`): absent directory → `heartbeatAt()`, `pendingRequestId()`, `lastRun()` null, `logTail()` `''`; a `heartbeat` of `1793440800` → that instant (UTC); a `status.json` per the contract → the matching `UpdateRunModel` (dates parsed as UTC); a `status.json` holding `[]` or bad JSON → `MalformedUpdateStateException`; `logTail()` of a 40 KiB log returns its last 16 KiB; `saveRequest()` writes `request.json` that `pendingRequestId()` reads back, and leaves no `request.json.tmp`.

- [ ] **Step 4: Implement `FileUpdateStateStore`** — `final readonly`, `#[Autowire('%update_state_directory%')] private string $directory`, constant `private const int LOG_TAIL_BYTES = 16384`. One private reader per file (`decodedFile(string $name): ?array` throwing `MalformedUpdateStateException` on non-object JSON, `stringOrNull`, `dateOrNull`), the log tail via `fopen` + `fseek(-LOG_TAIL_BYTES, SEEK_END)` guarded by `filesize`. `saveRequest` writes `request.json.tmp` then `rename()`; a failed write or rename throws `UpdateRequestNotSavedException` (`MalformedUpdateStateException` stays for reads). Keep each method short enough for PHPMD.

- [ ] **Step 5: Failing `SelfUpdaterTest`** — an in-memory `UpdateStateStoreInterface` double in the test file, `Symfony\Component\Clock\MockClock('2026-10-31 10:00:00')`, and a real `VersionReporter` built from interface doubles as in `tests/Controller/Api/VersionControllerTest.php::reporterReporting()`. Cases:
  - no heartbeat → `updaterPresent` false; heartbeat 89 s old → true; 91 s → false;
  - no request, no run → `Idle`; request `abc`, run `abc` succeeded → `Succeeded`; request `def`, run `abc` → `Requested`;
  - `request('boss@example.com')`: updater absent → `UpdaterUnavailableException`; phase `Requested` or `Running` → `UpdateInProgressException`; no update available → `NoUpdateAvailableException`; otherwise the store holds one request by `boss@example.com` at the clock's now with a 16-hex id.

- [ ] **Step 6: Implement `SelfUpdater`:**

```php
final readonly class SelfUpdater
{
    private const int HEARTBEAT_FRESH_SECONDS = 90;

    public function __construct(
        private UpdateStateStoreInterface $state,
        private VersionReporter $versions,
        private ClockInterface $clock,
    ) {
    }

    public function status(): SelfUpdateStatusModel
    {
        $lastRun = $this->state->lastRun();

        return new SelfUpdateStatusModel(
            $this->updaterPresent(),
            $this->phase($lastRun),
            $lastRun,
            $this->state->logTail(),
        );
    }

    public function request(string $requestedBy): void
    {
        if (!$this->updaterPresent()) {
            throw new UpdaterUnavailableException('No updater container is running.');
        }
        if ($this->phase($this->state->lastRun())->isUnderway()) {
            throw new UpdateInProgressException('An update is already under way.');
        }
        if (!$this->versions->report()->updateAvailable) {
            throw new NoUpdateAvailableException('This install already runs the latest release.');
        }

        $this->state->saveRequest(
            new UpdateRequestModel(bin2hex(random_bytes(8)), $requestedBy, $this->clock->now()),
        );
    }

    private function updaterPresent(): bool
    {
        $heartbeat = $this->state->heartbeatAt();

        return null !== $heartbeat
            && $this->clock->now()->getTimestamp() - $heartbeat->getTimestamp() <= self::HEARTBEAT_FRESH_SECONDS;
    }

    private function phase(?UpdateRunModel $lastRun): SelfUpdatePhase
    {
        $pending = $this->state->pendingRequestId();
        if (null !== $pending && $pending !== $lastRun?->requestId) {
            return SelfUpdatePhase::Requested;
        }

        return $lastRun->phase ?? SelfUpdatePhase::Idle;
    }
}
```

Exceptions: `final class … extends \RuntimeException`, one per file, as in `src/Service/Backup/Exception/`.

- [ ] **Step 7: Wiring.** `config/services.yaml` `parameters:`:

```yaml
    # Where the updater container and the app hand over an update (#1948); docker-compose.prod.yml mounts it.
    update_state_directory: '%kernel.project_dir%/update-state'
```

with `when@test: parameters: update_state_directory: '%kernel.cache_dir%/update-state'` (the cache dir is per `TEST_TOKEN`), and next to the Version aliases:

```yaml
    App\Service\SelfUpdate\UpdateStateStore\UpdateStateStoreInterface: '@App\Service\SelfUpdate\UpdateStateStore\FileUpdateStateStore'
```

- [ ] **Step 8: HTTP.** `SelfUpdateProblems` in `BackupProblems`' shape: `UpdaterUnavailableException` → `updater_unavailable`, "The updater is not running", `Response::HTTP_SERVICE_UNAVAILABLE`; `UpdateInProgressException` → `update_in_progress`, "An update is already under way", `HTTP_CONFLICT`; `NoUpdateAvailableException` → `no_update_available`, "No update available", `HTTP_CONFLICT`. Its test mirrors `tests/Http/Problem/ExceptionProblems/ChapterProblemsTest.php`.

`SelfUpdateStatusJson::of(SelfUpdateStatusModel $status): array` → the Produces shape (dates `DATE_ATOM`, `log` = `$status->log`).

```php
#[Route('/api/admin/update')]
final readonly class AdminUpdateController
{
    public function __construct(private SelfUpdater $updater)
    {
    }

    #[Route('', name: 'api_admin_update_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(SelfUpdateStatusJson::of($this->updater->status()));
    }

    #[Route('', name: 'api_admin_update_request', methods: ['POST'])]
    public function request(#[CurrentUser] User $admin): JsonResponse
    {
        $this->updater->request($admin->getEmail());

        return new JsonResponse(SelfUpdateStatusJson::of($this->updater->status()), Response::HTTP_ACCEPTED);
    }
}
```

(Check how admin routes get `ROLE_ADMIN` — `security.yaml` `access_control` on `^/api/admin` — `AdminRouteGuardTest` already proves every admin route refuses a non-admin, and picks the new routes up by itself.)

- [ ] **Step 9: Failing `AdminUpdateControllerTest`** (style of `AdminQueueControllerTest`), writing state files into `self::getContainer()->getParameter('update_state_directory')`: no token → 401; admin, no state directory → `200` with `updaterPresent` false and `phase` `idle`; POST with no updater → `503` `updater_unavailable`; fresh heartbeat + a pending request → POST `409` `update_in_progress`; fresh heartbeat, nothing pending → POST `409` `no_update_available` (the test env reports no latest release, see `VersionControllerTest`). The `202` path is a direct-invocation test with a `SelfUpdater` over the in-memory store and a reporter reporting `v1.1.0` over `v1.0.0`, as `VersionControllerTest::testMapsAnAvailableReleaseIntoThePayload` does.

- [ ] **Step 10: Run** `php bin/phpunit tests/Service/SelfUpdate tests/Controller/Admin tests/Http/Problem` then `composer check` and `composer md` — PASS, clean.

- [ ] **Step 11: Commit** `feat(#1948): report and request updates through /api/admin/update`

---

### Task 9: Frontend — the update block in admin About

**Files:**
- Create: `frontend/src/app/settings/admin/about/self-update.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/admin/admin-api.ts`, `frontend/src/app/admin/admin.models.ts`, `frontend/src/app/settings/admin/about/about-section.component.{ts,html}`, `frontend/public/i18n/{en,de}.json`

**Interfaces:**
- Consumes: Task 8's endpoints, `VersionService.updateAvailable/latest/load`, `ConfirmService.ask(data: ConfirmData): Observable<boolean>`.
- Produces: `SelfUpdateStatusDto`, `AdminApi.updateStatus()`, `AdminApi.requestUpdate()`, `<app-self-update />`.

- [ ] **Step 1: Model + API.**

```ts
export type SelfUpdatePhase = 'idle' | 'requested' | 'running' | 'succeeded' | 'failed';

export interface SelfUpdateStatusDto {
  updaterPresent: boolean;
  phase: SelfUpdatePhase;
  startedAt: string | null;
  finishedAt: string | null;
  exitCode: number | null;
  log: string;
}
```

```ts
  updateStatus(): Observable<SelfUpdateStatusDto> {
    return this.http.get<SelfUpdateStatusDto>(`${this.base}/api/admin/update`);
  }

  requestUpdate(): Observable<SelfUpdateStatusDto> {
    return this.http.post<SelfUpdateStatusDto>(`${this.base}/api/admin/update`, {});
  }
```

- [ ] **Step 2: Failing spec** `self-update.component.spec.ts` (AdminApi, VersionService and ConfirmService stubbed with signals/`of()`; `jest.useFakeTimers()` for the poll):
  - updater absent → `.manual` shows `./scripts/update.sh`, no button;
  - present, `updateAvailable` true, phase `idle` → a button "Update to v9.9.9"; clicking it asks `ConfirmService` (title/message keys) and, on `true`, calls `requestUpdate`; on `false` it does not;
  - present, no update, phase `idle` → nothing rendered;
  - phase `running` → status text and `log` in a `<pre class="log">`, no button; it polls `updateStatus` again after 10 s;
  - phase going `running` → `succeeded` calls `VersionService.load()` once;
  - phase `failed` → the failure text with the exit code, the log, and the button again when an update is still available;
  - a failed poll while running (API down mid-rebuild) keeps the last status and polls on.

- [ ] **Step 3: Implement.** Component state: `status = signal<SelfUpdateStatusDto | null>(null)`; `ngOnInit` loads once; a `timer(10_000, 10_000)` subscription (`takeUntilDestroyed`) refetches only while `status()?.phase` is `requested` or `running`, `catchError(() => EMPTY)` per request; an `effect`-free transition check in the `next` handler calls `versions.load()` when the phase moves into `succeeded`. Template states as in the spec; the confirm message suggests the database dump (`docs/backup.md`) and links nothing (ConfirmData is text). Styles: `.log` — `max-block-size` from a spacing token multiple, `overflow: auto`, `font-family: var(--font-mono)` (check the token name in `src/styles/`), `white-space: pre-wrap`. Put `<app-self-update />` in `about-section.component.html` after the version rows, inside the `@else` of `loading()`.

i18n under `settings.about.update`: `button` "Update to {{version}}" / "Auf {{version}} aktualisieren"; `confirmTitle` "Update this install?" / "Diese Installation aktualisieren?"; `confirmMessage` "The app restarts during the update and is unavailable for a few minutes. Take a database dump first if this release changes the schema." / "Die App startet während des Updates neu und ist einige Minuten nicht erreichbar. Lege vorher einen Datenbank-Dump an, wenn dieses Release das Schema ändert."; `confirm` "Update" / "Aktualisieren"; `requested` "Update requested — the updater picks it up within a minute." / "Update angefordert — der Updater übernimmt es innerhalb einer Minute."; `running` "Updating…" / "Wird aktualisiert…"; `succeeded` "Update finished." / "Update abgeschlossen."; `failed` "The update failed (exit code {{code}})." / "Das Update ist fehlgeschlagen (Exit-Code {{code}})."; `manual` "Run ./scripts/update.sh on the host to update. To update from here, enable it with ./scripts/prod-configure.sh." / "Führe ./scripts/update.sh auf dem Host aus, um zu aktualisieren. Für Updates von hier aus aktiviere sie mit ./scripts/prod-configure.sh.".

- [ ] **Step 4: Run** `docker compose exec -T frontend npm test -- src/app/settings/admin/about` — PASS.

- [ ] **Step 5: Commit** `feat(#1948): run the update from the admin About page`

---

### Task 10: Docs

**Files:** `docs/docker-production.md` (§6 Update), `docs/security.md`

- [ ] §6 gains "From the app": what the installer question enables, where the button is, that it always updates to the latest release, that the app is down for the rebuild, the `.update-state/` directory, how to turn it off (`./scripts/prod-configure.sh`), and that a run's log stays in `.update-state/run.log`.
- [ ] §6 also covers: an install older than this release gets it through `./scripts/update.sh` as before and turns the updater on with `./scripts/prod-configure.sh` (`update.sh` never asks); and the updater updates itself — the run lives in its own container, so recreating `updater` mid-run is safe.
- [ ] `docs/security.md`: the updater holds the Docker socket (root-equivalent), publishes nothing, and takes no input from the app beyond "a request exists".
- [ ] Contract note for maintainers, in the comment at the top of `docker/updater/state.sh` (one line): the state files are read across versions — an old run writes what a new watcher and backend read — so fields are only ever added. A future `UPDATER_*` setting must be generated by `prod-start.sh` (as `ensure_ai_key_secret` does), since the installer question does not run on update; say so in §6's maintainer paragraph.
- [ ] **Commit** `docs(#1948): document updates from the app`

---

### Task 11: Check for a new release more often

Today the backend asks GitHub at most every 6 h (`GitHubLatestReleaseReader::CACHE_TTL_SECONDS = 21600`) and the app calls `/api/version` once per app load, so an open tab or home-screen app never learns of a release. Agreed with the user: backend cache 1 h; the app re-checks when it returns to the foreground, at most every 30 min. No timer.

**Files:**
- Modify: `backend/src/Service/Version/LatestReleaseReader/GitHubLatestReleaseReader.php`
- Modify: `frontend/src/app/core/version.service.ts`, `frontend/src/app/core/version.service.spec.ts`, `frontend/src/app/reader/shell/reader-shell.component.ts` (its "Check once" comment)

**Interfaces:**
- Consumes: `onTabVisibilityChange(onChange: (hidden: boolean) => void)` from `frontend/src/app/core/tab-visibility.ts` (runs outside the Angular zone; call in an injection context).
- Produces: unchanged `VersionService` API; `load()` additionally records when it ran.

- [ ] **Step 1: Backend.** `private const int CACHE_TTL_SECONDS = 3600; // one hour`. No test asserts the lifetime; none is added (a constant).

- [ ] **Step 2: Failing tests** in `version.service.spec.ts` (`jest.useFakeTimers()` + `jest.setSystemTime`, `afterEach(() => jest.useRealTimers())`; a helper that sets `document.visibilityState` via `Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state })` and dispatches `new Event('visibilitychange')`):

```ts
  it('checks again when the tab comes back after half an hour', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 30 * 60_000);
    showTab();

    ctrl.expectOne('https://api.test/api/version').flush(release);
  });

  it('does not check again when the tab comes back sooner', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 29 * 60_000);
    showTab();

    ctrl.expectNone('https://api.test/api/version');
  });

  it('does not check while the tab is hidden', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 60 * 60_000);
    hideTab();

    ctrl.expectNone('https://api.test/api/version');
  });

  it('waits for the first load before checking on its own', () => {
    showTab();
    ctrl.expectNone('https://api.test/api/version');
  });
```

- [ ] **Step 3: Run** `docker compose exec -T frontend npm test -- src/app/core/version.service` — FAIL.

- [ ] **Step 4: Implement** in `VersionService`:

```ts
const RECHECK_AFTER_MS = 30 * 60_000;

  private readonly zone = inject(NgZone);
  private lastLoadedAt: number | null = null;

  constructor() {
    onTabVisibilityChange((hidden) => {
      if (!hidden) this.zone.run(() => this.recheckIfDue());
    });
  }

  private recheckIfDue(): void {
    if (this.lastLoadedAt === null || Date.now() - this.lastLoadedAt < RECHECK_AFTER_MS) return;
    this.load();
  }
```

and `load()` sets `this.lastLoadedAt = Date.now();` before the request. Update the reader-shell comment to say the service re-checks on its own when the tab returns (one line).

- [ ] **Step 5: Run** the spec, then every spec that injects `VersionService` unstubbed (`grep -rln "VersionService" frontend/src/app --include=*.spec.ts`), then `cd frontend && npm run check` — PASS. Backend: `php bin/phpunit tests/Service/Version` — PASS.

- [ ] **Step 6: Commit** `feat(#1948): check for a new release when the app returns, and hourly upstream`

---

### Task 12: Gates and live checks

- [ ] Frontend: `cd frontend && npm run check` and `npx ng build` — clean.
- [ ] Backend: `composer check`, `composer md`, `php bin/phpunit`, and in parallel `docker compose exec php composer test:parallel` — green; `composer infection:diff` meets `minMsi`.
- [ ] Scripts: every `scripts/test/*.test.sh` touched or added, and `shellcheck` over the CI list — clean.
- [ ] Live, settings (localhost:4200, built-in browser; admin and plain user): Statistics shows "Your reading"; admin sees About last in the Admin group; `/settings/about` lands on Statistics; a plain user gets no About and no pill. With `/api/version` stubbed to `updateAvailable: true`, the pill shows and the sidebar notice opens `/settings/admin/about` for the admin; with no updater the About page shows the manual hint.
- [ ] Live, updater (throwaway prod install from a local clone — memory: installer-e2e-from-local-clone; commit first, it only runs committed code): `SFR_REPO_URL=file://… scripts/install.sh --ref feature/1947-settings-statistics-about <dir>`, answering yes to the updater (headless runs take defaults, so set `UPDATER_ENABLED=1` via `prod-configure.sh` or `.env.prod` + `prod-start.sh`). Check: the updater container runs as the host UID with `mem_limit` 64 MB; `heartbeat` refreshes; About shows the button; clicking it moves to `requested`, then `running` with a growing log, and the run updates the clone to the latest release tag (which has no updater, so the run survives the updater's removal — the point of running outside the project). `status.json` ends `succeeded`, nothing in the checkout is root-owned (`find <dir> -user 0`), and `git status` is clean. Tear down with `down -v` from the clone as the memory describes.
