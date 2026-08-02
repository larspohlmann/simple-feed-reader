# Cache-bust the Transloco dictionaries (#141)

## Problem

The SPA loads its translation dictionaries from `i18n/{lang}.json`. That path is
stable across releases, and the Strato deploy sends no `Cache-Control` header for
it. The browser therefore applies heuristic freshness and can serve the previous
release's copy without revalidating.

A deploy that adds a translation key then renders the raw key on screen. The
first occurrence was `v0.5.0-dev.4`, which showed `discover.adminEmptyWarning`
and `discover.adminEmptyAction` in the admin banner.

Every other asset the SPA loads is content-hashed, so a new release always names
new URLs. The dictionaries are the one exception.

## Decision

Send the release version as a query parameter on the dictionary request.

```
i18n/en.json?v=v0.5.0-dev.4
```

`frontend/src/environments/version.ts` already carries the tag. The release
build rewrites it (`deploy/strato/build-release.sh`), and the build verifies
that the value reaches the emitted JavaScript. Each release therefore names a
URL that no browser cache can hold.

### Alternatives considered

- **`Cache-Control: no-cache` in `.htaccess`.** Smallest diff, but it fixes only
  the Strato host, and it costs a revalidation round-trip per language on every
  load. Any future host repeats the bug.
- **Content-hashed dictionary filenames.** Matches the JavaScript bundles
  exactly, but it needs a build step, a manifest and loader indirection for the
  same outcome.
- **Both a query parameter and a long `immutable` header.** Correct, but the
  query parameter alone closes the bug. The header is extra surface.

## Scope

### `frontend/src/app/core/transloco-loader.ts`

`HttpTranslocoLoader.getTranslation` sends `{ params: { v: buildVersion.version } }`.
The path stays relative, so the `/reader` subpath keeps working. A comment
records why the parameter is there.

### `frontend/src/app/core/transloco-loader.spec.ts`

A second test asserts that the request carries the build version. The existing
relative-path test stays and matches on `urlWithParams`.

## What does not change

- **The server.** The `.htaccess` static-file rule matches on
  `REQUEST_FILENAME`, which excludes the query string, so the file still
  serves. The `404` rule for asset extensions matches on `REQUEST_URI`, which
  mod_rewrite also supplies without the query string.
- **The release build.** `build-release.sh` already greps the emitted JavaScript
  for the tag. The loader imports the same constant, so that check covers this
  fix.

## Accepted limitation

A local build reports `dev`, so the parameter does not change between local
builds. This equals the behaviour today, and the Angular dev server does not
cache the file.

## Verification

- `npm run check` in `frontend/` (ESLint, Prettier, Stylelint, Jest).
- A production bundle built with the release configuration names the parameter
  and the tag in its emitted JavaScript.
