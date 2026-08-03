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
