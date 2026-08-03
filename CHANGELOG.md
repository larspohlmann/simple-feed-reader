# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version sections below are generated automatically when a release tag is pushed;
see [docs/releasing.md](docs/releasing.md). History before the first release
lives in the git log and the merged pull requests.

## [Unreleased]

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
