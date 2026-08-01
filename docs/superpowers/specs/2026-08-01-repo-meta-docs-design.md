# Repo meta files for a public GitHub repository (issue #67)

**Date:** 2026-08-01
**Issue:** [#67](https://github.com/larspohlmann/simple-feed-reader/issues/67)
**Branch:** `feature/67-repo-meta-docs`

## Goal

Add the standard meta files that a public open-source repository needs. Keep
every new file consistent with the workflow that `CLAUDE.md`,
`docs/local-docker.md`, and `docs/releasing.md` already document.

## Decisions

| Question | Decision |
|---|---|
| License | MIT, "Copyright (c) 2026 Lars Pohlmann" |
| Security reports | GitHub private vulnerability reporting (no public email) |
| Code of conduct | Contributor Covenant 2.1, contact `lars.pohlmann@googlemail.com` |
| Issue templates | Skipped |
| PR template | Skipped |
| README extras | CI badge, screenshots (supplied by Lars), CHANGELOG link (no license badge) |
| Changelog | "Keep a Changelog" format, starts empty from now |
| Contribution stance | Open or claim an issue first, then send a PR |

## Files to add

### LICENSE

Plain MIT text with the copyright line above. No markdown extension, so GitHub
detects the license.

### CONTRIBUTING.md

Documents the real workflow; links to existing docs instead of duplicating
them. Sections:

- **Before you start** — open or claim a GitHub issue first. PRs without an
  issue can be declined.
- **Setup** — pointer to `docs/local-docker.md` for the Docker stack and to
  `README.md` for the one-line installer.
- **Branch and commit conventions** — git-flow: feature branches off
  `develop`, never `main`. Branch names embed the issue number
  (`feature/67-repo-meta-docs`). Commit style `type(#issue): summary`, as in
  the git history.
- **Quality gate** — backend: `composer check` (cs + stan), `composer md`,
  `php bin/phpunit` natively (SQLite) and
  `docker compose exec php vendor/bin/phpunit` (MySQL). Frontend:
  `npm run check`. Every touched `src` file must be PHPMD-clean.
- **PRs** — target `develop`; "Closes #NN" in the body.

### CODE_OF_CONDUCT.md

Contributor Covenant 2.1, unmodified apart from the contact:
`lars.pohlmann@googlemail.com`.

### SECURITY.md

- Report vulnerabilities through GitHub's private "Report a vulnerability"
  form; do not open public issues.
- Names the sensitive surface: authentication (JWT, ALTCHA), OAuth/OIDC
  sign-in, outbound feed fetching (SSRF boundary).
- Supported version: the latest release and `develop`.
- **Manual step:** enable "Private vulnerability reporting" in the repository
  settings (one `gh api` call, needs Lars's OK, or one checkbox in the web
  UI).

### CHANGELOG.md

"Keep a Changelog 1.1" format. Starts with an empty `Unreleased` section;
history before today stays in git. Version sections are **filled
automatically at release time** (see "Release automation" below), so they
hold the auto-generated list of merged pull requests, not hand-curated
Added/Changed/Fixed groups.

## README changes

- CI status badge for `.github/workflows/ci.yml` on `develop`, at the top.
- Screenshots of the reader UI, supplied by Lars in `docs/screenshots/`:
  `desktop-reader.png` (split view with reader pane) as the main image near
  the top, with `desktop-cards.png` (card view) and `mobile.png` (mobile
  view) in a small gallery below it.
- Links to CONTRIBUTING.md, LICENSE, and CHANGELOG.md in the documentation
  section.

## Release automation (added 2026-08-01, after the docs work)

A new workflow turns a `vX.Y.Z` release tag on `main` into a published
GitHub Release and a changelog update. It follows the same house style as
`deploy-strato.yml`: a tag push proves nothing on its own, so two guard
steps supply what the trigger cannot.

### `.github/workflows/release.yml`

- **Trigger:** `push` on tags matching `v[0-9]+.[0-9]+.[0-9]+` — plain
  semver only. The `-dev.N` deploy tags are deliberately unmatched, so the
  two lanes stay separate.
- **Repository guard:** `if: github.repository == 'larspohlmann/simple-feed-reader'`.
- **Permissions:** `contents: write` (create the release, push the changelog
  commit).
- **Guard 1 — commit is on `main`:** refuse unless the tagged SHA is an
  ancestor of `origin/main`. Mirrors deploy's develop-ancestry guard.
- **Guard 2 — CI is green:** refuse unless a completed `push` CI run for the
  tagged SHA concluded `success`. Absence is failure. Mirrors deploy's
  guard. (CI runs on pushes to `main`, so a real release commit has a run.)
- **Previous tag:** the highest plain-semver `vX.Y.Z` tag below the current
  one, found with the same `grep -E` filter `scripts/lib.sh` already uses.
- **First release is special.** When there is no previous `vX.Y.Z` tag, this
  is the first release: publish the GitHub Release with a plain
  `Initial release.` body and do **not** write to `CHANGELOG.md`. Otherwise
  a first release would dump every pull request since the repository start.
- **Notes (later releases):** GitHub's `releases/generate-notes` API produces
  the body from the pull requests merged since the previous `vX.Y.Z` tag.
- **Publish:** create the release for the tag, marked latest, with that body.
- **Changelog (later releases only):** insert a new `## [vX.Y.Z] -
  YYYY-MM-DD` section holding the same notes directly under `## [Unreleased]`,
  leave `Unreleased` empty, and push the change to `main` as
  `github-actions[bot]`. The edit is done by a committed, unit-tested script,
  not inline shell, because it rewrites a file the workflow then commits to
  `main`.

### `scripts/changelog-insert-release.sh`

- Arguments: the version tag and the ISO date; the notes body on stdin.
- Inserts the new version section immediately after the `## [Unreleased]`
  line in `CHANGELOG.md`, leaving `Unreleased` empty.
- Idempotent guard: exits non-zero if a section for that version already
  exists, so a re-run never double-inserts.
- Covered by a golden-file test (`scripts/test/…`) that feeds a fixture
  changelog and diffs the exact expected output. The test is the safety net
  for a script whose output is auto-committed to `main`.

### `docs/releasing.md` rewrite

The current document states there is "no GitHub Release object to create."
That is now false. The rewrite: a `vX.Y.Z` tag on `main` is still the single
action, but it now also publishes a GitHub Release and updates the changelog
automatically. Remove the manual "update the changelog before tagging" step;
the workflow owns it. Keep the load-bearing rule that a release tag is plain
semver with no suffix.

## Out of scope

- Issue templates and a PR template (explicitly skipped).
- A license badge.
- Backfilled changelog history.
- CI changes beyond the new release workflow.

## Acceptance criteria (from #67, adjusted to decisions)

- [ ] LICENSE (MIT) added
- [ ] CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md added
- [ ] CHANGELOG.md added; releasing docs updated
- [ ] README: CI badge, screenshots, meta-file links
- [ ] Private vulnerability reporting enabled on the repo
- [ ] New docs consistent with `docs/local-docker.md` and the real workflow
- [ ] `release.yml`: `vX.Y.Z` tag on `main` publishes a GitHub Release
- [ ] Release workflow auto-updates `CHANGELOG.md` and commits it to `main`
- [ ] `changelog-insert-release.sh` covered by a golden-file test
- [ ] `docs/releasing.md` rewritten for the automated release flow
