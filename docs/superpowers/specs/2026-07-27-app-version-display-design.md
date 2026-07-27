# App Version Display — Design

**Date:** 2026-07-27
**Issue:** [#135](https://github.com/larspohlmann/simple-feed-reader/issues/135)
**Branch:** `feature/135-app-version-display`

## Problem

Nothing in the running app says which release it is. After pushing a
`vX.Y.Z-dev.N` tag there is no way to confirm from the browser that the new
release actually went live, and when something misbehaves there is no build
identifier to attach to the report. The one divergence that genuinely occurs —
a browser holding a cached SPA while the server already serves a newer release —
is invisible and presents as unexplained misbehaviour.

## Goal

The version is visible at the bottom of the reader sidebar. The full detail —
commit, build date, and the backend's own version — lives in Settings, where a
mismatch between the two halves is stated plainly.

## Non-goals

- A release-notes or changelog view.
- Automatic reload when the versions diverge. The user is told; the user decides.
- An update-available check against GitHub. The backend's version is the only
  "newer" the app knows about.
- Versioning the API itself. `/api/version` reports the build, not a contract
  version.

## Decisions

| Question | Decision |
| --- | --- |
| Where the version is derived | Once, in `build-release.sh`, from the tag |
| How the frontend learns it | Baked at build time into a committed `version.ts`, rewritten on the runner |
| How the backend learns it | `version.json` written into the release root, read at request time |
| Sidebar content | The version string only, as a link to Settings |
| Settings content | App and API rows: version, commit, build date, plus a stale-build note |
| Endpoint auth | Authenticated, via the existing `^/api/` catch-all — no `security.yaml` change |
| Missing `version.json` | Returns a development value; only a malformed file throws |

## Deriving the version

In [`deploy/strato/build-release.sh`](../../../deploy/strato/build-release.sh),
before the frontend build:

```bash
VERSION="${GITHUB_REF_NAME:-}"
case "${VERSION}" in
    v[0-9]*) ;;   # a release tag
    *) VERSION="$(git -C "${ROOT}" describe --tags --always 2>/dev/null || echo dev)" ;;
esac
COMMIT="$(git -C "${ROOT}" rev-parse --short HEAD)"
BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
```

The `case` guard is load-bearing. On a `workflow_dispatch`, `GITHUB_REF_NAME`
holds a *branch* name, so reading it unguarded would ship a build labelled
`develop`. Falling through to `git describe` yields
`v0.5.0-dev.2-4-gabc1234` — honest about sitting between tags. The workflow's
`fetch-depth: 0` is what guarantees `describe` has tags to work with.

One derivation, two artifacts:

- **`${OUT}/version.json`**, written after the backend copy loop. It sits at the
  release root, not under `public/`, so it is never web-served directly; only
  the endpoint exposes it.
- **`frontend/src/environments/version.ts`**, rewritten in the runner's checkout
  before `npm run build`.

## Backend

New `src/Service/Version/`, one subdirectory for the concern:

- **`ReleaseVersion`** — `final readonly class`, promoted constructor, holding
  `version`, `commit`, `builtAt`. Named constructor `ReleaseVersion::development()`.
- **`ReleaseVersionReader`** (interface) and **`FileReleaseVersionReader`**,
  reading `%kernel.project_dir%/version.json`.
- **`Service/Version/Exception/MalformedVersionFile`** for a file that exists but
  cannot be parsed.

A **missing** `version.json` returns `ReleaseVersion::development()` rather than
throwing. This is a deliberate departure from the house rule that failure is
signalled by exceptions: an absent file is not a failure here, it is the normal
state of every local checkout and every Docker run. A **malformed** file is a
failure and throws. The named constructor keeps that distinction visible in the
code instead of buried in a null coalesce.

**`Controller/Api/VersionController`** — `GET /api/version`, returning
`Dto/Version/VersionResponse`:

```json
{ "version": "v0.5.0-dev.3", "commit": "a1b2c3d", "builtAt": "2026-07-27T10:04:11Z" }
```

The route falls under the existing `^/api/` → `IS_AUTHENTICATED_FULLY` rule, so
`security.yaml` is untouched. Bearer JWT, stateless, JSON in and out: the
native-iOS checklist passes without special handling, and a future Swift client
gets the same build identifier the web app shows.

## Frontend

**`frontend/src/environments/version.ts`**, committed carrying placeholders:

```ts
// Rewritten by deploy/strato/build-release.sh on the release runner. These are
// the values a local build reports — do not hand-edit them to a real tag.
export const buildVersion = { version: 'dev', commit: 'local', builtAt: '' } as const;
```

Committing the file with placeholders is what keeps a fresh clone building:
`ng serve`, `npm run build`, and Jest all compile with no generation step. The
runner rewrites it in place; outside CI the script restores it on exit, so a
hand-run build cannot leave a real tag sitting in the working tree.

Two alternatives were considered and rejected. Angular 20's `define` build option
moves the value into a build flag, but leaves TypeScript needing a
`declare const` plus a `typeof … !== 'undefined'` fallback so Jest still compiles.
A gitignored generated file breaks a fresh checkout until something generates it.
Rewriting a committed file is the least machinery for the same result.

**Sidebar** — one line appended after `<app-view-controls class="controls" />` in
[`sidebar.component.html`](../../../frontend/src/app/reader/sidebar/sidebar.component.html),
rendered as a link to Settings so the glance at the version is also the route to
the detail:

```html
<a class="version" routerLink="/settings">{{ version }}</a>
```

`RouterLink` is already imported by the component, and `.controls` already carries
`margin-top: auto`, so the new line rides along at the bottom with no layout
change. Styling stays token-only (`var(--muted)`, `var(--space-*)`); no hex.

**`core/version.service.ts`** — signal-backed, fetches `/api/version` once through
`HttpClient` and `API_BASE_URL`, exposing the API version and an unavailable
state. HTTP stays out of the component, matching the rest of `core/`.

**`settings/about-section.component.*`** — the same four-file shape and
`ChangeDetectionStrategy.OnPush` as its sibling sections, appended to
[`settings.component.html`](../../../frontend/src/app/settings/settings.component.html)
after `<app-account-section />`. Two rows:

| | |
| --- | --- |
| App | `v0.5.0-dev.3` · `a1b2c3d` · built 27 Jul 2026 |
| API | `v0.5.0-dev.3` · `a1b2c3d` · built 27 Jul 2026 |

If the API call fails, the API row reads *unavailable* and the App row still
stands — the block never becomes useless. If the two versions differ, a muted
note says the browser is running an older build and to reload. A note, not an
automatic reload: silently reloading a reader mid-article is worse than the
staleness it fixes.

New transloco keys under `settings.about.*` in both `public/i18n/en.json` and
`public/i18n/de.json`.

## Testing

- **Backend unit** — `FileReleaseVersionReader`: a valid file parses; a missing
  file yields `development()`; a malformed file throws.
- **Backend functional** — `GET /api/version` returns 200 with all three fields
  when authenticated, and 401 anonymously. The 401 goes through the real
  firewall rather than asserting something about configuration.
- **Frontend Jest** — the About section renders both rows, shows the stale-build
  note when the versions differ, and degrades to *unavailable* when the call
  fails; the sidebar spec gains a case for the version line.
- **Deploy guards** — two checks beside the existing content-hash check in
  `build-release.sh`: `version.json` is present in the release, and
  `grep -q "${VERSION}" "${OUT}"/public/main-*.js` proves the rewritten
  `version.ts` actually composed into the bundle. The second catches a silently
  unrewritten file, the same class of failure the content-hash check exists for.

## Accepted risks

- The sidebar version is only as fresh as the loaded bundle. That is the point —
  it reports what is *running*, and the Settings comparison is what surfaces the
  gap.
- `git describe` output for an untagged commit is long and unlovely. It only
  appears on a `workflow_dispatch` deploy, where the alternative is a wrong label.
