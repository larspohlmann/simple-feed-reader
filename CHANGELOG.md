# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version sections below are generated automatically when a release tag is pushed;
see [docs/releasing.md](docs/releasing.md). History before the first release
lives in the git log and the merged pull requests.

## [Unreleased]

## [v0.6.0] - 2026-08-04

## What's Changed
* fix(#275): stop docker compose from eating a piped installer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/276
* feat(#277): let the installer choose SQLite instead of MySQL by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/278


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.3...v0.6.0

## [v0.5.3] - 2026-08-04

## What's Changed
* fix(#250, #260): clear the PhpStorm ERROR findings in backend/src and backend/tests by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/268
* fix(#267): give each list its own scroll position on a view switch by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/269
* feat(#270): keep the article named while it scrolls by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/271
* feat(#272): a production installer that survives a second install, and sets up the catalog it ships by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/273


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.2...v0.5.3

## [v0.5.2] - 2026-08-03

## What's Changed
* feat(#237): make website scraping an opt-in experimental preference by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/239
* feat(#238): a reading-progress bar for the article view by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/240
* feat(#241): an All Items pill in the mobile tag row by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/242
* fix(#141): cache-bust the Transloco dictionaries with the release version by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/243
* fix(#119): tell the user when a refresh accomplished nothing by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/244
* fix(#245): materialize entry.effective_date and index the list sort by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/248
* feat(#246): delete a user account with all of its content, and reclaim orphaned feeds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/249
* fix(#247): show the account status after an OAuth sign-in by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/251
* feat(#252): split the installer's public-URL question into three by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/253
* Run both e2e suites weekly in CI by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/258
* Cover frontend/e2e with the Prettier check by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/259
* fix(#255): drop the host from the outbound User-Agent by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/261
* fix(#254): compress responses and keep the list rendered while it reloads by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/262
* fix(#263): void per-user caches when the signed-in identity changes by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/264
* chore: back-merge main into develop before cutting v0.5.2 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/265


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.1...v0.5.2

### Changed

- The production installer asks how users reach the instance, under which
  hostname, and on which port, instead of one "Public URL" question that
  carried all three. The new first question offers plain HTTP, a certificate
  this stack serves, or a reverse proxy in front — and the proxy answer now
  writes the loopback bind address and the moved TLS port itself, instead of
  leaving them as a hand edit ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).

### Fixed

- A port equal to the scheme's default no longer reaches `PUBLIC_URL`. The
  resulting `https://host:443/...` did not match an OAuth redirect URI
  registered as `https://host/...`, which providers compare exactly
  ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).
- Answering the public-origin question with a malformed value asks again
  instead of aborting the installer after the clone
  ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).

## [v0.5.1] - 2026-08-02

## What's Changed
* feat(#230,#224): mailless-capable instance + registration-gate toggles by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/231
* fix(#213): stronger reading focus, no scroll lag by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/232
* fix(#212): keep long tag names inside the mobile viewport by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/233
* fix(#212): keep settings/tags rows on one line on mobile by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/234
* Reader extraction fixes and typographic variety by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/236


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.0...v0.5.1
